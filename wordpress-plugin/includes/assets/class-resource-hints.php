<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

class Resource_Hints {

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;

        // Logged-in users: ensure admin bar + query monitor styles load in head.
        add_action( 'wp_head', [ $this, 'force_admin_styles' ], 8 );

        // Script/style handle source replacement.
        add_filter( 'script_loader_src', [ $this, 'replace_script_src' ], 10, 2 );
        add_filter( 'style_loader_src', [ $this, 'replace_style_src' ], 10, 2 );
    }

    /**
     * Force admin bar and Query Monitor styles for logged-in users
     * so they render before CSS deferral kicks in.
     */
    public function force_admin_styles() {
        if ( is_user_logged_in() ) {
            wp_styles()->do_items( [ 'admin-bar', 'query-monitor' ] );
        }
    }

    /**
     * Allow replacing script sources by handle via filter.
     */
    public function replace_script_src( $src, $handle ) {
        $replace = apply_filters( 'cp_script_handle_src', apply_filters( 'aoc_script_handle_src', [] ) );
        if ( isset( $replace[ $handle ] ) ) {
            return $replace[ $handle ];
        }
        return $src;
    }

    /**
     * Allow replacing style sources by handle via filter.
     */
    public function replace_style_src( $src, $handle ) {
        $replace = apply_filters( 'cp_style_handle_src', apply_filters( 'aoc_style_handle_src', [] ) );
        if ( isset( $replace[ $handle ] ) ) {
            return $replace[ $handle ];
        }
        return $src;
    }

    /**
     * Send font/image preload headers or tags.
     * Called from AO_Bridge after minify, or standalone if AO is not active.
     */
    public static function send_preload_hints( $content, $preload_by_http = true ) {
        $CDN  = get_option( 'autoptimize_cdn_url' ) ?: '//' . ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) . '/';
        $head = '';

        // CSS preload (Autoptimize aggregated CSS).
        if ( preg_match( '/autoptimize\_([a-f0-9]{32})\.css/i', $content, $md5 ) ) {
            $url = $CDN . 'wp-content/cache/autoptimize/css/autoptimize_' . $md5[1] . '.css';
            if ( $preload_by_http ) {
                header( 'Link: <' . $url . '>; rel=preload; as=style', false );
            } else {
                $head .= '<link rel="preload" href="' . esc_url( $url ) . '" as="style">' . "\r\n";
            }
        }

        if ( preg_match_all( '/autoptimize\_single\_([a-f0-9]{32})\.css\?ver=([0-9]+)/i', $content, $md5s ) ) {
            foreach ( $md5s[1] as $i => $md5 ) {
                $url = $CDN . 'wp-content/cache/autoptimize/css/autoptimize_single_' . $md5 . '.css?ver=' . $md5s[2][ $i ];
                if ( $preload_by_http ) {
                    header( 'Link: <' . $url . '>; rel=preload; as=style', false );
                } else {
                    $head .= '<link rel="preload" href="' . esc_url( $url ) . '" as="style">' . "\r\n";
                }
            }
        }

        // Font preload.
        $fonts = apply_filters( 'cp_fonts_to_preload', apply_filters( 'aoc_fonts_to_preload', [], $CDN ), $CDN );
        foreach ( $fonts as $font ) {
            if ( $preload_by_http ) {
                header( 'Link: <' . $font . '>; rel=preload; as=font; crossorigin=anonymous', false );
            } else {
                $head .= '<link rel="preload" href="' . esc_url( $font ) . '" as="font" crossorigin="anonymous">' . "\r\n";
            }
        }

        $fonts_desktop = apply_filters( 'cp_fonts_to_preload_desktop', apply_filters( 'aoc_fonts_to_preload_desktop', [], $CDN ), $CDN );
        foreach ( $fonts_desktop as $font ) {
            if ( $preload_by_http ) {
                header( 'Link: <' . $font . '>; rel=preload; as=font; crossorigin=anonymous; media=(min-width: 768px)', false );
            } else {
                $head .= '<link rel="preload" href="' . esc_url( $font ) . '" as="font" crossorigin="anonymous" media="(min-width: 768px)">' . "\r\n";
            }
        }

        // Image preload (including per-post preload from post meta).
        $images = apply_filters( 'cp_images_to_preload', apply_filters( 'aoc_images_to_preload', [], $CDN ), $CDN );

        // Per-post preload support.
        if ( is_singular() ) {
            $ao_meta = get_post_meta( get_the_ID(), 'ao_post_optimize', true );
            if ( ! empty( $ao_meta['ao_post_preload'] ) ) {
                $images[] = $ao_meta['ao_post_preload'];
            }
        }

        foreach ( $images as $image ) {
            if ( $preload_by_http ) {
                header( 'Link: <' . $image . '>; rel=preload; as=image', false );
            } else {
                $head .= '<link rel="preload" href="' . esc_url( $image ) . '" as="image">' . "\r\n";
            }
        }

        return $head;
    }
}
