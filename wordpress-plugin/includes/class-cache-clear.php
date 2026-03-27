<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cache Party admin bar dropdown menu.
 *
 * Branded "Cache Party" menu with individual cache clear options:
 * - Clear All Caches (CP + WP Engine + Cloudflare)
 * - Clear Cache Party (CSS/JS aggregation only)
 * - Clear WP Engine (Varnish + memcached) — conditional
 * - Clear Cloudflare — conditional
 */
class Cache_Clear {

    public function __construct() {
        add_action( 'admin_bar_menu', [ $this, 'admin_bar_menu' ], 90 );
        add_action( 'wp_ajax_cache_party_clear_all', [ $this, 'ajax_clear_all' ] );
        add_action( 'wp_ajax_cache_party_clear_cp', [ $this, 'ajax_clear_cp' ] );
        add_action( 'wp_ajax_cache_party_clear_wpe', [ $this, 'ajax_clear_wpe' ] );
        add_action( 'wp_ajax_cache_party_clear_cf', [ $this, 'ajax_clear_cf' ] );
        add_action( 'admin_footer', [ $this, 'inline_assets' ] );
        add_action( 'wp_footer', [ $this, 'inline_assets' ] );
    }

    public function admin_bar_menu( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Parent node.
        $wp_admin_bar->add_node( [
            'id'    => 'cache-party',
            'title' => '<span class="ab-icon dashicons dashicons-performance" style="font-family:dashicons;font-size:16px;line-height:1;margin-right:4px;position:relative;top:3px;"></span>Cache Party',
            'href'  => admin_url( 'admin.php?page=cache-party' ),
        ] );

        // Clear All Caches.
        $wp_admin_bar->add_node( [
            'id'     => 'cache-party-clear-all',
            'parent' => 'cache-party',
            'title'  => 'Clear All Caches',
            'href'   => '#',
            'meta'   => [ 'onclick' => 'cpClear("all"); return false;' ],
        ] );

        // Clear Cache Party only.
        $wp_admin_bar->add_node( [
            'id'     => 'cache-party-clear-cp',
            'parent' => 'cache-party',
            'title'  => 'Clear Cache Party',
            'href'   => '#',
            'meta'   => [ 'onclick' => 'cpClear("cp"); return false;' ],
        ] );

        // Clear WP Engine — only on WPE sites.
        if ( class_exists( 'WpeCommon' ) ) {
            $wp_admin_bar->add_node( [
                'id'     => 'cache-party-clear-wpe',
                'parent' => 'cache-party',
                'title'  => 'Clear WP Engine',
                'href'   => '#',
                'meta'   => [ 'onclick' => 'cpClear("wpe"); return false;' ],
            ] );
        }

        // Clear Cloudflare — only when configured.
        if ( $this->has_cloudflare() ) {
            $wp_admin_bar->add_node( [
                'id'     => 'cache-party-clear-cf',
                'parent' => 'cache-party',
                'title'  => 'Clear Cloudflare',
                'href'   => '#',
                'meta'   => [ 'onclick' => 'cpClear("cf"); return false;' ],
            ] );
        }
    }

