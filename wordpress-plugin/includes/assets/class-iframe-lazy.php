<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

class Iframe_Lazy {

    private $exclude_keywords;

    public function __construct( $settings ) {
        $this->exclude_keywords = array_filter(
            array_map( 'trim', explode( "\n", $settings['iframe_exclude_keywords'] ?? '' ) )
        );
    }

    public function process_buffer( $content ) {
        if ( strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        $exclude_kw = apply_filters( 'cp_lazy_iframe_exclude_kw', apply_filters( 'aoc_lazy_iframe_exclude_kw', $this->exclude_keywords ) );

        $content = preg_replace_callback( '#<iframe(.*?)>.*?</iframe>#is', function( $matches ) use ( $exclude_kw ) {
            $tag = $matches[0];

            // Skip if marked.
            if ( strpos( $tag, 'data-cp-skip' ) !== false || strpos( $tag, 'data-aoc-skip' ) !== false ) {
                return $tag;
            }

            // Check exclusions.
            foreach ( $exclude_kw as $kw ) {
                if ( $kw !== '' && stripos( $tag, $kw ) !== false ) {
                    return $tag;
                }
            }

            $tag = str_replace( ' src=', ' data-lazy-src=', $tag );

            return apply_filters( 'cp_iframe_html', apply_filters( 'aoc_iframe_html', $tag ) );
        }, $content );

        return $content;
    }
}
