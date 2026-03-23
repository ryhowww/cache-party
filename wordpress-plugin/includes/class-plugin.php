<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

class Plugin {

    private static $instance;

    public static function init() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'load' ], 11 );
    }

    public function load() {
        new Settings();
        new Module_Loader();
        new Meta_Box();
        new Cloudflare();

        // Critical CSS: always active if files exist.
        new Assets\Critical_CSS();

        // Debug overlay: only on front end with ?perf-debug=1.
        if ( ! is_admin() ) {
            new Debug();
        }

        // WP-CLI: migration + preset commands.
        Migration::register_cli();
    }

    public static function uninstall() {
        $cleanup = (bool) get_option( 'cache_party_cleanup', false );

        if ( is_multisite() ) {
            $sites = get_sites( [ 'fields' => 'ids' ] );
            foreach ( $sites as $site_id ) {
                switch_to_blog( (int) $site_id );
                self::cleanup_site( $cleanup );
                restore_current_blog();
            }
        } else {
            self::cleanup_site( $cleanup );
        }
    }

    private static function cleanup_site( $delete_webp ) {
        if ( $delete_webp && class_exists( '\CacheParty\Images\WebP_Converter' ) ) {
            Images\WebP_Converter::delete_all_webp_files();
        }

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_cache_party_webp'" );

        delete_option( 'cache_party_modules' );
        delete_option( 'cache_party_images' );
        delete_option( 'cache_party_assets' );
        delete_option( 'cache_party_warmer' );
        delete_option( 'cache_party_cloudflare' );
        delete_option( 'cache_party_cleanup' );
        delete_transient( 'cache_party_cf_zone_id' );
        delete_option( 'cache_party_migrated_webp_meta' );
        delete_option( 'cache_party_last_warm' );
        $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_cache_party_page'" );
    }

    private function __clone() {}
}
