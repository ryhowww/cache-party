<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

class JS_Delay {

    private $settings;

    public function __construct( $settings ) {
        $this->settings = $settings;

        if ( $settings['js_delay_enabled'] ) {
            add_action( 'template_redirect', [ $this, 'start_buffer' ], 4 );
        }
    }

    public function start_buffer() {
        ob_start( [ $this, 'process_buffer' ] );
    }

    public function process_buffer( $content ) {
        if ( strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        // Move scripts to end.
        $move_kw = $this->keywords_from_setting( 'js_move_to_end_keywords' );
        $move_kw = apply_filters( 'cp_script_move_to_end_kw', apply_filters( 'aoc_script_move_to_end_kw', $move_kw ) );

        if ( ! empty( $move_kw ) ) {
            $js_to_end = '';
            $content = preg_replace_callback( '#<script(.*?)</script>#is', function( $matches ) use ( $move_kw, &$js_to_end ) {
                foreach ( $move_kw as $kw ) {
                    if ( $kw !== '' && stripos( $matches[1], $kw ) !== false ) {
                        $js_to_end .= $matches[0];
                        return '';
                    }
                }
                return $matches[0];
            }, $content );
            $content = str_replace( '</body>', $js_to_end . '</body>', $content );
        }

        // Delay and delete scripts.
        $delete_kw   = $this->keywords_from_setting( 'js_delete_keywords' );
        $delay_tag   = $this->keywords_from_setting( 'js_delay_tag_keywords' );
        $delay_code  = $this->keywords_from_setting( 'js_delay_code_keywords' );

        $delete_kw  = apply_filters( 'cp_script_delete_kw', apply_filters( 'aoc_script_delete_kw', $delete_kw ) );
        $delay_tag  = apply_filters( 'cp_delay_script_tag_atts_kw', apply_filters( 'aoc_delay_script_tag_atts_kw', $delay_tag ) );
        $delay_code = apply_filters( 'cp_delay_script_code_kw', apply_filters( 'aoc_delay_script_code_kw', $delay_code ) );

        $delayed_scripts = '';
        $content = preg_replace_callback( '#<script(.*?)>(.*?)</script>#is', function( $matches ) use ( $delete_kw, $delay_tag, $delay_code, &$delayed_scripts ) {
            $tag_attrs = $matches[1];
            $code      = $matches[2];
            $full_tag  = $matches[0];

            // Skip if marked.
            if ( strpos( $tag_attrs, 'data-cp-skip' ) !== false || strpos( $tag_attrs, 'data-aoc-skip' ) !== false ) {
                return $full_tag;
            }

            // Delete by keyword.
            foreach ( $delete_kw as $kw ) {
                if ( $kw !== '' && ( stripos( $tag_attrs, $kw ) !== false || stripos( $code, $kw ) !== false ) ) {
                    return '';
                }
            }

            // Delay by tag attributes.
            foreach ( $delay_tag as $kw ) {
                if ( $kw !== '' && stripos( $tag_attrs, $kw ) !== false ) {
                    $delayed_scripts .= "\r\n" . $full_tag;
                    return '';
                }
            }

            // Delay by code content.
            foreach ( $delay_code as $kw ) {
                if ( $kw !== '' && stripos( $code, $kw ) !== false ) {
                    $delayed_scripts .= "\r\n" . $full_tag;
                    return '';
                }
            }

            return $full_tag;
        }, $content );

        $content = str_replace( '</body>', '<noscript id="delayed-scripts">' . "\r\n" . $delayed_scripts . "\r\n" . '</noscript></body>', $content );

        // Apply final HTML filter.
        $content = apply_filters( 'cp_html', apply_filters( 'aoc_html', $content ) );

        return $content;
    }

    private function keywords_from_setting( $key ) {
        $value = $this->settings[ $key ] ?? '';
        return array_filter( array_map( 'trim', explode( "\n", $value ) ) );
    }
}
