# WordPress Plugin Info and Download API Proxy

This standalone PHP solution provides a WordPress-style plugin info API in JSON, using data fetched from GitHub releases and `package.json` file. It is ideal for custom plugin update APIs or integration with WordPress plugin management tools.

## Features

- Fetches plugin info from GitHub releases and `package.json`
- Supports multiple plugin slugs (multiple repos)
- Allows specifying a release tag (returns info for that release)
- Supports release channels (stable, beta, rc, nightly, prerelease, all)
- Returns error if plugin slug is not found
- Includes author profile, total download count, and all available versions
- Provides download endpoints for both release and branch zips
- Caches API responses for performance (5 min default)

## Requirements

- PHP 7.2+
- Composer
- [Guzzle HTTP client](https://github.com/guzzle/guzzle)
- Access to the GitHub API (optionally with a personal access token for private repos or higher rate limits)

## Setup

1. **Clone or copy this script into your desired directory.**

2. **Install Composer dependencies (Guzzle):**
   ```bash
   composer require guzzlehttp/guzzle
   ```

3. **Configure allowed plugins:**
   - Open `allowed-plugins.php`.
   - Edit the `$allowed_plugins` array to include your plugin slugs and GitHub repo info. You can also set optional keys:
     - `branch_download`: (string) Allow a branch to be downloaded as a zip (e.g. 'trunk')
     - `min_release`: (string) Only fetch releases from this tag onwards (e.g. 'v5.0.0')
     ```php
     $allowed_plugins = [
         'cart-rest-api-for-woocommerce' => [
             'owner' => 'co-cart',
             'repo' => 'co-cart',
             'branch_download' => 'trunk', // optional
             'min_release' => 'v5.0.0', // optional
         ],
         // Add more plugins as needed
     ];
     ```

4. **(Optional) Configure GitHub token:**
   - Open `github-token.php`.
   - Set your GitHub personal access token in the `$github_token` variable for private repos or higher rate limits:
     ```php
     $github_token = 'YOUR_GITHUB_TOKEN';
     ```

5. **Deploy the script to your server or local environment.**

6. **Configure web server rewrite rules:**
   - Use the provided `.htaccess` to rewrite pretty endpoints to the correct PHP scripts.
   - Example rules for Apache (see `.htaccess`):
     ```apache
     # /plugin-info/{slug}.json → plugin-info.php?slug={slug}
     RewriteRule ^plugin-info/(.+)\.json$ plugin-info.php?slug=$1 [L,QSA]
     # /download/release/{slug}-{version}.zip → download.php
     RewriteRule ^download/release/(.+)-(.+)\.zip$ download.php [L,QSA]
     # /download/branch/{slug}-{branch}.zip → download.php
     RewriteRule ^download/branch/(.+)-(.+)\.zip$ download.php [L,QSA]
     ```

## Endpoints & Usage

### Get Latest Release Info

```
GET /plugin-info/{slug}.json
```
Example:
```
GET /plugin-info/cart-rest-api-for-woocommerce.json
```

### Get Specific Release Info

```
GET /plugin-info/{slug}.json?release={tag}
```
Example:
```
GET /plugin-info/cart-rest-api-for-woocommerce.json?release=v4.6.0
```

### Get Info for a Specific Channel

```
GET /plugin-info/{slug}.json?channel=beta
```
- Supported channels: `stable` (default), `beta`, `rc`, `nightly`, `prerelease`, `all`

### Download a Release ZIP

```
GET /download/release/{slug}-{version}.zip
```
Example:
```
GET /download/release/cart-rest-api-for-woocommerce-4.6.0.zip
```
- Returns the ZIP asset for the specified release (tries both v-prefixed and non-prefixed tags)

### Download a Branch ZIP (if enabled)

```
GET /download/branch/{slug}-{branch}.zip
```
Example:
```
GET /download/branch/cart-rest-api-for-woocommerce-trunk.zip
```
- Returns a ZIP of the specified branch, with the folder renamed to match the plugin slug

## Example JSON Response

```json
{
  "name": "cart-rest-api-for-woocommerce",
  "slug": "cart-rest-api-for-woocommerce",
  "version": "4.6.0",
  "author": "CoCart",
  "author_profile": "https://github.com/co-cart",
  "downloaded": 12345,
  "requires": "6.3",
  "tested": "6.5",
  "requires_php": "7.4",
  "last_updated": "2024-06-01T12:00:00Z",
  "homepage": "https://github.com/co-cart/co-cart",
  "sections": {
    "description": "A REST API for WooCommerce.",
    "changelog": "<h2>Changelog</h2><ul><li>Added new endpoints</li><li>Fixed bugs</li></ul>"
  },
  "download_link": "https://yourdomain.com/download/release/cart-rest-api-for-woocommerce-4.6.0.zip",
  "versions": {
    "trunk": "https://yourdomain.com/download/branch/cart-rest-api-for-woocommerce-trunk.zip",
    "4.6.0": "https://yourdomain.com/download/release/cart-rest-api-for-woocommerce-4.6.0.zip"
    // ...
  }
}
```

### Error Example

```
GET /plugin-info/not-a-real-plugin.json
```
Response:
```json
{"error":"Plugin not found."}
```

## Notes

- For private repositories or higher API rate limits, set a GitHub personal access token in `$github_token`.
- The script fetches and decodes `package.json` from the specified release tag.
- Download counts and version info are aggregated from all GitHub releases.
- The API response is cached for 5 minutes by default for performance.
- Extend the `$allowed_plugins` array to support more plugins.
- Download endpoints are protected by allowed plugin slugs.
- Error responses are returned as JSON with an `error` key.
- All endpoints require the rewrite rules to be active (see `.htaccess`).

## License

MIT
