<?php

namespace CacheParty\Assets;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Delay JavaScript execution until user interaction.
 *
 * Uses the data-src swap pattern (like AO Pro):
 * - External scripts: src → data-src, type → data-type="cp-delay"
 * - Inline scripts: base64-encode body into data-src, type → data-type="cp-delay"
 *
 * Scripts stay in the DOM (no noscript extraction). The interaction-loader.js
 * dispatches a "delayedLoad" event on first interaction or idle timeout,
 * and a small inline handler swaps data-src back to src.
 *
 * AO sees scripts without src and skips them during aggregation.
 */
class JS_Delay {

    private $settings;

    /** Hard exclusions — never delay these regardless of keywords. */
    private static $hard_exclude = [
        'data-cp-skip',
        'data-noptimize',
        'data-cfasync="false"',
        'data-type="cp-delay"',
        'cache/autoptimize',
    ];

    public function __construct( $settings ) {
        $this->settings = $settings;
    }

    public function process_buffer( $content ) {
        if ( strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        // Move scripts to end.
        $move_kw = $this->keywords_from_setting( 'js_move_to_end_keywords' );
        $move_kw = apply_filters( 'cp_script_move_to_end_kw', $move_kw );

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

        // Delete and delay scripts.
        $delete_kw  = $this->keywords_from_setting( 'js_delete_keywords' );
        $delay_tag  = $this->keywords_from_setting( 'js_delay_tag_keywords' );
        $delay_code = $this->keywords_from_setting( 'js_delay_code_keywords' );

        $delete_kw  = apply_filters( 'cp_script_delete_kw', $delete_kw );
        $delay_tag  = apply_filters( 'cp_delay_script_tag_atts_kw', $delay_tag );
        $delay_code = apply_filters( 'cp_delay_script_code_kw', $delay_code );

        $has_delayed = false;

        $content = preg_replace_callback( '#<script(.*?)>(.*?)</script>#is', function( $matches ) use ( $delete_kw, $delay_tag, $delay_code, &$has_delayed ) {
            $tag_attrs = $matches[1];
            $code      = $matches[2];
            $full_tag  = $matches[0];

            // Hard exclusions.
            foreach ( self::$hard_exclude as $excl ) {
                if ( stripos( $tag_attrs, $excl ) !== false ) {
                    return $full_tag;
                }
            }

            // Delete by keyword.
            foreach ( $delete_kw as $kw ) {
                if ( $kw !== '' && ( stripos( $tag_attrs, $kw ) !== false || stripos( $code, $kw ) !== false ) ) {
                    return '';
                }
            }

            // Check if this script should be delayed.
            $should_delay = false;

            foreach ( $delay_tag as $kw ) {
                if ( $kw !== '' && stripos( $tag_attrs, $kw ) !== false ) {
                    $should_delay = true;
                    break;
                }
            }

            if ( ! $should_delay ) {
                foreach ( $delay_code as $kw ) {
                    if ( $kw !== '' && stripos( $code, $kw ) !== false ) {
                        $should_delay = true;
                        break;
                    }
                }
            }

            if ( ! $should_delay ) {
                return $full_tag;
            }

            $has_delayed = true;
            return $this->convert_to_delayed( $tag_attrs, $code );
        }, $content );

        // Inject the data-src swap handler if we delayed any scripts.
        if ( $has_delayed ) {
            $content = str_replace( '</body>', $this->get_swap_handler_js() . '</body>', $content );
        }

        // Apply final HTML filter.
        $content = apply_filters( 'cp_html', $content );

        return $content;
    }

    /**
     * Convert a script tag to delayed form using data-src swap.
     *
     * External: <script src="..."> → <script data-type="cp-delay" data-src="...">
     * Inline:   <script>code</script> → <script data-type="cp-delay" data-src="data:text/javascript;base64,...">
     */
    private function convert_to_delayed( $tag_attrs, $code ) {
        // Strip type="text/javascript" (redundant, can confuse re-execution).
        $tag_attrs = preg_replace( '/\s*type=["\']text\/javascript["\']/i', '', $tag_attrs );

        // Strip defer/async — we control execution timing now.
        $tag_attrs = preg_replace( '/\s*(defer|async)(=["\'][^"\']*["\'])?/i', '', $tag_attrs );

        // External script (has src attribute).
        if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag_attrs, $src_match ) ) {
            $src = $src_match[1];
            // Remove src, add data-src and data-type.
            $tag_attrs = preg_replace( '/\s*src=["\'][^"\']+["\']/i', '', $tag_attrs );
            return '<script data-type="cp-delay" data-src="' . esc_attr( $src ) . '"' . $tag_attrs . '></script>';
        }

        // Inline script — base64-encode the code.
        if ( trim( $code ) !== '' ) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            $encoded = base64_encode( $code );
            return '<script data-type="cp-delay" data-src="data:text/javascript;base64,' . $encoded . '"' . $tag_attrs . '></script>';
        }

        // Empty script tag — just return as-is.
        return '<script' . $tag_attrs . '>' . $code . '</script>';
    }

    /**
     * Inline JS that listens for the delayedLoad event and swaps data-src back.
     * Handles async (just set src) vs sync (create new element) scripts.
     */
    private function get_swap_handler_js() {
        return '<script data-cp-skip>
document.addEventListener("delayedLoad",function(){
var s=document.querySelectorAll("script[data-type=\'cp-delay\']");
(async function(){
for(var i=0;i<s.length;i++){
var el=s[i],src=el.getAttribute("data-src");
if(!src)continue;
try{
if(src.indexOf("data:")===0){
var n=document.createElement("script");
n.textContent=atob(src.split(",")[1]);
el.parentNode.insertBefore(n,el.nextSibling);
el.parentNode.removeChild(el);
}else{
await new Promise(function(ok,fail){
var n=document.createElement("script");
n.src=src;
n.onload=ok;n.onerror=fail;
el.parentNode.insertBefore(n,el.nextSibling);
el.parentNode.removeChild(el);
});
}
}catch(e){}
}
})();
},{once:true});
</script>' . "\n";
    }

    private function keywords_from_setting( $key ) {
        $value = $this->settings[ $key ] ?? '';
        return array_filter( array_map( 'trim', explode( "\n", $value ) ) );
    }
}
