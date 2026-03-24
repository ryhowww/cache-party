<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CSS processing for the output buffer.
 *
 * Handles delete-by-keyword AND defer via media="print" onload on
 * <link> stylesheets. Inline <style> blocks that match defer keywords
 * go into a noscript for the interaction loader.
 */
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

        return $this->process_standalone( $content );
    }

    /**
     * Full CSS deferral pipeline: delete + defer.
     * <link> stylesheets get media="print" onload (non-render-blocking).
     * Inline <style> blocks go into noscript for interaction loader.
     */
    private function process_standalone( $content ) {
        $this->deferred_links   = '';
        $this->deferred_inlines = '';
        $this->head_styles      = '';

        $delete_kw = $this->keywords_from_setting( 'css_delete_keywords' );
        $defer_kw  = $this->keywords_from_setting( 'css_defer_keywords' );
        $except_kw = $this->keywords_from_setting( 'css_defer_except' );

        $delete_kw = apply_filters( 'cp_delete_style_kw', $delete_kw );
        $defer_kw  = apply_filters( 'cp_defer_style_kw', $defer_kw );
        $except_kw = apply_filters( 'cp_defer_style_except_kw', $except_kw );

        $content = preg_replace_callback(
            '#(<style[^>]*>.*</style>)|(<link[^>]*stylesheet[^>]*>)#Usmi',
            function( $matches ) use ( $delete_kw, $defer_kw, $except_kw ) {
                return $this->process_style( $matches, $delete_kw, $defer_kw, $except_kw );
            },
            $content
        );

        $content = str_replace( '</head>', "\r\n" . $this->head_styles . "\r\n" . '</head>', $content );

        $before_body = '';
        if ( $this->deferred_links !== '' ) {
            $before_body .= "\r\n" . $this->deferred_links;
        }
        if ( $this->deferred_inlines !== '' ) {
            $before_body .= '<noscript id="deferred-styles">' . "\r\n" . $this->deferred_inlines . "\r\n" . '</noscript>';
        }

        if ( $before_body !== '' ) {
            $content = str_replace( '</body>', $before_body . '</body>', $content );
        }

        return $content;
    }

    private function process_style( $matches, $delete_kw, $defer_kw, $except_kw ) {
        $tag    = $matches[0];
        $inline = $matches[1] ?? '';
        $link   = $matches[2] ?? '';

        if ( strpos( $tag, 'data-cp-skip' ) !== false ) {
            $this->head_styles .= $tag;
            return '';
        }

        foreach ( $delete_kw as $kw ) {
            if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                return '';
            }
        }

        // Defer keywords populated → allowlist mode: only defer matching.
        if ( ! empty( $defer_kw ) ) {
            foreach ( $defer_kw as $kw ) {
                if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                    $this->defer_tag( $tag, $link );
                    return '';
                }
            }
            // Didn't match any defer keyword — keep in head.
            $this->head_styles .= $tag;
            return '';
        }

        // Except keywords populated → blocklist mode: defer all except matching.
        if ( ! empty( $except_kw ) ) {
            foreach ( $except_kw as $kw ) {
                if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                    $this->head_styles .= $tag;
                    return '';
                }
            }
        }

        // Both empty, or except didn't match → defer everything.
        $this->defer_tag( $tag, $link );
        return '';
    }

    private function defer_tag( $tag, $link ) {
        if ( $link !== '' ) {
            $media = 'all';
            if ( preg_match( '/\bmedia=["\']([^"\']+)["\']/i', $link, $m ) ) {
                $media = $m[1];
            }

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
