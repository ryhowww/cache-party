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

        // Output buffer — register processors with the single Output_Buffer.
        $buffer = \CacheParty\Output_Buffer::instance();

        // CSS aggregation (priority 1 — runs before deferral).
        if ( ! empty( $this->settings['css_aggregate_enabled'] ) ) {
            Cache_Manager::cache_available();
            $aggregator = new CSS_Aggregator( $this->settings );
            $buffer->add_processor( 1, [ $aggregator, 'process_buffer' ] );
        }

        if ( $this->settings['css_defer_enabled'] ) {
            $css = new CSS_Deferral( $this->settings );
            $buffer->add_processor( 3, [ $css, 'process_buffer' ] );
        }

        if ( $this->settings['js_delay_enabled'] ) {
            $js = new JS_Delay( $this->settings );
            $buffer->add_processor( 4, [ $js, 'process_buffer' ] );
        }

        if ( $this->settings['js_delay_enabled'] ) {
            // Interaction loader for JS delay.
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_loader' ], 1 );

            // Body class for scroll state tracking.
            add_filter( 'body_class', [ $this, 'add_body_class' ] );
        }

        if ( $this->settings['iframe_lazy_enabled'] ) {
            $iframe = new Iframe_Lazy( $this->settings );
            $buffer->add_processor( 5, [ $iframe, 'process_buffer' ] );
        }

        // Remove WordPress emoji inline CSS, JS, and DNS prefetch.
        if ( ! empty( $this->settings['remove_emojis'] ) ) {
            $this->disable_emojis();
        }

        // Remove block editor frontend CSS and global styles preset variables.
        if ( ! empty( $this->settings['remove_block_styles'] ) ) {
            add_action( 'wp_enqueue_scripts', function() {
                wp_dequeue_style( 'global-styles' );
                wp_dequeue_style( 'wp-block-library' );
            }, 100 );
        }

        // Critical CSS (always active — inlines if files exist).
        new Critical_CSS();

        // Resource hints (always active when module is on).
        new Resource_Hints( $this->settings );

        // Cache cleanup cron.
        Cache_Manager::schedule_cleanup();
        add_action( 'cp_cache_cleanup', [ Cache_Manager::class, 'cleanup' ] );

        // Auto-detect plugin scripts to delay.
        if ( $this->settings['auto_detect_plugins'] ) {
            $this->auto_detect_plugins();
        }

        // Admin AJAX: critical CSS + cache management.
        if ( is_admin() ) {
            add_action( 'wp_ajax_cache_party_generate_critical', [ $this, 'ajax_generate_critical' ] );
            add_action( 'wp_ajax_cache_party_delete_critical', [ $this, 'ajax_delete_critical' ] );
            add_action( 'wp_ajax_cache_party_get_critical', [ $this, 'ajax_get_critical' ] );
            add_action( 'wp_ajax_cache_party_save_critical', [ $this, 'ajax_save_critical' ] );
            add_action( 'wp_ajax_cache_party_dismiss_cf_notice', [ $this, 'ajax_dismiss_cf_notice' ] );
            add_action( 'wp_ajax_cache_party_purge_css_cache', [ $this, 'ajax_purge_css_cache' ] );
        }

        // WP-CLI commands for assets.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            $cli = new CLI_Assets();
            \WP_CLI::add_command( 'cache-party generate-critical', [ $cli, 'generate_critical' ] );
            \WP_CLI::add_command( 'cache-party list-templates', [ $cli, 'list_templates' ] );
            \WP_CLI::add_command( 'cache-party list-critical', [ $cli, 'list_critical' ] );
            \WP_CLI::add_command( 'cache-party delete-critical', [ $cli, 'delete_critical' ] );
        }
    }

    /**
     * AJAX: Purge aggregated CSS cache.
     */
    public function ajax_purge_css_cache() {
        check_ajax_referer( 'cache_party_critical', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }
        Cache_Manager::clearall( 'css' );
        wp_send_json_success( [ 'message' => 'CSS cache purged.' ] );
    }

    /**
     * AJAX: Delete critical CSS for a template.
     */
    public function ajax_delete_critical() {
        check_ajax_referer( 'cache_party_critical', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $template = isset( $_POST['template'] ) ? sanitize_text_field( $_POST['template'] ) : '';
        if ( ! $template ) {
            wp_send_json_error( [ 'message' => 'Template is required.' ] );
        }

        Critical_CSS::delete_critical_css( $template );

        // Remove from metadata.
        $all_meta = get_option( 'cache_party_critical_meta', [] );
        unset( $all_meta[ $template ] );
        update_option( 'cache_party_critical_meta', $all_meta, false );

        wp_send_json_success( [ 'message' => 'Deleted.' ] );
    }

    /**
     * AJAX: Get critical CSS content for editing.
     */
    public function ajax_get_critical() {
        check_ajax_referer( 'cache_party_critical', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $template = isset( $_POST['template'] ) ? sanitize_text_field( $_POST['template'] ) : '';
        if ( ! $template ) {
            wp_send_json_error( [ 'message' => 'Template is required.' ] );
        }

        $css_obj = new Critical_CSS();
        $css = $css_obj->get_critical_css( $template );

        if ( false === $css ) {
            wp_send_json_error( [ 'message' => 'No critical CSS found for this template.' ] );
        }

        wp_send_json_success( [ 'css' => $css ] );
    }

    /**
     * AJAX: Save edited critical CSS.
     */
    public function ajax_save_critical() {
        check_ajax_referer( 'cache_party_critical', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $template = isset( $_POST['template'] ) ? sanitize_text_field( $_POST['template'] ) : '';
        // Use wp_unslash to preserve CSS content exactly as submitted.
        $css      = isset( $_POST['css'] ) ? wp_unslash( $_POST['css'] ) : '';

        if ( ! $template ) {
            wp_send_json_error( [ 'message' => 'Template is required.' ] );
        }

        $saved = Critical_CSS::save_critical_css( $template, $css, [
            'source' => 'manual_edit',
        ] );

        if ( ! $saved ) {
            wp_send_json_error( [ 'message' => 'Failed to save CSS file.' ] );
        }

        wp_send_json_success( [
            'message' => 'Saved!',
            'size'    => strlen( $css ),
        ] );
    }

    /**
     * AJAX: Dismiss Cloudflare critical CSS notice.
     */
    public function ajax_dismiss_cf_notice() {
        check_ajax_referer( 'cache_party_critical', 'nonce' );
        update_user_meta( get_current_user_id(), 'cp_dismiss_cf_critical_notice', 1 );
        wp_send_json_success();
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
     * Remove WordPress core emoji inline CSS, inline JS, and DNS prefetch.
     * Transplanted from autoptimizeExtra::disable_emojis().
     */
    private function disable_emojis() {
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
        remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
        add_filter( 'tiny_mce_plugins', function ( $plugins ) {
            return is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : [];
        } );
        add_filter( 'wp_resource_hints', function ( $urls, $relation_type ) {
            if ( 'dns-prefetch' === $relation_type ) {
                $urls = array_filter( $urls, function ( $url ) {
                    return ( is_string( $url ) && false === strpos( $url, 'svn.wp' ) );
                } );
            }
            return $urls;
        }, 10, 2 );
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

    /**
     * Get the configured critical CSS viewport dimensions.
     *
     * @return array Array of { width, height } arrays.
     */
    public static function get_critical_dimensions() {
        $defaults = [
            [ 'width' => 412, 'height' => 896 ],
            [ 'width' => 900, 'height' => 1024 ],
            [ 'width' => 1300, 'height' => 900 ],
        ];

        $saved = get_option( 'cache_party_critical_dimensions', [] );
        return ! empty( $saved ) ? $saved : $defaults;
    }

    /**
     * AJAX: Generate critical CSS for a template (multi-viewport).
     */
    public function ajax_generate_critical() {
        check_ajax_referer( 'cache_party_critical', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $template = isset( $_POST['template'] ) ? sanitize_text_field( $_POST['template'] ) : '';
        $url      = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';

        if ( ! $template || ! $url ) {
            wp_send_json_error( [ 'message' => 'Template and URL are required.' ] );
        }

        $api_url = \CacheParty\Warmer\Warmer_Client::API_URL;
        $api_key = get_option( 'cache_party_api_key', '' );

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => 'API key not configured. Add it on the General tab.' ] );
        }

        $endpoint   = rtrim( $api_url, '/' ) . '/api/critical-css';
        $dimensions = self::get_critical_dimensions();

        $response = wp_remote_post( $endpoint, [
            'timeout' => 120,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'url'        => $url,
                'template'   => $template,
                'dimensions' => $dimensions,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || empty( $body['css'] ) ) {
            $err = $body['error'] ?? "HTTP {$code}";
            wp_send_json_error( [ 'message' => 'Generation failed: ' . $err ] );
        }

        $meta = [
            'dimensions'  => $body['dimensions'] ?? [],
            'source_url'  => $url,
        ];

        $saved = Critical_CSS::save_critical_css( $template, $body['css'], $meta );
        if ( ! $saved ) {
            wp_send_json_error( [ 'message' => 'Failed to save CSS file.' ] );
        }

        wp_send_json_success( [
            'message'    => 'Generated! ' . size_format( strlen( $body['css'] ) ),
            'template'   => $template,
            'size'       => strlen( $body['css'] ),
            'dimensions' => $body['dimensions'] ?? [],
        ] );
    }
}
