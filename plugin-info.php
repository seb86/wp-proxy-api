<?php
require __DIR__ . '/vendor/autoload.php'; // Adjust path if needed

use GuzzleHttp\Client;

// CONFIGURATION
require __DIR__ . '/allowed-plugins.php';
require __DIR__ . '/github-token.php';

// Rate limiting configuration
$cache_dir = __DIR__ . '/cache';
$cache_duration = 300; // 5 minutes in seconds

// Create cache directory if it doesn't exist
if (!is_dir($cache_dir)) {
    mkdir($cache_dir, 0755, true);
}

/**
 * Convert markdown to HTML
 */
function markdown_to_html($markdown) {
    if (empty($markdown)) return '';
    
    // Convert line breaks
    $html = str_replace("\r\n", "\n", $markdown);
    $html = str_replace("\r", "\n", $html);
    
    // Convert headers
    $html = preg_replace('/^### (.*$)/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^## (.*$)/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^# (.*$)/m', '<h1>$1</h1>', $html);
    
    // Convert lists
    $html = preg_replace('/^\* (.*$)/m', '<li>$1</li>', $html);
    $html = preg_replace('/^- (.*$)/m', '<li>$1</li>', $html);
    
    // Wrap consecutive list items in <ul> tags
    $html = preg_replace('/(<li>.*<\/li>)\s*(<li>.*<\/li>)/s', '<ul>$1$2</ul>', $html);
    $html = preg_replace('/<ul>(<li>.*<\/li>)<\/ul>\s*<ul>(<li>.*<\/li>)<\/ul>/s', '<ul>$1$2</ul>', $html);
    
    // Convert bold and italic
    $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $html);
    
    // Convert code blocks
    $html = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $html);
    $html = preg_replace('/`(.*?)`/', '<code>$1</code>', $html);
    
    // Convert links
    $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $html);
    
    // Convert paragraphs
    $html = preg_replace('/^(?!<[h|u|p|d|b|q|t|s|o|a|i|e|r|f|n|m|w|v|u|p|r|e|>])(.*)$/m', '<p>$1</p>', $html);
    
    // Clean up empty paragraphs
    $html = str_replace('<p></p>', '', $html);
    $html = preg_replace('/<p>\s*<\/p>/', '', $html);
    
    return trim($html);
}

header('Content-Type: application/json');

// Get plugin slug from query (?slug=cart-rest-api-for-woocommerce)
$slug = isset($_GET['slug']) ? strtolower(trim($_GET['slug'])) : '';
if (!isset($allowed_plugins[$slug])) {
    echo json_encode(['error' => 'Plugin not found.']);
    exit;
}

$owner = $allowed_plugins[$slug]['owner'];
$repo = $allowed_plugins[$slug]['repo'];
$release_tag = isset($_GET['release']) ? trim($_GET['release']) : null;

// Get channel from query (?channel=stable|beta|rc|nightly|prerelease|all)
$channel = isset($_GET['channel']) ? strtolower(trim($_GET['channel'])) : 'stable';

// Create cache key
$cache_key = md5("$slug|$owner|$repo|" . ($release_tag ?: 'latest') . "|$channel");
$cache_file = "$cache_dir/$cache_key.json";