    public function inline_assets() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <style>
        #wp-admin-bar-cache-party > .ab-item { cursor: pointer; }
        #wp-admin-bar-cache-party .ab-submenu .ab-item { cursor: pointer !important; }
        #wp-admin-bar-cache-party .ab-submenu .ab-item:hover { color: #00b9eb !important; }
        #wp-admin-bar-cache-party .cp-clearing .ab-item { opacity: .6; pointer-events: none; }
        </style>
        <script>
        function cpClear(target) {
            var node = document.getElementById('wp-admin-bar-cache-party-clear-' + target);
            if (!node) return;
            var link = node.querySelector('.ab-item');
            var original = link.textContent;
            link.textContent = 'Clearing\u2026';
            node.classList.add('cp-clearing');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                var msg = 'Failed';
                try { var r = JSON.parse(xhr.responseText); msg = r.data ? r.data.message : msg; } catch(e) {}
                link.textContent = msg;
                node.classList.remove('cp-clearing');
                setTimeout(function() { link.textContent = original; }, 3000);
            };
            xhr.onerror = function() {
                link.textContent = 'Error';
                node.classList.remove('cp-clearing');
                setTimeout(function() { link.textContent = original; }, 3000);
            };
            xhr.send('action=cache_party_clear_' + target + '&nonce=<?php echo esc_js( wp_create_nonce( 'cache_party_clear' ) ); ?>');
        }
        </script>
        <?php
    }

    // ── AJAX Handlers ──────────────────────────────────────────────────

    public function ajax_clear_all() {
        check_ajax_referer( 'cache_party_clear', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $cleared = [];

        // CP aggregation cache.
        if ( class_exists( '\CacheParty\Assets\Cache_Manager' ) ) {
            Assets\Cache_Manager::clearall( 'css' );
            $cleared[] = 'CP';
        }

        // WP Engine.
        if ( class_exists( 'WpeCommon' ) ) {
            if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
                \WpeCommon::purge_memcached();
            }
            if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
                \WpeCommon::purge_varnish_cache();
            }
            $cleared[] = 'WPE';
        }

        // Cloudflare.
        $cf_result = $this->purge_cloudflare();
        if ( $cf_result === true ) {
            $cleared[] = 'CF';
        } elseif ( $cf_result === false ) {
            $cleared[] = 'CF (failed)';
        }

        do_action( 'cp_caches_cleared' );

        wp_send_json_success( [
            'message' => empty( $cleared ) ? 'No caches found' : 'Cleared: ' . implode( ' + ', $cleared ),
        ] );
    }

    public function ajax_clear_cp() {
        check_ajax_referer( 'cache_party_clear', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        if ( class_exists( '\CacheParty\Assets\Cache_Manager' ) ) {
            Assets\Cache_Manager::clearall( 'css' );
        }

        wp_send_json_success( [ 'message' => 'CP cache cleared' ] );
    }

    public function ajax_clear_wpe() {
        check_ajax_referer( 'cache_party_clear', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        if ( ! class_exists( 'WpeCommon' ) ) {
            wp_send_json_error( [ 'message' => 'Not a WP Engine site.' ] );
        }

        if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
            \WpeCommon::purge_memcached();
        }
        if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
            \WpeCommon::purge_varnish_cache();
        }

        wp_send_json_success( [ 'message' => 'WPE cache cleared' ] );
    }

    public function ajax_clear_cf() {
        check_ajax_referer( 'cache_party_clear', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $result = $this->purge_cloudflare();
        if ( $result === true ) {
            wp_send_json_success( [ 'message' => 'Cloudflare cleared' ] );
        } elseif ( $result === null ) {
            wp_send_json_error( [ 'message' => 'Cloudflare not configured.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Cloudflare purge failed.' ] );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function has_cloudflare() {
        if ( defined( 'CLOUDFLARE_API_KEY' ) ) {
            return true;
        }
        $settings = get_option( 'cache_party_cloudflare', [] );
        return ! empty( $settings['api_key'] );
    }

    /**
     * Purge Cloudflare cache.
     *
     * @return bool|null true = success, false = failed, null = not configured.
     */
    private function purge_cloudflare() {
        $settings = get_option( 'cache_party_cloudflare', [] );
        $email    = defined( 'CLOUDFLARE_EMAIL' ) ? CLOUDFLARE_EMAIL : ( $settings['email'] ?? '' );
        $api_key  = defined( 'CLOUDFLARE_API_KEY' ) ? CLOUDFLARE_API_KEY : ( $settings['api_key'] ?? '' );
        $domain   = defined( 'CLOUDFLARE_DOMAIN_NAME' ) ? CLOUDFLARE_DOMAIN_NAME : ( $settings['domain'] ?? '' );

        if ( ! $api_key ) {
            return null;
        }

        if ( ! $domain ) {
            $domain = wp_parse_url( home_url(), PHP_URL_HOST );
        }

        $is_global_key = strlen( $api_key ) === 37 && preg_match( '/^[0-9a-f]+$/', $api_key );
        if ( $is_global_key && ! $email ) {
            return null;
        }

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( $is_global_key ) {
            $headers['X-Auth-Email'] = $email;
            $headers['X-Auth-Key']   = $api_key;
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $zone_id = get_transient( 'cache_party_cf_zone_id' );
        if ( ! $zone_id ) {
            $response = wp_remote_get( 'https://api.cloudflare.com/client/v4/zones?name=' . $domain, [
                'headers' => $headers,
                'timeout' => 10,
            ] );

            if ( is_wp_error( $response ) ) {
                return false;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $body['result'][0]['id'] ) ) {
                return false;
            }

            $zone_id = $body['result'][0]['id'];
            set_transient( 'cache_party_cf_zone_id', $zone_id, DAY_IN_SECONDS );
        }

        $response = wp_remote_request( 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache', [
            'method'  => 'DELETE',
            'headers' => $headers,
            'body'    => wp_json_encode( [ 'purge_everything' => true ] ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $body['success'] );
    }
}
