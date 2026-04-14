<?php

namespace CacheParty;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Migration from Autoptimize Custom and ImageOptimizer.ai.
 *
 * WP-CLI:
 *   wp cache-party migrate --from=autoptimize-custom,imageoptimizerai
 */
class Migration {

    /**
     * Run version-gated upgrade routines. Called on every plugins_loaded;
     * the stored version guards keep it a no-op once everything has run.
     *
     * Fresh installs are detected by the absence of any Cache Party option
     * and skipped — they get current defaults without being "upgraded".
     */
    public static function maybe_upgrade() {
        $installed = get_option( 'cache_party_installed_version', null );
        $current   = defined( 'CACHE_PARTY_VERSION' ) ? CACHE_PARTY_VERSION : '0';

        if ( $installed === null ) {
            $has_existing_data =
                get_option( 'cache_party_modules', null ) !== null
                || get_option( 'cache_party_assets', null ) !== null
                || get_option( 'cache_party_images', null ) !== null;

            if ( $has_existing_data ) {
                self::upgrade_to_1_4_0();
            }

            update_option( 'cache_party_installed_version', $current );
            return;
        }

        if ( version_compare( $installed, '1.4.0', '<' ) ) {
            self::upgrade_to_1_4_0();
        }

        if ( $installed !== $current ) {
            update_option( 'cache_party_installed_version', $current );
        }
    }

    /**
     * The "Enable Asset Optimizer" master toggle on the Assets tab was
     * removed in 1.4.0. Asset_Optimizer now always loads; each feature has
     * its own sub-toggle. To preserve experienced behavior on sites where
     * the master was off (nothing was running), force the sub-toggles off
     * so the features don't silently turn on after update. Sites with the
     * master on keep their sub-toggle state unchanged.
     */
    private static function upgrade_to_1_4_0() {
        $modules = (array) get_option( 'cache_party_modules', [ 'images' ] );
        if ( in_array( 'assets', $modules, true ) ) {
            return;
        }

        $assets = (array) get_option( 'cache_party_assets', [] );
        foreach ( [ 'css_aggregate_enabled', 'css_defer_enabled', 'js_delay_enabled', 'iframe_lazy_enabled' ] as $key ) {
            $assets[ $key ] = false;
        }
        update_option( 'cache_party_assets', $assets );

        $modules[] = 'assets';
        update_option( 'cache_party_modules', array_values( array_unique( $modules ) ) );
    }

    /**
     * Migrate settings from ImageOptimizer.ai.
     *
     * @return array Summary of migrated settings.
     */
    public static function from_imageoptimizerai() {
        $migrated = [];

        $auto_alt    = get_option( 'imageoptimizerai_boolean', null );
        $webp_on     = get_option( 'imageoptimizerai_webp_enabled', null );
        $webp_clean  = get_option( 'imageoptimizerai_webp_cleanup', null );

        if ( $auto_alt !== null || $webp_on !== null ) {
            $current = wp_parse_args( get_option( 'cache_party_images', [] ), Settings::image_defaults() );

            if ( $auto_alt !== null ) {
                $current['auto_alt_enabled'] = (bool) $auto_alt;
                $migrated[] = 'auto_alt_enabled = ' . ( $current['auto_alt_enabled'] ? 'true' : 'false' );
            }

            if ( $webp_on !== null ) {
                $current['webp_enabled'] = (bool) $webp_on;
                $migrated[] = 'webp_enabled = ' . ( $current['webp_enabled'] ? 'true' : 'false' );
            }

            update_option( 'cache_party_images', $current );
        }

        if ( $webp_clean !== null ) {
            update_option( 'cache_party_cleanup', (bool) $webp_clean );
            $migrated[] = 'cleanup = ' . ( $webp_clean ? 'true' : 'false' );
        }

        // WebP meta migration is handled automatically by WebP_Converter::maybe_migrate_meta().
        $migrated[] = 'WebP post meta migration will run automatically on next admin page load.';

        return $migrated;
    }

