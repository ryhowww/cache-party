<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

class CSS_Deferral {

    private $settings;
    private $deferred_styles  = '';
    private $head_styles      = '';

    public function __construct( $settings ) {
        $this->settings = $settings;
    }

    public function process_buffer( $content ) {
        if ( strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        $this->deferred_styles = '';
        $this->head_styles     = '';

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

        $content = str_replace( '</head>', "\r\n" . $this->head_styles . "\r\n" . '</head>', $content );
        $content = str_replace( '</body>', '<noscript id="deferred-styles">' . "\r\n" . $this->deferred_styles . "\r\n" . '</noscript></body>', $content );

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
                $this->deferred_styles .= "\r\n" . $tag;
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
