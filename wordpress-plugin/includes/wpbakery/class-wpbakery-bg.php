<?php
/**
 * Cache Party — WPBakery responsive background images.
 *
 * Auto-detects WPBakery and injects @media (max-width:540px) rules with the
 * WP `large` size URL for any vc_row/vc_column whose css attribute references
 * an attachment by ?id=NNN. Hooks vc_shortcodes_custom_css (post-cache filter)
 * so it doesn't interact with WPBakery's _wpb_shortcodes_custom_css postmeta
 * cache — the rule runs on every page render and never persists.
 *
 * @package CacheParty\WPBakery
 */

namespace CacheParty\WPBakery;

if ( ! defined( 'ABSPATH' ) ) exit;

class WPBakery_BG {

    public function __construct() {
        // Only attach if WPBakery is active.
        if ( ! defined( 'WPB_VC_VERSION' ) && ! class_exists( '\\Vc_Manager' ) ) {
            return;
        }
        add_filter( 'vc_shortcodes_custom_css', [ $this, 'inject_mobile_bg' ], 10, 2 );
    }

    /**
     * Append a @media (max-width:540px) rule to any vc_custom_NNN class
     * whose background-image references a media library attachment via ?id=NNN.
     */
    public function inject_mobile_bg( $css, $post_id ) {
        if ( empty( $css ) || ! is_string( $css ) ) {
            return $css;
        }

        $size       = apply_filters( 'cp_wpbakery_mobile_bg_size', 'large' );
        $breakpoint = (int) apply_filters( 'cp_wpbakery_mobile_bg_breakpoint', 540 );

        return preg_replace_callback(
            '#\.(vc_custom_\d+)\{[^}]*background-image:\s*url\([^)]*\?id=(\d+)[^}]*\}#',
            function ( $m ) use ( $size, $breakpoint ) {
                $url = wp_get_attachment_image_url( (int) $m[2], $size );
                if ( ! $url ) {
                    return $m[0];
                }
                return $m[0] . sprintf(
                    '@media (max-width:%dpx){.%s{background-image:url(%s) !important;background-size:cover;background-position:center;}}',
                    $breakpoint,
                    $m[1],
                    esc_url_raw( $url )
                );
            },
            $css
        );
    }
}
