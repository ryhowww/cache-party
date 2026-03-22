<?php

namespace CacheParty\Images;

if ( ! defined( 'ABSPATH' ) ) exit;

class WebP_Admin {

    public function __construct() {
        add_action( 'add_meta_boxes_attachment', [ $this, 'register_metabox' ] );
        add_filter( 'manage_media_columns', [ $this, 'add_media_column' ] );
        add_action( 'manage_media_custom_column', [ $this, 'render_media_column' ], 10, 2 );

        add_action( 'wp_ajax_cache_party_convert_single', [ $this, 'ajax_convert_single' ] );
        add_action( 'wp_ajax_cache_party_convert_batch', [ $this, 'ajax_convert_batch' ] );
        add_action( 'wp_ajax_cache_party_convert_batch_auto', [ $this, 'ajax_convert_batch_auto' ] );
        add_action( 'wp_ajax_cache_party_bulk_stats', [ $this, 'ajax_bulk_stats' ] );

        add_action( 'cache_party_after_images_settings', [ $this, 'render_bulk_section' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    /* ---------------------------------------------------------------
     *  Attachment metabox
     * ------------------------------------------------------------- */

    public function register_metabox( $post ) {
        $mime = get_post_mime_type( $post->ID );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
            return;
        }

        add_meta_box(
            'cache_party_webp',
            'WebP Optimization',
            [ $this, 'render_metabox' ],
            'attachment',
            'side',
            'default'
        );
    }

    public function render_metabox( $post ) {
        $stats  = WebP_Converter::get_attachment_webp_stats( $post->ID );
        $engine = WebP_Converter::is_conversion_available();
        ?>
        <div id="cp-webp-metabox" data-attachment-id="<?php echo esc_attr( $post->ID ); ?>">
            <?php if ( ! $engine ) : ?>
                <p>No WebP library available (Imagick or GD required).</p>
            <?php elseif ( $stats['has_webp'] ) : ?>
                <table class="widefat striped" style="margin-bottom:12px;">
                    <thead>
                        <tr>
                            <th>Size</th>
                            <th>Original</th>
                            <th>WebP</th>
                            <th>Saved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $stats['sizes'] as $s ) : ?>
                            <tr>
                                <td><?php echo esc_html( $s['name'] ); ?></td>
                                <td><?php echo esc_html( size_format( $s['original_size'] ) ); ?></td>
                                <td><?php echo esc_html( size_format( $s['webp_size'] ) ); ?></td>
                                <td><?php echo esc_html( size_format( $s['savings'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th><?php echo esc_html( size_format( $stats['total_original'] ) ); ?></th>
                            <th><?php echo esc_html( size_format( $stats['total_webp'] ) ); ?></th>
                            <th>
                                <?php
                                echo esc_html( size_format( $stats['total_savings'] ) );
                                echo ' (' . esc_html( $stats['total_savings_pct'] ) . '%)';
                                ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
                <button type="button" class="button cp-convert-btn" data-action="regenerate">
                    Regenerate WebP
                </button>
            <?php else : ?>
                <p>No WebP versions exist for this image.</p>
                <button type="button" class="button button-primary cp-convert-btn" data-action="convert">
                    Convert to WebP
                </button>
            <?php endif; ?>
            <span class="spinner" style="float:none;margin-top:0;"></span>
            <div class="cp-metabox-result" style="margin-top:8px;"></div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------------
     *  Media library column
     * ------------------------------------------------------------- */

    public function add_media_column( $columns ) {
        $columns['cache_party_webp'] = 'WebP';
        return $columns;
    }

    public function render_media_column( $column, $post_id ) {
        if ( 'cache_party_webp' !== $column ) {
            return;
        }

        $mime = get_post_mime_type( $post_id );
        if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
            echo '&mdash;';
            return;
        }

        $stats = WebP_Converter::get_attachment_webp_stats( $post_id );

        if ( $stats['has_webp'] ) {
            printf(
                '<span style="color:#46b450;" title="%s">&#10003; -%s%%</span>',
                esc_attr( size_format( $stats['total_savings'] ) . ' saved' ),
                esc_html( $stats['total_savings_pct'] )
            );
        } else {
            printf(
                '<a href="#" class="cp-column-convert" data-id="%d">Convert</a>',
                esc_attr( $post_id )
            );
        }
    }

    /* ---------------------------------------------------------------
     *  AJAX handlers
     * ------------------------------------------------------------- */

    public function ajax_convert_single() {
        check_ajax_referer( 'cache_party_webp', 'nonce' );

        if ( ! current_user_can( 'upload_files' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! $attachment_id ) {
            wp_send_json_error( [ 'message' => 'Invalid attachment ID.' ] );
        }

        $force  = ! empty( $_POST['force'] );
        $result = WebP_Converter::convert_attachment( $attachment_id, $force );

        if ( $result['error'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        $stats = WebP_Converter::get_attachment_webp_stats( $attachment_id );
        wp_send_json_success( [
            'converted' => $result['converted'],
            'skipped'   => $result['skipped'],
            'stats'     => $stats,
        ] );
    }

    public function ajax_convert_batch() {
        check_ajax_referer( 'cache_party_webp', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : [];
        if ( empty( $ids ) ) {
            wp_send_json_error( [ 'message' => 'No attachment IDs provided.' ] );
        }

        $results = [];
        foreach ( $ids as $id ) {
            $r = WebP_Converter::convert_attachment( $id );
            $results[] = [
                'id'        => $id,
                'file'      => wp_basename( (string) get_attached_file( $id ) ),
                'converted' => $r['converted'],
                'skipped'   => $r['skipped'],
                'error'     => $r['error'],
            ];
        }

        wp_send_json_success( [
            'results'    => $results,
            'bulk_stats' => WebP_Converter::get_bulk_stats(),
        ] );
    }

    public function ajax_bulk_stats() {
        check_ajax_referer( 'cache_party_webp', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        wp_send_json_success( WebP_Converter::get_bulk_stats() );
    }

    public function ajax_convert_batch_auto() {
        check_ajax_referer( 'cache_party_webp', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
        }

        $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 10;
        $batch_size = min( $batch_size, 50 );

        $ids = WebP_Converter::get_unconverted_ids( $batch_size );
        if ( empty( $ids ) ) {
            wp_send_json_success( [
                'results'    => [],
                'bulk_stats' => WebP_Converter::get_bulk_stats(),
            ] );
            return;
        }

        $results = [];
        foreach ( $ids as $id ) {
            $id = (int) $id;
            $r  = WebP_Converter::convert_attachment( $id );
            $results[] = [
                'id'        => $id,
                'file'      => wp_basename( (string) get_attached_file( $id ) ),
                'converted' => $r['converted'],
                'skipped'   => $r['skipped'],
                'error'     => $r['error'],
            ];
        }

        wp_send_json_success( [
            'results'    => $results,
            'bulk_stats' => WebP_Converter::get_bulk_stats(),
        ] );
    }

    /* ---------------------------------------------------------------
     *  Bulk conversion section (renders on Images settings tab)
     * ------------------------------------------------------------- */

    public function render_bulk_section() {
        $stats  = WebP_Converter::get_bulk_stats();
        $engine = WebP_Converter::is_conversion_available();
        ?>
        <h2>Bulk Conversion</h2>

        <?php if ( ! $engine ) : ?>
            <div class="notice notice-error inline" style="margin-bottom:1em;">
                <p>No WebP conversion library available. Install Imagick or GD with WebP support.</p>
            </div>
            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped" style="max-width:400px;">
            <tr>
                <td>Total JPEG + PNG images</td>
                <td id="cp-stat-total"><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong></td>
            </tr>
            <tr>
                <td>Already converted</td>
                <td id="cp-stat-converted"><strong><?php echo esc_html( number_format_i18n( $stats['converted'] ) ); ?></strong></td>
            </tr>
            <tr>
                <td>Need conversion</td>
                <td id="cp-stat-unconverted"><strong><?php echo esc_html( number_format_i18n( $stats['unconverted'] ) ); ?></strong></td>
            </tr>
        </table>

        <div style="margin-top:16px;">
            <?php if ( $stats['unconverted'] > 0 ) : ?>
                <button type="button" class="button button-primary" id="cp-bulk-start">
                    Convert All
                </button>
            <?php else : ?>
                <button type="button" class="button button-primary" disabled>
                    All images converted
                </button>
            <?php endif; ?>
            <button type="button" class="button" id="cp-bulk-stop" style="display:none;">
                Stop
            </button>
            <span class="spinner" id="cp-bulk-spinner" style="float:none;margin-top:0;"></span>
        </div>

        <div id="cp-bulk-progress" style="display:none;max-width:720px;margin-top:20px;">
            <h3 style="margin-top:0;" id="cp-progress-heading">Converting...</h3>
            <div style="background:#e0e0e0;border-radius:3px;height:24px;overflow:hidden;">
                <div id="cp-progress-bar" style="background:#0073aa;height:100%;width:0;transition:width .3s;border-radius:3px;"></div>
            </div>
            <p id="cp-progress-text" style="margin-top:8px;"></p>
        </div>

        <div id="cp-bulk-log" style="display:none;max-width:720px;margin-top:20px;">
            <h3 style="margin-top:0;">Log</h3>
            <div id="cp-log-entries" style="max-height:300px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.6;"></div>
        </div>

        <input type="hidden" id="cp-nonce" value="<?php echo esc_attr( wp_create_nonce( 'cache_party_webp' ) ); ?>">
        <input type="hidden" id="cp-batch-size" value="10">
        <?php
    }

    /* ---------------------------------------------------------------
     *  Enqueue scripts
     * ------------------------------------------------------------- */

    public function enqueue_scripts( $hook ) {
        $screens = [
            'post.php',
            'upload.php',
            'toplevel_page_cache-party',
        ];

        if ( ! in_array( $hook, $screens, true ) ) {
            return;
        }

        wp_enqueue_script(
            'cache-party-admin',
            CACHE_PARTY_URL . 'admin/js/settings.js',
            [ 'jquery' ],
            filemtime( CACHE_PARTY_PATH . 'admin/js/settings.js' ),
            true
        );

        wp_enqueue_style(
            'cache-party-admin',
            CACHE_PARTY_URL . 'admin/css/settings.css',
            [],
            filemtime( CACHE_PARTY_PATH . 'admin/css/settings.css' )
        );

        wp_localize_script( 'cache-party-admin', 'cachePartyWebP', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cache_party_webp' ),
            'i18n'    => [
                'converting'  => 'Converting...',
                'converted'   => 'Converted!',
                'regenerated' => 'Regenerated!',
                'error'       => 'Error:',
                'saved'       => 'saved',
                'done'        => 'Done!',
                'stopped'     => 'Stopped.',
                'progressOf'  => 'Converting %1$s of %2$s...',
            ],
        ] );
    }
}
