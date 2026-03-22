<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-autoloader.php';

$cleanup = (bool) get_option( 'cache_party_cleanup', false );

if ( is_multisite() ) {
    $sites = get_sites( [ 'fields' => 'ids' ] );
    foreach ( $sites as $site_id ) {
        switch_to_blog( (int) $site_id );
        cache_party_uninstall_site( $cleanup );
        restore_current_blog();
    }
} else {
    cache_party_uninstall_site( $cleanup );
}

function cache_party_uninstall_site( $delete_webp ) {
    global $wpdb;

    if ( $delete_webp ) {
        \CacheParty\Images\WebP_Converter::delete_all_webp_files();
    }

    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_cache_party_webp'" );

    delete_option( 'cache_party_modules' );
    delete_option( 'cache_party_images' );
    delete_option( 'cache_party_assets' );
    delete_option( 'cache_party_warmer' );
    delete_option( 'cache_party_cleanup' );
    delete_option( 'cache_party_migrated_webp_meta' );
    delete_option( 'cache_party_last_warm' );

    // Clean per-page meta.
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_cache_party_page'" );
}
