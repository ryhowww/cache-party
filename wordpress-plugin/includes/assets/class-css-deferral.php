<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Deferral {

    private $settings;
    private $deferred_styles  = '';
    private $head_styles      = '';

    public function __construct( $settings ) {
        $this->settings = $settings;

        if ( $settings['css_defer_enabled'] ) {
            add_action( 'template_redirect', [ $this, 'start_buffer' ], 3 );
        }
    }

    public function start_buffer() {
        ob_start( [ $this, 'process_buffer' ] );
    }

    /**
     * Process the full HTML buffer — extract and defer stylesheets.
     */
    public function process_buffer( $content ) {
        // Validate buffer.
        if ( function_exists( 'autoptimize' ) && ! autoptimize()->is_valid_buffer( $content ) ) {
            return $content;
        }

        if ( strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        $this->deferred_styles = '';
        $this->head_styles     = '';

        // Parse settings into keyword arrays.
        $delete_kw  = $this->keywords_from_setting( 'css_delete_keywords' );
        $defer_kw   = $this->keywords_from_setting( 'css_defer_keywords' );
        $except_kw  = $this->keywords_from_setting( 'css_defer_except' );

        // Apply legacy AOC filters + new CP filters.
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

        // Re-inject head styles and deferred styles.
        $content = str_replace( '</head>', "\r\n" . $this->head_styles . "\r\n" . '</head>', $content );
        $content = str_replace( '</body>', '<noscript id="deferred-styles">' . "\r\n" . $this->deferred_styles . "\r\n" . '</noscript></body>', $content );

        return $content;
    }

    private function process_style( $matches, $delete_kw, $defer_kw, $except_kw ) {
        $tag     = $matches[0];
        $inline  = $matches[1] ?? '';
        $link    = $matches[2] ?? '';

        // Skip if marked.
        if ( strpos( $tag, 'data-cp-skip' ) !== false || strpos( $tag, 'data-aoc-skip' ) !== false ) {
            $this->head_styles .= $tag;
            return '';
        }

        // Delete by keyword.
        foreach ( $delete_kw as $kw ) {
            if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                return '';
            }
        }

        // Defer by keyword (specific styles).
        foreach ( $defer_kw as $kw ) {
            if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                $this->deferred_styles .= "\r\n" . $tag;
                return '';
            }
        }

        // Defer all EXCEPT by keyword.
        if ( ! empty( $except_kw ) ) {
            $is_excepted = false;
            foreach ( $except_kw as $kw ) {
                if ( $kw !== '' && ( stripos( $inline, $kw ) !== false || stripos( $link, $kw ) !== false ) ) {
                    $is_excepted = true;
                    break;
                }
            }
            if ( ! $is_excepted ) {
                $this->deferred_styles .= "\r\n" . $tag;
                return '';
            }
        }

        $this->head_styles .= $tag;
        return '';
    }

    private function keywords_from_setting( $key ) {
        $value = $this->settings[ $key ] ?? '';
        return array_filter( array_map( 'trim', explode( "\n", $value ) ) );
    }
}
