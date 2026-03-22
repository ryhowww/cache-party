<?php

if ( ! defined( 'ABSPATH' ) ) exit;

spl_autoload_register( function ( $class_name ) {
    $classes = [
        'CacheParty\\Plugin'                    => __DIR__ . '/class-plugin.php',
        'CacheParty\\Module_Loader'             => __DIR__ . '/class-module-loader.php',
        'CacheParty\\Settings'                  => __DIR__ . '/class-settings.php',
        'CacheParty\\Images\\Image_Optimizer'   => __DIR__ . '/images/class-image-optimizer.php',
        'CacheParty\\Images\\WebP_Converter'    => __DIR__ . '/images/class-webp-converter.php',
        'CacheParty\\Images\\Picture_Wrapper'   => __DIR__ . '/images/class-picture-wrapper.php',
        'CacheParty\\Images\\Lazy_Loader'       => __DIR__ . '/images/class-lazy-loader.php',
        'CacheParty\\Images\\Auto_Alt'          => __DIR__ . '/images/class-auto-alt.php',
        'CacheParty\\Images\\WebP_Admin'        => __DIR__ . '/images/class-webp-admin.php',
        'CacheParty\\Images\\CLI_Commands'      => __DIR__ . '/images/class-cli-commands.php',
        'CacheParty\\Assets\\Asset_Optimizer'   => __DIR__ . '/assets/class-asset-optimizer.php',
        'CacheParty\\Assets\\CSS_Deferral'      => __DIR__ . '/assets/class-css-deferral.php',
        'CacheParty\\Assets\\JS_Delay'          => __DIR__ . '/assets/class-js-delay.php',
        'CacheParty\\Assets\\Resource_Hints'    => __DIR__ . '/assets/class-resource-hints.php',
        'CacheParty\\Assets\\AO_Bridge'         => __DIR__ . '/assets/class-ao-bridge.php',
        'CacheParty\\Assets\\Iframe_Lazy'       => __DIR__ . '/assets/class-iframe-lazy.php',
        'CacheParty\\Assets\\Critical_CSS'      => __DIR__ . '/assets/class-critical-css.php',
        'CacheParty\\Debug'                     => __DIR__ . '/class-debug.php',
        'CacheParty\\Warmer\\Warmer_Client'     => __DIR__ . '/warmer/class-warmer-client.php',
        'CacheParty\\Warmer\\Purge_Hooks'       => __DIR__ . '/warmer/class-purge-hooks.php',
        'CacheParty\\Preset_Manager'            => __DIR__ . '/class-preset-manager.php',
        'CacheParty\\Migration'                 => __DIR__ . '/class-migration.php',
        'CacheParty\\Meta_Box'                  => __DIR__ . '/class-meta-box.php',
        'CacheParty\\Assets\\CLI_Assets'        => __DIR__ . '/assets/class-cli-assets.php',
        'CacheParty\\Cloudflare'                => __DIR__ . '/class-cloudflare.php',
    ];

    if ( isset( $classes[ $class_name ] ) ) {
        require_once $classes[ $class_name ];
    }
} );
