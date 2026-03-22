<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Per-page meta box for Cache Party.
 *
 * Allows per-post/page overrides:
 * - LCP image URL (for preloading)
 * - Custom preload URLs
 * - Skip optimization toggle
 */
class Meta_Box {

    const META_KEY = '_cache_party_page';

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'register' ] );
        add_action( 'save_post', [ $this, 'save' ], 10, 2 );
    }

    public function register() {
        $post_types = get_post_types( [ 'public' => true ] );

        foreach ( $post_types as $type ) {
            add_meta_box(
                'cache_party_page',
                'Cache Party',
                [ $this, 'render' ],
                $type,
                'side',
                'default'
            );
        }
    }

    public function render( $post ) {
        $meta = get_post_meta( $post->ID, self::META_KEY, true );
        $meta = wp_parse_args( (array) $meta, [
            'lcp_image'   => '',
            'preload_urls' => '',
            'skip'        => false,
        ] );

        wp_nonce_field( 'cache_party_page_meta', 'cache_party_page_nonce' );
        ?>
        <p>
            <label for="cp_lcp_image"><strong>LCP Image URL</strong></label><br>
            <input type="text" name="cache_party_page[lcp_image]" id="cp_lcp_image"
                   value="<?php echo esc_attr( $meta['lcp_image'] ); ?>"
                   class="widefat" placeholder="https://..." />
            <span class="description">Image to preload with fetchpriority="high".</span>
        </p>

        <p>
            <label for="cp_preload_urls"><strong>Preload URLs</strong></label><br>
            <textarea name="cache_party_page[preload_urls]" id="cp_preload_urls"
                      class="widefat" rows="3" placeholder="One URL per line"><?php echo esc_textarea( $meta['preload_urls'] ); ?></textarea>
            <span class="description">Additional resources to preload on this page.</span>
        </p>

        <p>
            <label>
                <input type="hidden" name="cache_party_page[skip]" value="0" />
                <input type="checkbox" name="cache_party_page[skip]" value="1"
                    <?php checked( $meta['skip'] ); ?> />
                <strong>Skip optimization</strong>
            </label><br>
            <span class="description">Disable CSS deferral and JS delay on this page.</span>
        </p>
        <?php
    }

    public function save( $post_id, $post ) {
        if ( ! isset( $_POST['cache_party_page_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['cache_party_page_nonce'], 'cache_party_page_meta' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $input = $_POST['cache_party_page'] ?? [];
        $clean = [
            'lcp_image'    => isset( $input['lcp_image'] ) ? esc_url_raw( $input['lcp_image'] ) : '',
            'preload_urls' => isset( $input['preload_urls'] ) ? sanitize_textarea_field( $input['preload_urls'] ) : '',
            'skip'         => ! empty( $input['skip'] ),
        ];

        // Don't store empty meta.
        if ( $clean['lcp_image'] === '' && $clean['preload_urls'] === '' && ! $clean['skip'] ) {
            delete_post_meta( $post_id, self::META_KEY );
        } else {
            update_post_meta( $post_id, self::META_KEY, $clean );
        }
    }

    /**
     * Get per-page meta for the current post.
     *
     * @return array|false
     */
    public static function get_current_page_meta() {
        if ( ! is_singular() ) {
            return false;
        }

        $meta = get_post_meta( get_the_ID(), self::META_KEY, true );
        if ( empty( $meta ) ) {
            return false;
        }

        return wp_parse_args( (array) $meta, [
            'lcp_image'    => '',
            'preload_urls' => '',
            'skip'         => false,
        ] );
    }
}
