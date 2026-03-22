<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

class Asset_Optimizer {

    private $settings;

    public function __construct() {
        $this->settings = wp_parse_args(
            get_option( 'cache_party_assets', [] ),
            \CacheParty\Settings::asset_defaults()
        );

        // Output buffer — the core pipeline.
        if ( $this->settings['css_defer_enabled'] || $this->settings['js_delay_enabled'] ) {
            new CSS_Deferral( $this->settings );
            new JS_Delay( $this->settings );

            // Enqueue interaction loader.
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_loader' ], 1 );

            // Body class for scroll state tracking.
            add_filter( 'body_class', [ $this, 'add_body_class' ] );
        }

        if ( $this->settings['iframe_lazy_enabled'] ) {
            new Iframe_Lazy( $this->settings );
        }

        // Critical CSS (always active — inlines if files exist).
        new Critical_CSS();

        // Resource hints (always active when module is on).
        new Resource_Hints( $this->settings );

        // Autoptimize bridge (only if AO is active).
        if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {
            new AO_Bridge( $this->settings );
        }

        // Auto-detect plugin scripts to delay.
        if ( $this->settings['auto_detect_plugins'] ) {
            $this->auto_detect_plugins();
        }

        // WP-CLI commands for assets.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $cli = new CLI_Assets();
            \WP_CLI::add_command( 'cache-party generate-critical', [ $cli, 'generate_critical' ] );
            \WP_CLI::add_command( 'cache-party list-critical', [ $cli, 'list_critical' ] );
            \WP_CLI::add_command( 'cache-party delete-critical', [ $cli, 'delete_critical' ] );
        }
    }

    public function enqueue_loader() {
        $idle = (int) $this->settings['idle_timeout'];

        wp_enqueue_script(
            'cache-party-loader',
            CACHE_PARTY_URL . 'includes/assets/js/interaction-loader.js',
            [],
            filemtime( CACHE_PARTY_PATH . 'includes/assets/js/interaction-loader.js' ),
            true
        );

        // Add data-cp-skip (so AO and our JS delay don't touch it) and
        // data-idle-timeout (so the JS can read config without wp_localize_script).
        add_filter( 'script_loader_tag', function( $tag, $handle ) use ( $idle ) {
            if ( $handle === 'cache-party-loader' ) {
                return str_replace( '<script ', '<script data-cp-skip data-idle-timeout="' . $idle . '" ', $tag );
            }
            return $tag;
        }, 10, 2 );
    }

    public function add_body_class( $classes ) {
        $classes[] = 'cp-not-scrolled';
        return $classes;
    }

    /**
     * Auto-detect known plugins and add their scripts to delay lists.
     */
    private function auto_detect_plugins() {
        $active_plugins = get_option( 'active_plugins', [] );

        // PixelYourSite
        foreach ( $active_plugins as $plugin ) {
            if ( strpos( $plugin, 'pixelyoursite' ) === 0 ) {
                add_filter( 'cp_delay_script_tag_atts_kw', function( $kw ) {
                    return array_merge( $kw, [ 'plugins/pixelyoursite', 'pys-js-extra' ] );
                } );
                add_filter( 'cp_delay_script_code_kw', function( $kw ) {
                    return array_merge( $kw, [ 'window.pys', 'var pys' ] );
                } );
                break;
            }
        }

        // Google Tag Manager (Duracelltomi)
        foreach ( $active_plugins as $plugin ) {
            if ( strpos( $plugin, 'duracelltomi-google-tag-manager' ) === 0 ) {
                add_filter( 'cp_delay_script_tag_atts_kw', function( $kw ) {
                    return array_merge( $kw, [ 'plugins/duracelltomi-google-tag-manager' ] );
                } );
                break;
            }
        }
    }
}