// Check if we have a valid cached response
if (file_exists($cache_file)) {
    $cache_data = json_decode(file_get_contents($cache_file), true);
    if ($cache_data && (time() - $cache_data['timestamp']) < $cache_duration) {
        echo json_encode($cache_data['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$client = new Client([
    'base_uri' => 'https://api.github.com/',
    'headers' => [
        'User-Agent' => 'WP-Plugin-Info-Script',
        'Accept' => 'application/vnd.github.v3+json',
        ...( $github_token ? ['Authorization' => "token $github_token"] : [] ),
    ]
]);

try {
    // 1. Get all releases (for versions and download count)
    $all_releases = [];
    $page = 1;
    do {
        $releases_resp = $client->get("repos/$owner/$repo/releases?per_page=100&page=$page")->getBody()->getContents();
        $releases_page = json_decode($releases_resp, true);
        if (!is_array($releases_page) || empty($releases_page)) break;
        $all_releases = array_merge($all_releases, $releases_page);
        $page++;
    } while (count($releases_page) === 100);
    $releases = $all_releases;
    if (empty($releases)) {
        echo json_encode(['error' => 'No releases found.']);
        exit;
    }

    // Optionally filter releases from min_release onwards
    $min_release = $allowed_plugins[$slug]['min_release'] ?? null;
    if ($min_release) {
        $min_release_norm = ltrim($min_release, 'v');
        $filtered = [];
        $found = false;
        foreach ($releases as $rel) {
            $tag_norm = ltrim($rel['tag_name'], 'v');
            if (!$found && $tag_norm === $min_release_norm) $found = true;
            if ($found) $filtered[] = $rel;
        }
        if ($found) $releases = $filtered;
    }

    // Filter releases based on channel
    $releases = array_filter($releases, function($rel) use ($channel) {
        $tag = strtolower($rel['tag_name']);
        $name = strtolower($rel['name'] ?? '');
        if ($channel === 'stable') {
            return empty($rel['prerelease']) && empty($rel['draft']);
        }
        if ($channel === 'beta') {
            return $rel['prerelease'] && (strpos($tag, 'beta') !== false || strpos($name, 'beta') !== false);
        }
        if ($channel === 'rc') {
            return $rel['prerelease'] && (strpos($tag, 'rc') !== false || strpos($name, 'rc') !== false);
        }
        if ($channel === 'nightly') {
            return $rel['prerelease'] && (strpos($tag, 'nightly') !== false || strpos($name, 'nightly') !== false);
        }
        if ($channel === 'prerelease') {
            return $rel['prerelease'];
        }
        if ($channel === 'all') {
            return true;
        }
        return empty($rel['prerelease']) && empty($rel['draft']); // fallback to stable
    });
    $releases = array_values($releases); // reindex

    // 2. Get the correct release
    if ($release_tag) {
        $release = $client->get("repos/$owner/$repo/releases/tags/$release_tag")->getBody()->getContents();
        $release = json_decode($release, true);
        if (isset($release['message']) && $release['message'] === 'Not Found') {
            echo json_encode(['error' => 'Release not found.']);
            exit;
        }
    } else {
        // Find the first non-prerelease as the latest
        $release = null;
        foreach ($releases as $rel) {
            if (empty($rel['prerelease'])) {
                $release = $rel;
                break;
            }
        }
        if (!$release) {
            // fallback: if all are prereleases, use the first
            $release = $releases[0];
        }
    }

    // 3. Get package.json from the release tag
    $tag = $release['tag_name'];
    $package = $client->get("repos/$owner/$repo/contents/package.json?ref=$tag")->getBody()->getContents();
    $package = json_decode($package, true);
    $package_json = base64_decode($package['content']);
    $package = json_decode($package_json, true);

    // 4. Author profile
    $repo_info = $client->get("repos/$owner/$repo")->getBody()->getContents();
    $repo_info = json_decode($repo_info, true);
    $author_profile = $repo_info['owner']['html_url'] ?? '';

    // 5. Download count (for current release only)
    $downloaded = 0;
    if (!empty($release['assets'])) {
        foreach ($release['assets'] as $asset) {
            $downloaded += $asset['download_count'];
        }
    }

    // 6. Versions
    $versions = [];
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $host = $_SERVER['HTTP_HOST'];
    $script_path = dirname($_SERVER['SCRIPT_NAME']);
    $base_url = $protocol . "://" . $host . $script_path;

    // Add branch download to versions if specified
    $branch = $allowed_plugins[$slug]['branch_download'] ?? null;
    if ($branch && ('all' === $channel || 'stable' === $channel) ) {
        $versions[$branch] = "$base_url/download/branch/$slug-$branch.zip";
    }

    foreach ($releases as $rel) {
        $ver = $rel['tag_name'];
        $ver_norm = ltrim( $rel['tag_name'], 'v' );
        $versions[$ver_norm] = "$base_url/download/release/$slug-$ver.zip";
    }

    // 8. Build the plugin info JSON
    // Format last_updated as "2025-06-26 6:22pm GMT" in GMT/UTC
    $last_updated_iso = $release['published_at'] ?? date('c');
    $dt = new DateTime($last_updated_iso);
    $dt->setTimezone(new DateTimeZone('GMT'));
    $last_updated = $dt->format('Y-m-d g:ia') . ' GMT';

    $plugin_info = [
        'name' => $package['title'] ?? $package['name'],
        'slug' => $slug,
        'version' => ltrim($release['tag_name'], 'v'),
        'author' => $package['author'] ?? '',
        'author_profile' => $author_profile,
        'requires' => $package['wordpress']['required'] ?? '6.3',
        'tested' => $package['wordpress']['tested'] ?? '6.5',
        'requires_php' => $package['wordpress']['requires_php'] ?? '7.4',
        'downloaded' => $downloaded,
        'last_updated' => $last_updated,
        'homepage' => $package['homepage'] ?? ($package['repository']['url'] ?? ''),
        'sections' => [
            'description' => $package['description'] ?? '',
            'changelog' => markdown_to_html($release['body'] ?? ''),
        ],
        'download_link' => "$base_url/download/release/$slug-" . $release['tag_name'] . ".zip",
        'versions' => $versions,
    ];

    // Cache the response
    $cache_data = [
        'timestamp' => time(),
        'data' => $plugin_info,
    ];
    file_put_contents($cache_file, json_encode($cache_data));

    echo json_encode($plugin_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    echo json_encode(['error' => 'Could not fetch plugin info.']);
    exit;
} 