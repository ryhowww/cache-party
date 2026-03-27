<?php
/**
 * Plugin Name:       Cache Party
 * Plugin URI:        https://completeseo.com
 * Description:       Unified WordPress performance plugin — WebP conversion, smart lazy loading, CSS/JS optimization, critical CSS, and cache warmer integration.
 * Version:           1.2.17
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ryan Howard
 * Author URI:        https://completeseo.com/author/ryan-howard/
 * Text Domain:       cache-party
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CACHE_PARTY_VERSION', '1.2.17' );
define( 'CACHE_PARTY_PATH', plugin_dir_path( __FILE__ ) );
define( 'CACHE_PARTY_URL', plugin_dir_url( __FILE__ ) );
define( 'CACHE_PARTY_FILE', __FILE__ );

// Plugin Update Checker — checks GitHub for new releases.
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

$cachePartyUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/ryhowww/cache-party/',
    __FILE__,
    'cache-party'
);

// Use GitHub releases (tagged versions), download the release asset ZIP.
$cachePartyUpdateChecker->getVcsApi()->enableReleaseAssets();

// Private repo: read token from wp-config.php constant if defined.
if ( defined( 'CACHE_PARTY_GITHUB_TOKEN' ) ) {
    $cachePartyUpdateChecker->setAuthentication( CACHE_PARTY_GITHUB_TOKEN );
}

// Force auto-updates on managed sites.
add_filter( 'auto_update_plugin', function( $update, $item ) {
    if ( isset( $item->slug ) && $item->slug === 'cache-party' ) {
        return true;
    }
    return $update;
}, 10, 2 );

require_once __DIR__ . '/includes/class-autoloader.php';

\CacheParty\Plugin::init();
register_uninstall_hook( __FILE__, [ '\CacheParty\Plugin', 'uninstall' ] );

// Deactivation: deregister site from warmer (non-blocking).
register_deactivation_hook( __FILE__, function() {
    $warmer = new \CacheParty\Warmer\Warmer_Client();
    $warmer->remove_site();
} );

/**
 * Theme helper: output an <img> or <picture> tag with WebP support.
 *
 * @param string|int $source Image URL, relative uploads path, or attachment ID.
 * @param array      $attrs  Optional HTML attributes (class, alt, width, height, loading, etc.).
 * @return string HTML output.
 */
function cache_party_img( $source, $attrs = [] ) {
    if ( is_numeric( $source ) ) {
        $url = wp_get_attachment_url( (int) $source );
        if ( ! $url ) {
            return '';
        }
        $source = $url;
    }

    if ( strpos( $source, '/' ) !== false && strpos( $source, '://' ) === false ) {
        $upload_dir = wp_get_upload_dir();
        $source     = $upload_dir['baseurl'] . '/' . ltrim( $source, '/' );
    }

    return \CacheParty\Images\Picture_Wrapper::build_picture_tag( $source, $attrs );
}
