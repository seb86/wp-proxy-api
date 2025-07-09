<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/allowed-plugins.php';
require __DIR__ . '/github-token.php';

use GuzzleHttp\Client;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

// Parse the request URI for /download/release/{slug}-{ref}.zip or /download/branch/{slug}-{ref}.zip
$uri = $_SERVER['REQUEST_URI'];
$matches = [];
if (!preg_match('#/download/(release|branch)/(.+)-(.+)\.zip$#', $uri, $matches)) {
    http_response_code(400);
    echo 'Invalid download URL.';
    exit;
}
$type = $matches[1];
$slug = $matches[2];
$ref = $matches[3];

if (!isset($allowed_plugins[$slug])) {
    http_response_code(404);
    echo 'Plugin not found.';
    exit;
}
$owner = $allowed_plugins[$slug]['owner'];
$repo = $allowed_plugins[$slug]['repo'];

$client = new Client([
    'base_uri' => 'https://api.github.com/',
    'headers' => [
        'User-Agent' => 'WP-Plugin-Info-Script',
        'Accept' => 'application/vnd.github.v3+json',
        ...( $github_token ? ['Authorization' => "token $github_token"] : [] ),
    ]
]);

try {
    if ($type === 'branch') {
        $zip_url = "https://github.com/$owner/$repo/archive/refs/heads/$ref.zip";
        $desired_folder = $slug;

        // 1. Download ZIP to temp file
        $tmp_zip = tempnam(sys_get_temp_dir(), 'ghzip_');
        $zip_resp = $client->request('GET', $zip_url, [
            'sink' => $tmp_zip,
            'allow_redirects' => true,
        ]);

        // Check if the ZIP file was downloaded and is not empty
        if (!file_exists($tmp_zip) || filesize($tmp_zip) === 0) {
            http_response_code(500);
            echo 'Failed to download ZIP from GitHub.';
            unlink($tmp_zip);
            exit;
        }

        // 2. Extract ZIP to temp dir
        $tmp_dir = sys_get_temp_dir() . '/' . uniqid('ghzip_', true);
        mkdir($tmp_dir);
        // Extra check before opening ZIP to avoid deprecated warning
        if (!file_exists($tmp_zip) || filesize($tmp_zip) === 0) {
            http_response_code(500);
            echo 'Downloaded ZIP is empty or missing.';
            unlink($tmp_zip);
            exit;
        }
        $zip = new ZipArchive;
        if ($zip->open($tmp_zip) === TRUE) {
            $zip->extractTo($tmp_dir);
            $zip->close();
        } else {
            http_response_code(500);
            echo 'Failed to extract ZIP.';
            unlink($tmp_zip);
            exit;
        }

        // 3. Find and rename the extracted folder
        $extracted_folders = glob($tmp_dir . '/*', GLOB_ONLYDIR);
        if (count($extracted_folders) !== 1) {
            http_response_code(500);
            echo 'Unexpected ZIP structure.';
            unlink($tmp_zip);
            array_map('unlink', glob("$tmp_dir/*"));
            rmdir($tmp_dir);
            exit;
        }
        $old_folder = $extracted_folders[0];
        $new_folder = $tmp_dir . '/' . $desired_folder;
        rename($old_folder, $new_folder);

        // 4. Create new ZIP with desired folder name
        $new_zip_path = tempnam(sys_get_temp_dir(), 'ghzip_new_');
        // Workaround for PHP deprecation: remove empty file before open
        if (file_exists($new_zip_path)) unlink($new_zip_path);
        $new_zip = new ZipArchive;
        if ($new_zip->open($new_zip_path, ZipArchive::CREATE) === TRUE) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($new_folder, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($files as $file) {
                $localname = $desired_folder . '/' . substr($file, strlen($new_folder) + 1);
                if ($file->isDir()) {
                    $new_zip->addEmptyDir($localname);
                } else {
                    $new_zip->addFile($file, $localname);
                }
            }
            $new_zip->close();
        } else {
            http_response_code(500);
            echo 'Failed to create new ZIP.';
            // Cleanup
            unlink($tmp_zip);
            array_map('unlink', glob("$tmp_dir/*"));
            rmdir($tmp_dir);
            exit;
        }

        // 5. Stream new ZIP to user
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . $ref . '.zip"');
        header('Content-Length: ' . filesize($new_zip_path));
        readfile($new_zip_path);

        // 6. Cleanup
        unlink($tmp_zip);
        // Remove all files and folders recursively
        function rrmdir($dir) {
            if (is_dir($dir)) {
                $objects = scandir($dir);
                foreach ($objects as $object) {
                    if ($object != "." && $object != "..") {
                        $path = $dir . "/" . $object;
                        if (is_dir($path)) rrmdir($path);
                        else unlink($path);
                    }
                }
                rmdir($dir);
            }
        }
        rrmdir($new_folder);
        rrmdir($tmp_dir);
        unlink($new_zip_path);
        exit;
    }
    // For releases, try both v-prefixed and non-prefixed tags
    $possible_tags = [$ref, 'v' . ltrim($ref, 'v')];
    $release = null;
    foreach ($possible_tags as $tag) {
        try {
            $resp = $client->get("repos/$owner/$repo/releases/tags/$tag");
            $release = json_decode($resp->getBody()->getContents(), true);
            if (!isset($release['message']) || $release['message'] !== 'Not Found') break;
        } catch (Exception $e) {
            // Try next
        }
    }
    if (!$release || (isset($release['message']) && $release['message'] === 'Not Found')) {
        http_response_code(404);
        echo 'Release not found.';
        exit;
    }
    // Find asset by name (construct expected filename)
    $expected_asset = "$slug-$ref.zip";
    $asset = null;
    foreach ($release['assets'] as $a) {
        if ($a['name'] === $expected_asset) {
            $asset = $a;
            break;
        }
    }
    // Fallback to first asset if expected asset not found
    if (!$asset && !empty($release['assets'])) {
        $asset = $release['assets'][0];
    }
    if (!$asset) {
        http_response_code(404);
        echo 'No assets found for this release.';
        exit;
    }
    // Download asset (GitHub requires Accept header for binary)
    $asset_url = $asset['url'];
    $asset_resp = $client->get($asset_url, [
        'headers' => [
            'Accept' => 'application/octet-stream',
            ...( $github_token ? ['Authorization' => "token $github_token"] : [] ),
        ],
        'stream' => true,
    ]);
    // Stream to client
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $asset['name'] . '"');
    header('Content-Length: ' . $asset_resp->getHeaderLine('Content-Length'));
    $body = $asset_resp->getBody();
    while (!$body->eof()) {
        echo $body->read(8192);
        flush();
    }
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error downloading asset.';
    exit;
} 