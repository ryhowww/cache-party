<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Deferral {

    private $settings;
    private $deferred_links   = '';
    private $deferred_inlines = '';
    private $head_styles      = '';

    public function __construct( $settings ) {
        $this->settings = $settings;
    }

    public function process_buffer( $content ) {
        if ( strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        $this->deferred_links   = '';
        $this->deferred_inlines = '';
        $this->head_styles      = '';

        $delete_kw  = $this->keywords_from_setting( 'css_delete_keywords' );
        $defer_kw   = $this->keywords_from_setting( 'css_defer_keywords' );
        $except_kw  = $this->keywords_from_setting( 'css_defer_except' );

        $delete_kw = apply_filters( 'cp_delete_style_kw', apply_filters( 'aoc_delete_srtyle_kw', $delete_kw ) );
        $defer_kw  = apply_filters( 'cp_defer_style_kw', apply_filters( 'aoc_defer_srtyle_kw', $defer_kw ) );
        $except_kw = apply_filters( 'cp_defer_style_except_kw', apply_filters( 'aoc_defer_srtyle_except_kw', $except_kw ) );

        $content = preg_replace_callback(
            '#(<style[^>]*>.*</style>)|(<link[^>]*stylesheet[^>]*>)#Usmi',
            function( $matches ) use ( $delete_kw, $defer_kw, $except_kw ) {
                return $this->process_style( $matches, $delete_kw, $defer_kw, $except_kw );
            },
            $content
        );

        // Put excepted/head styles back in <head>.
        $content = str_replace( '</head>', "\r\n" . $this->head_styles . "\r\n" . '</head>', $content );

        // Deferred <link> stylesheets: use media="print" onload pattern.
        // They load immediately but non-render-blocking — no 5-second delay.
        // Deferred inline <style> blocks: keep in noscript for AO Bridge compat.
        $before_body = '';

        if ( $this->deferred_links !== '' ) {
            $before_body .= "\r\n" . $this->deferred_links;
        }

        // The noscript block holds deferred inline styles (for AO Bridge to
        // extract and process). Even if empty, keep the block so AO Bridge's
        // regex doesn't fail if it's looking for it.
        $before_body .= '<noscript id="deferred-styles">' . "\r\n" . $this->deferred_inlines . "\r\n" . '</noscript>';

        $content = str_replace( '</body>', $before_body . '</body>', $content );

        return $content;
    }

    private function process_style( $matches, $delete_kw, $defer_kw, $except_kw ) {
        $tag     = $matches[0];
        $inline  = $matches[1] ?? '';
        $link    = $matches[2] ?? '';

        if ( strpos( $tag, 'data-cp-skip' ) !== false || strpos( $tag, 'data-aoc-skip' ) !== false ) {
            $this->head_styles .= $tag;
            return '';
        }

        foreach ( $delete_kw as $kw ) {
            if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                return '';
            }
        }

        foreach ( $defer_kw as $kw ) {
            if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                $this->defer_tag( $tag, $link );
                return '';
            }
        }

        if ( ! empty( $except_kw ) ) {
            $is_excepted = false;
            foreach ( $except_kw as $kw ) {
                if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                    $is_excepted = true;
                    break;
                }
            }
            if ( ! $is_excepted ) {
                $this->defer_tag( $tag, $link );
                return '';
            }
        }

        $this->head_styles .= $tag;
        return '';
    }

    /**
     * Defer a style tag using the appropriate technique:
     * - <link> stylesheets: media="print" onload="this.media='all'" (loads
     *   immediately, non-render-blocking, no JS delay needed)
     * - Inline <style>: noscript block (for AO Bridge extraction)
     */
    private function defer_tag( $tag, $link ) {
        if ( $link !== '' ) {
            // Extract the real media value to restore on load.
            $media = 'all';
            if ( preg_match( '/\bmedia=["\']([^"\']+)["\']/i', $link, $m ) ) {
                $media = $m[1];
            }

            // Rewrite to media="print" with onload swap.
            $deferred = preg_replace( '/\bmedia=["\'][^"\']*["\']/i', '', $link );
            $deferred = str_replace(
                '<link',
                '<link media="print" onload="this.media=\'' . esc_attr( $media ) . '\'"',
                $deferred
            );

            $this->deferred_links .= "\r\n" . $deferred;
        } else {
            $this->deferred_inlines .= "\r\n" . $tag;
        }
    }

    private function keywords_from_setting( $key ) {
        $value = $this->settings[ $key ] ?? '';
        return array_filter( array_map( 'trim', explode( "\n", $value ) ) );
    }
}
