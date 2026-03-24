<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Single output buffer orchestrator.
 *
 * All HTML-rewriting processors (CSS aggregation, CSS deferral, JS delay,
 * iframe lazy, picture wrapper, lazy loader) register here instead of
 * starting their own ob_start(). One buffer at template_redirect priority 3.
 */
class Output_Buffer {

    private static $instance;
    private $processors = [];
    private $registered = false;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Register a processor callback with a priority.
     * Lower priority = runs first. Mirrors the old template_redirect priorities:
     *   3 = CSS deferral, 4 = JS delay, 5 = iframe lazy,
     *   999 = picture wrapper, 1000 = lazy loader.
     */
    public function add_processor( $priority, $callback ) {
        $this->processors[] = [
            'priority' => $priority,
            'callback' => $callback,
        ];

        usort( $this->processors, function( $a, $b ) {
            return $a['priority'] - $b['priority'];
        } );

        if ( ! $this->registered ) {
            $this->registered = true;
            add_action( 'template_redirect', [ $this, 'start_buffer' ], 3 );
        }
    }

    public function start_buffer() {
        if ( is_admin() || wp_doing_ajax() || is_feed() || defined( 'REST_REQUEST' ) ) {
            return;
        }

        ob_start( [ $this, 'process' ] );
    }

    public function process( $content ) {
        if ( empty( $content ) || strpos( $content, '</body>' ) === false ) {
            return $content;
        }

        foreach ( $this->processors as $processor ) {
            $content = call_user_func( $processor['callback'], $content );
        }

        return $content;
    }
}