    /**
     * Migrate from Autoptimize Custom.
     *
     * AOC stores settings as filter hooks in code, not in the database.
     * We can only detect if the plugin is active and suggest manual review.
     *
     * @return array Summary.
     */
    public static function from_autoptimize_custom() {
        $migrated = [];

        // Check if AOC is active.
        $active_plugins = get_option( 'active_plugins', [] );
        $aoc_active     = false;
        foreach ( $active_plugins as $plugin ) {
            if ( strpos( $plugin, 'autoptimize-custom' ) !== false ) {
                $aoc_active = true;
                break;
            }
        }

        if ( $aoc_active ) {
            $migrated[] = 'Autoptimize Custom is active.';
            $migrated[] = 'AOC stores config as filter hooks in PHP files, not database options.';
            $migrated[] = 'Enable the Assets module in Cache Party, then deactivate AOC.';
            $migrated[] = 'Copy any custom filter hook values to the Assets settings tab.';
        } else {
            $migrated[] = 'Autoptimize Custom is not active — nothing to migrate.';
        }

        // Enable the assets module.
        $modules = get_option( 'cache_party_modules', [ 'images' ] );
        if ( ! in_array( 'assets', $modules, true ) ) {
            $modules[] = 'assets';
            update_option( 'cache_party_modules', $modules );
            $migrated[] = 'Assets module enabled.';
        }

        return $migrated;
    }

    /**
     * WP-CLI: Register migration and preset commands.
     * Called from Plugin boot when WP_CLI is defined.
     *
     * Uses standalone closures registered as individual subcommands
     * to avoid conflicts with class-based registrations from other modules.
     */
    public static function register_cli() {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }

        \WP_CLI::add_command( 'cache-party migrate', function( $args, $assoc_args ) {
            $from = $assoc_args['from'] ?? '';
            if ( ! $from ) {
                \WP_CLI::error( 'Please provide --from (e.g., --from=autoptimize-custom,imageoptimizerai).' );
                return;
            }

            $sources = array_map( 'trim', explode( ',', $from ) );

            foreach ( $sources as $source ) {
                \WP_CLI::log( '' );
                \WP_CLI::log( "Migrating from: {$source}" );
                \WP_CLI::log( str_repeat( '-', 40 ) );

                if ( $source === 'imageoptimizerai' ) {
                    $logs = Migration::from_imageoptimizerai();
                } elseif ( $source === 'autoptimize-custom' ) {
                    $logs = Migration::from_autoptimize_custom();
                } else {
                    $logs = [ "Unknown source: {$source}. Supported: imageoptimizerai, autoptimize-custom." ];
                }

                foreach ( $logs as $log ) {
                    \WP_CLI::log( '  ' . $log );
                }
            }

            \WP_CLI::log( '' );
            \WP_CLI::success( 'Migration complete.' );
        } );

        \WP_CLI::add_command( 'cache-party preset-export', function() {
            $data = Preset_Manager::export();
            \WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
        } );

        \WP_CLI::add_command( 'cache-party preset-import', function( $args ) {
            if ( empty( $args[0] ) ) {
                \WP_CLI::error( 'Please provide a JSON file path.' );
                return;
            }

            $file = $args[0];
            if ( ! file_exists( $file ) ) {
                \WP_CLI::error( "File not found: {$file}" );
                return;
            }

            $json = file_get_contents( $file );
            $data = json_decode( $json, true );
            if ( ! $data ) {
                \WP_CLI::error( 'Invalid JSON.' );
                return;
            }

            $count = Preset_Manager::import( $data );
            \WP_CLI::success( "Imported {$count} settings from {$file}." );
        } );

        \WP_CLI::add_command( 'cache-party preset-apply', function( $args ) {
            $name = $args[0] ?? '';
            if ( ! $name ) {
                \WP_CLI::error( 'Please provide a preset name (e.g., home-services, agency-site).' );

                $available = Preset_Manager::list_bundled();
                if ( ! empty( $available ) ) {
                    \WP_CLI::log( "\nAvailable presets:" );
                    foreach ( $available as $p ) {
                        \WP_CLI::log( sprintf( '  %-20s %s', $p['slug'], $p['description'] ) );
                    }
                }
                return;
            }

            $data = Preset_Manager::get_bundled_preset( $name );
            if ( ! $data ) {
                \WP_CLI::error( "Preset \"{$name}\" not found." );
                return;
            }

            $count = Preset_Manager::import( $data );
            \WP_CLI::success( "Applied preset \"{$name}\" ({$count} settings)." );
        } );

        \WP_CLI::add_command( 'cache-party preset-list', function() {
            $presets = Preset_Manager::list_bundled();
            if ( empty( $presets ) ) {
                \WP_CLI::log( 'No bundled presets found.' );
                return;
            }

            \WP_CLI::log( '' );
            \WP_CLI::log( 'Available Presets' );
            \WP_CLI::log( str_repeat( '-', 50 ) );
            foreach ( $presets as $p ) {
                \WP_CLI::log( sprintf( '  %-20s %s', $p['slug'], $p['description'] ) );
            }
            \WP_CLI::log( '' );
        } );
    }
}
