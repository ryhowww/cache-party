<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cloudflare cache purge integration.
 *
 * Replaces the full Cloudflare plugin with just the purge functionality:
 * - Auto-purge on content changes (selective by URL)
 * - Full purge via admin bar button
 * - After purge, notify the cache warmer to re-prime
 *
 * Settings can be defined as constants in wp-config.php:
 *   CLOUDFLARE_EMAIL, CLOUDFLARE_API_KEY, CLOUDFLARE_DOMAIN_NAME
 * Or via the Cache Party settings page.
 */
class Cloudflare {

    const API_BASE = 'https://api.cloudflare.com/client/v4/';

    private $email;
    private $api_key;
    private $domain;
    private $zone_id;

    public function __construct() {
        $settings = get_option( 'cache_party_cloudflare', [] );

        $this->email   = defined( 'CLOUDFLARE_EMAIL' ) ? CLOUDFLARE_EMAIL : ( $settings['email'] ?? '' );
        $this->api_key = defined( 'CLOUDFLARE_API_KEY' ) ? CLOUDFLARE_API_KEY : ( $settings['api_key'] ?? '' );
        $this->domain  = defined( 'CLOUDFLARE_DOMAIN_NAME' ) ? CLOUDFLARE_DOMAIN_NAME : ( $settings['domain'] ?? '' );

        // Auto-detect domain from home_url if not set.
        if ( ! $this->domain ) {
            $this->domain = wp_parse_url( home_url(), PHP_URL_HOST );
        }

        if ( ! $this->email || ! $this->api_key ) {
            return;
        }

        // Selective purge hooks.
        add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 20, 3 );
        add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 20 );
        add_action( 'delete_attachment', [ $this, 'on_deleted_post' ], 20 );
        add_action( 'comment_post', [ $this, 'on_new_comment' ], 20, 3 );
        add_action( 'transition_comment_status', [ $this, 'on_comment_status_change' ], 20, 3 );

        // Full purge hooks.
        add_action( 'switch_theme', [ $this, 'purge_everything' ], 20 );
        add_action( 'customize_save_after', [ $this, 'purge_everything' ], 20 );

        // CF-only admin bar button removed — replaced by unified Cache_Clear class.
    }

    /* ---------------------------------------------------------------
     *  API Methods
     * ------------------------------------------------------------- */

    /**
     * Get the Cloudflare Zone ID for the configured domain.
     * Cached in a transient for 24 hours.
     */
    private function get_zone_id() {
        if ( $this->zone_id ) {
            return $this->zone_id;
        }

        $cached = get_transient( 'cache_party_cf_zone_id' );
        if ( $cached ) {
            $this->zone_id = $cached;
            return $cached;
        }

        $response = $this->api_request( 'GET', 'zones?name=' . urlencode( $this->domain ) . '&status=active' );

        if ( ! $response || empty( $response['result'][0]['id'] ) ) {
            return false;
        }

        $this->zone_id = $response['result'][0]['id'];
        set_transient( 'cache_party_cf_zone_id', $this->zone_id, DAY_IN_SECONDS );

        return $this->zone_id;
    }

    /**
     * Purge specific URLs from Cloudflare cache.
     * Chunks into batches of 30 (CF API limit).
     */
    public function purge_urls( $urls ) {
        $zone_id = $this->get_zone_id();
        if ( ! $zone_id || empty( $urls ) ) {
            return false;
        }

        $urls    = array_unique( array_filter( $urls ) );
        $chunks  = array_chunk( $urls, 30 );
        $success = true;

        foreach ( $chunks as $chunk ) {
            $response = $this->api_request( 'DELETE', 'zones/' . $zone_id . '/purge_cache', [
                'files' => array_values( $chunk ),
            ] );

            if ( ! $response || empty( $response['success'] ) ) {
                $success = false;
            }
        }

        // Notify warmer to re-prime purged URLs.
        $this->notify_warmer( $urls );

        return $success;
    }

    /**
     * Purge entire Cloudflare cache for the zone.
     */
    public function purge_everything() {
        $zone_id = $this->get_zone_id();
        if ( ! $zone_id ) {
            return false;
        }

        $response = $this->api_request( 'DELETE', 'zones/' . $zone_id . '/purge_cache', [
            'purge_everything' => true,
        ] );

        // Notify warmer to re-prime the whole site.
        $this->notify_warmer_full();

        return $response && ! empty( $response['success'] );
    }

    /**
     * Make an API request to Cloudflare.
     */
    private function api_request( $method, $endpoint, $body = null ) {
        $headers = [ 'Content-Type' => 'application/json' ];

        // Detect auth method: 37 hex chars = Global API Key, otherwise Token.
        if ( strlen( $this->api_key ) === 37 && preg_match( '/^[0-9a-f]+$/', $this->api_key ) ) {
            $headers['X-Auth-Email'] = $this->email;
            $headers['X-Auth-Key']   = $this->api_key;
        } else {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 15,
        ];

        if ( $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( self::API_BASE . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /* ---------------------------------------------------------------
     *  WordPress Hook Callbacks
     * ------------------------------------------------------------- */

    public function on_post_status_change( $new_status, $old_status, $post ) {
        if ( wp_is_post_autosave( $post->ID ) || wp_is_post_revision( $post->ID ) ) {
            return;
        }

        // Only purge when publishing, updating published, or unpublishing.
        $dominated = ( $new_status === 'publish' || $old_status === 'publish' );
        if ( ! $dominated ) {
            return;
        }

        $urls = $this->get_post_related_urls( $post );
        if ( ! empty( $urls ) ) {
            $this->purge_urls( $urls );
        }
    }

    public function on_deleted_post( $post_id ) {
        $urls = [ home_url( '/' ) ];

        // Purge feeds.
        $urls[] = get_feed_link( 'rss2' );
        $urls[] = get_feed_link( 'atom' );

        $this->purge_urls( $urls );
    }

    public function on_new_comment( $comment_id, $comment_approved, $comment_data ) {
        if ( $comment_approved !== 1 ) {
            return;
        }

        $post_id = $comment_data['comment_post_ID'] ?? 0;
        if ( $post_id ) {
            $this->purge_urls( [ get_permalink( $post_id ) ] );
        }
    }

    public function on_comment_status_change( $new_status, $old_status, $comment ) {
        if ( $new_status === 'approved' || $old_status === 'approved' ) {
            $this->purge_urls( [ get_permalink( $comment->comment_post_ID ) ] );
        }
    }

    /**
     * Collect all URLs related to a post that should be purged.
     */
    private function get_post_related_urls( $post ) {
        $urls = [];

        // Post URL.
        $permalink = get_permalink( $post );
        if ( $permalink ) {
            $urls[] = $permalink;
        }

        // Homepage.
        $urls[] = home_url( '/' );

        // Posts page (if using static front page).
        $posts_page = get_option( 'page_for_posts' );
        if ( $posts_page ) {
            $urls[] = get_permalink( $posts_page );
        }

        // Taxonomy archives (categories, tags, custom).
        $taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
        foreach ( $taxonomies as $taxonomy ) {
            if ( ! $taxonomy->public ) {
                continue;
            }
            $terms = get_the_terms( $post->ID, $taxonomy->name );
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $urls[] = get_term_link( $term );
                }
            }
        }

        // Author archive.
        $urls[] = get_author_posts_url( $post->post_author );

        // Post type archive.
        $archive_link = get_post_type_archive_link( $post->post_type );
        if ( $archive_link ) {
            $urls[] = $archive_link;
        }

        // Feeds.
        $urls[] = get_feed_link( 'rss2' );
        $urls[] = get_feed_link( 'atom' );

        // Filter out WP_Error values from get_term_link.
        $urls = array_filter( $urls, function( $url ) {
            return is_string( $url ) && $url !== '';
        } );

        return apply_filters( 'cache_party_cf_purge_urls', $urls, $post );
    }

    /* ---------------------------------------------------------------
     *  Warmer Integration
     * ------------------------------------------------------------- */

    private function notify_warmer( $urls = [] ) {
        $warmer_settings = get_option( 'cache_party_warmer', [] );
        $api_url = $warmer_settings['api_url'] ?? '';
        $api_key = $warmer_settings['api_key'] ?? '';

        if ( ! $api_url ) {
            return;
        }

        wp_remote_post( rtrim( $api_url, '/' ) . '/api/warm', [
            'blocking' => false,
            'timeout'  => 1,
            'headers'  => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'site' => $warmer_settings['site_name'] ?: wp_parse_url( home_url(), PHP_URL_HOST ),
            ] ),
        ] );
    }

    private function notify_warmer_full() {
        $this->notify_warmer();
    }

    /* ---------------------------------------------------------------
     *  Admin Bar
     * ------------------------------------------------------------- */

    public function admin_bar_button( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $wp_admin_bar->add_node( [
            'id'    => 'cache-party-cf-purge',
            'title' => 'Purge CF Cache',
            'href'  => '#',
            'meta'  => [
                'onclick' => 'cachePartyCFPurge(); return false;',
            ],
        ] );
    }

    public function admin_bar_script() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <script>
        function cachePartyCFPurge() {
            if (!confirm('Purge entire Cloudflare cache?')) return;
            var bar = document.getElementById('wp-admin-bar-cache-party-cf-purge');
            if (bar) bar.querySelector('a').textContent = 'Purging...';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                var msg = 'Purge failed.';
                try { var r = JSON.parse(xhr.responseText); msg = r.data ? r.data.message : msg; } catch(e) {}
                if (bar) bar.querySelector('a').textContent = msg;
                setTimeout(function() { if (bar) bar.querySelector('a').textContent = 'Purge CF Cache'; }, 3000);
            };
            xhr.send('action=cache_party_cf_purge&nonce=<?php echo esc_js( wp_create_nonce( 'cache_party_cf_purge' ) ); ?>');
        }
        </script>
        <?php
    }

    public function ajax_purge_all() {
        check_ajax_referer( 'cache_party_cf_purge', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $result = $this->purge_everything();

        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Cache purged!' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Purge failed — check CF credentials.' ] );
        }
    }
}
