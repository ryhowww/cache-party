<?php

namespace CacheParty\Warmer;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Hooks into WordPress content change events to notify the warmer.
 *
 * URL-specific warm: save_post, trashed_post, deleted_post
 * Full site warm: wp_update_nav_menu, customize_save_after
 *
 * All requests are non-blocking (fire and forget).
 */
class Purge_Hooks {

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;

        // URL-specific warm on content changes.
        add_action( 'save_post', [ $this, 'on_save_post' ], 10, 2 );
        add_action( 'trashed_post', [ $this, 'on_trashed_post' ] );
        add_action( 'deleted_post', [ $this, 'on_deleted_post' ] );

        // Full site warm on structural changes.
        add_action( 'wp_update_nav_menu', [ $this, 'on_full_site_change' ] );
        add_action( 'customize_save_after', [ $this, 'on_full_site_change' ] );
    }

    public function on_save_post( $post_id, $post ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( $post->post_status !== 'publish' ) {
            return;
        }

        $url = get_permalink( $post_id );
        if ( $url ) {
            $this->warm_url( $url );
        }
    }

    public function on_trashed_post( $post_id ) {
        // Warm the homepage since a page was removed.
        $this->warm_url( home_url( '/' ) );
    }

    public function on_deleted_post( $post_id ) {
        $this->warm_url( home_url( '/' ) );
    }

    public function on_full_site_change() {
        $this->warm_site();
    }

    private function warm_url( $url ) {
        $api_url = $this->settings['api_url'] ?? '';
        $api_key = $this->settings['api_key'] ?? '';

        if ( ! $api_key ) {
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
                'site' => wp_parse_url( home_url(), PHP_URL_HOST ),
                'url'  => $url,
            ] ),
        ] );
    }

    private function warm_site() {
        $api_url = $this->settings['api_url'] ?? '';
        $api_key = $this->settings['api_key'] ?? '';

        if ( ! $api_key ) {
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
                'site' => wp_parse_url( home_url(), PHP_URL_HOST ),
                'all'  => true,
            ] ),
        ] );
    }
}
