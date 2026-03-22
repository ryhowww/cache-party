<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * JSON preset export/import for Cache Party settings.
 *
 * WP-CLI:
 *   wp cache-party preset export > config.json
 *   wp cache-party preset import config.json
 *   wp cache-party preset apply home-services
 */
class Preset_Manager {

    /**
     * Export all settings as a JSON array.
     *
     * @return array
     */
    public static function export() {
        return [
            'name'                => get_bloginfo( 'name' ) . ' — Cache Party Export',
            'version'             => CACHE_PARTY_VERSION,
            'exported_at'         => current_time( 'mysql' ),
            'cache_party_modules' => get_option( 'cache_party_modules', [ 'images' ] ),
            'cache_party_images'  => get_option( 'cache_party_images', Settings::image_defaults() ),
            'cache_party_assets'  => get_option( 'cache_party_assets', Settings::asset_defaults() ),
            'cache_party_warmer'  => get_option( 'cache_party_warmer', [] ),
            'cache_party_cleanup' => get_option( 'cache_party_cleanup', false ),
        ];
    }

    /**
     * Import settings from a JSON array.
     *
     * @param array $data Decoded JSON preset.
     * @return int Number of options updated.
     */
    public static function import( $data ) {
        $count    = 0;
        $settings = new Settings();

        if ( isset( $data['cache_party_modules'] ) ) {
            $clean = $settings->sanitize_modules( $data['cache_party_modules'] );
            update_option( 'cache_party_modules', $clean );
            $count++;
        }

        if ( isset( $data['cache_party_images'] ) ) {
            $clean = $settings->sanitize_images( $data['cache_party_images'] );
            update_option( 'cache_party_images', $clean );
            $count++;
        }

        if ( isset( $data['cache_party_assets'] ) ) {
            $clean = $settings->sanitize_assets( $data['cache_party_assets'] );
            update_option( 'cache_party_assets', $clean );
            $count++;
        }

        if ( isset( $data['cache_party_warmer'] ) ) {
            update_option( 'cache_party_warmer', $data['cache_party_warmer'] );
            $count++;
        }

        if ( isset( $data['cache_party_cleanup'] ) ) {
            update_option( 'cache_party_cleanup', (bool) $data['cache_party_cleanup'] );
            $count++;
        }

        return $count;
    }

    /**
     * Apply a bundled preset by name.
     *
     * @param string $name Preset name (e.g., 'home-services', 'agency-site').
     * @return array|false Preset data or false if not found.
     */
    public static function get_bundled_preset( $name ) {
        $file = CACHE_PARTY_PATH . 'presets/' . sanitize_file_name( $name ) . '.json';
        if ( ! file_exists( $file ) ) {
            return false;
        }

        $json = file_get_contents( $file );
        return json_decode( $json, true );
    }

    /**
     * List available bundled presets.
     *
     * @return array [ 'name' => '...', 'description' => '...' ]
     */
    public static function list_bundled() {
        $presets = [];
        $dir     = CACHE_PARTY_PATH . 'presets/';

        if ( ! is_dir( $dir ) ) {
            return $presets;
        }

        foreach ( glob( $dir . '*.json' ) as $file ) {
            $data = json_decode( file_get_contents( $file ), true );
            if ( $data ) {
                $presets[] = [
                    'slug'        => pathinfo( $file, PATHINFO_FILENAME ),
                    'name'        => $data['name'] ?? pathinfo( $file, PATHINFO_FILENAME ),
                    'description' => $data['description'] ?? '',
                ];
            }
        }

        return $presets;
    }
}
