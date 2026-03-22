<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Debug overlay: ?perf-debug=1
 *
 * Shows a fixed panel with applied optimizations when an admin
 * visits the front end with the debug query parameter.
 */
class Debug {

    public function __construct() {
        if ( ! $this->should_show() ) {
            return;
        }

        add_action( 'wp_footer', [ $this, 'render_overlay' ], 9999 );
    }

    private function should_show() {
        if ( ! isset( $_GET['perf-debug'] ) || $_GET['perf-debug'] !== '1' ) {
            return false;
        }

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return true;
    }

    public function render_overlay() {
        $modules  = get_option( 'cache_party_modules', [ 'images' ] );
        $images   = wp_parse_args( get_option( 'cache_party_images', [] ), Settings::image_defaults() );
        $assets   = wp_parse_args( get_option( 'cache_party_assets', [] ), Settings::asset_defaults() );

        // WebP engine.
        $webp_engine = 'Not available';
        if ( class_exists( '\CacheParty\Images\WebP_Converter' ) ) {
            $engine = \CacheParty\Images\WebP_Converter::is_conversion_available();
            $webp_engine = $engine ? ucfirst( $engine ) : 'Not available';
        }

        // WebP stats.
        $webp_stats = [ 'total' => 0, 'converted' => 0, 'unconverted' => 0 ];
        if ( class_exists( '\CacheParty\Images\WebP_Converter' ) ) {
            $webp_stats = \CacheParty\Images\WebP_Converter::get_bulk_stats();
        }

        // Critical CSS template.
        $critical_template = 'none';
        if ( class_exists( '\CacheParty\Assets\Critical_CSS' ) ) {
            $cc = new \CacheParty\Assets\Critical_CSS();
            $detected = $cc->detect_template();
            $has_css  = $cc->has_critical_css( $detected );
            $critical_template = $detected . ( $has_css ? ' (active)' : ' (no file)' );
        }

        // Warmer status.
        $warmer_settings = get_option( 'cache_party_warmer', [] );
        $warmer_url      = $warmer_settings['api_url'] ?? '';
        $warmer_status   = $warmer_url ? 'Configured' : 'Not configured';

        // Autoptimize.
        $ao_active = defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ? AUTOPTIMIZE_PLUGIN_VERSION : 'Not active';

        ?>
        <div id="cp-debug-overlay" style="
            position: fixed; bottom: 0; right: 0; z-index: 999999;
            background: rgba(0,0,0,0.92); color: #e0e0e0;
            font-family: 'SF Mono', 'Consolas', monospace; font-size: 12px; line-height: 1.6;
            padding: 16px 20px; max-width: 420px; max-height: 80vh; overflow-y: auto;
            border-top-left-radius: 8px; box-shadow: -2px -2px 12px rgba(0,0,0,0.3);
        ">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <strong style="color:#fff;font-size:13px;">Cache Party Debug</strong>
                <span style="cursor:pointer;color:#888;font-size:16px;" onclick="this.parentElement.parentElement.remove()">&#x2715;</span>
            </div>

            <div style="border-bottom:1px solid #333;padding-bottom:8px;margin-bottom:8px;">
                <strong style="color:#0073aa;">Modules</strong><br>
                <?php foreach ( [ 'images', 'assets', 'warmer' ] as $m ) : ?>
                    <?php $on = in_array( $m, $modules, true ); ?>
                    <span style="color:<?php echo $on ? '#46b450' : '#dc3232'; ?>;">
                        <?php echo $on ? '&#10003;' : '&#10007;'; ?>
                    </span>
                    <?php echo esc_html( ucfirst( $m ) ); ?><br>
                <?php endforeach; ?>
            </div>

            <?php if ( in_array( 'images', $modules, true ) ) : ?>
            <div style="border-bottom:1px solid #333;padding-bottom:8px;margin-bottom:8px;">
                <strong style="color:#0073aa;">Images</strong><br>
                WebP engine: <?php echo esc_html( $webp_engine ); ?><br>
                WebP: <?php echo esc_html( $webp_stats['converted'] ); ?>/<?php echo esc_html( $webp_stats['total'] ); ?> converted<br>
                Picture wrapping: <?php echo $images['picture_enabled'] ? 'On' : 'Off'; ?><br>
                Lazy loading: <?php echo $images['lazy_enabled'] ? 'On' : 'Off'; ?>
                (eager: <?php echo esc_html( $images['eager_count'] ); ?>)<br>
                Auto alt: <?php echo $images['auto_alt_enabled'] ? 'On' : 'Off'; ?>
            </div>
            <?php endif; ?>

            <?php if ( in_array( 'assets', $modules, true ) ) : ?>
            <div style="border-bottom:1px solid #333;padding-bottom:8px;margin-bottom:8px;">
                <strong style="color:#0073aa;">Assets</strong><br>
                CSS deferral: <?php echo $assets['css_defer_enabled'] ? 'On' : 'Off'; ?><br>
                JS delay: <?php echo $assets['js_delay_enabled'] ? 'On' : 'Off'; ?><br>
                Iframe lazy: <?php echo $assets['iframe_lazy_enabled'] ? 'On' : 'Off'; ?><br>
                Idle timeout: <?php echo esc_html( $assets['idle_timeout'] ); ?>s<br>
                Critical CSS: <?php echo esc_html( $critical_template ); ?><br>
                Autoptimize: <?php echo esc_html( $ao_active ); ?><br>
                Preload mode: <?php echo $assets['preload_css_http'] ? 'HTTP headers' : 'Link tags'; ?>
            </div>
            <?php endif; ?>

            <?php if ( in_array( 'warmer', $modules, true ) ) : ?>
            <div style="border-bottom:1px solid #333;padding-bottom:8px;margin-bottom:8px;">
                <strong style="color:#0073aa;">Warmer</strong><br>
                Status: <?php echo esc_html( $warmer_status ); ?>
            </div>
            <?php endif; ?>

            <div style="color:#888;font-size:11px;">
                v<?php echo esc_html( CACHE_PARTY_VERSION ); ?> &middot;
                PHP <?php echo esc_html( PHP_VERSION ); ?> &middot;
                WP <?php echo esc_html( get_bloginfo( 'version' ) ); ?>
            </div>
        </div>
        <?php
    }
}
