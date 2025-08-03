<?php
namespace IFL\Promotions;
if ( ! defined( 'ABSPATH' ) ) exit;

use PKPass\PKPass;

class Passes {
    public static function init() {
        // Handle pass download via AJAX endpoint
        add_action( 'wp_ajax_ifl_serve_pass',       [ __CLASS__, 'serve_pass' ] );
        add_action( 'wp_ajax_nopriv_ifl_serve_pass',[ __CLASS__, 'serve_pass' ] );
    }

    /**
     * Serve the .pkpass when admin-ajax.php?action=ifl_serve_pass&serial=... is called.
     */
    public static function serve_pass() {
        // Ensure serial parameter
        if ( empty( $_GET['serial'] ) ) {
            wp_die( esc_html__( 'Missing pass serial.', 'inflagranti-promotions' ), 400 );
        }
        $serial = sanitize_text_field( wp_unslash( $_GET['serial'] ) );

        // Get subscriber data first
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_subscribers';
        $subscriber = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE pass_serial = %s", $serial ) );
        
        if ( ! $subscriber ) {
            wp_die( esc_html__( 'Invalid pass serial.', 'inflagranti-promotions' ), 404 );
        }

        // Load PassKit settings
        $opts = get_option( 'ifl_promotions_settings', [] );
        $p12_path     = $opts['p12_file']         ?? '';
        $wwdr_path    = $opts['wwdr_pem']         ?? '';
        $p12_password = sanitize_text_field( $opts['p12_password'] ?? '' );
        $team_id      = sanitize_text_field( $opts['team_id']      ?? '' );
        $pass_type_id = sanitize_text_field( $opts['pass_type_id'] ?? '' );

        // Validate settings
        if ( ! $p12_path || ! $wwdr_path || ! $p12_password || ! $team_id || ! $pass_type_id ) {
            wp_die( esc_html__( 'PassKit settings incomplete.', 'inflagranti-promotions' ), 507 );
        }
        if ( ! file_exists( $p12_path ) || ! file_exists( $wwdr_path ) ) {
            wp_die( esc_html__( 'Certificate files missing.', 'inflagranti-promotions' ), 507 );
        }

        // Check required image files
        $assets_dir = dirname( __DIR__ ) . '/assets/';
        if ( ! file_exists( $assets_dir . 'icon.png' ) ) {
            wp_die( esc_html__( 'Required icon.png file missing.', 'inflagranti-promotions' ), 507 );
        }

        // Prepare pass data with proper structure
        $pass_data = [
            'description'        => 'InFlagranti VIP Membership',
            'formatVersion'      => 1,
            'organizationName'   => get_bloginfo( 'name' ),
            'passTypeIdentifier' => $pass_type_id,
            'serialNumber'       => $serial,
            'teamIdentifier'     => $team_id,
            'foregroundColor'    => 'rgb(255, 255, 255)',
            'backgroundColor'    => 'rgb(0, 0, 0)',
            'labelColor'         => 'rgb(184, 167, 93)',
            'storeCard'          => [
                'headerFields' => [
                    [
                        'key'   => 'member',
                        'label' => 'VIP Člen',
                        'value' => $subscriber->name ?: 'Člen'
                    ]
                ],
                'primaryFields' => [
                    [
                        'key'   => 'status',
                        'label' => 'Status',
                        'value' => 'VIP ČLEN'
                    ]
                ],
                'secondaryFields' => [
                    [
                        'key'   => 'benefits',
                        'label' => 'Výhody',
                        'value' => 'Prémiové Výhody'
                    ],
                    [
                        'key'   => 'joined',
                        'label' => 'Člen od',
                        'value' => date('m/Y', strtotime($subscriber->created_at))
                    ]
                ],
                'auxiliaryFields' => [
                    [
                        'key'   => 'serial',
                        'label' => 'Číslo karty',
                        'value' => substr($serial, -6) // Show last 6 digits
                    ]
                ],
                'backFields' => [
                    [
                        'key'   => 'terms',
                        'label' => 'VIP Výhody',
                        'value' => "• Exkluzívne zľavy a ponuky\n• Prioritné služby\n• Špeciálne akcie len pre VIP členov\n• Platí vo všetkých predajniach InFlagranti\n• Zľava sa určuje pri nákupe"
                    ],
                    [
                        'key'   => 'contact',
                        'label' => 'Kontakt',
                        'value' => "inflagranti.sk\ninfo@inflagranti.sk\n\nPre VIP podporu a informácie"
                    ]
                ]
            ],
            'barcode' => [
                'message'         => $serial,
                'format'          => 'PKBarcodeFormatQR',
                'messageEncoding' => 'iso-8859-1',
            ],
        ];

        try {
            $pass = new PKPass( $p12_path, $p12_password );
            $pass->setWwdrCertificatePath( $wwdr_path );
            $pass->setData( $pass_data );

            // Add required image files
            $pass->addFile( $assets_dir . 'icon.png' );
            $pass->addFile( $assets_dir . 'logo.png' );
            
            // Add @2x versions if they exist
            if ( file_exists( $assets_dir . 'icon@2x.png' ) ) {
                $pass->addFile( $assets_dir . 'icon@2x.png' );
            }
            if ( file_exists( $assets_dir . 'logo@2x.png' ) ) {
                $pass->addFile( $assets_dir . 'logo@2x.png' );
            }

            $pkpass_data = $pass->create();

            // Mark pass as downloaded
            $wpdb->update(
                $table,
                [ 'pass_downloaded' => 1 ],
                [ 'id' => $subscriber->id ],
                [ '%d' ],
                [ '%d' ]
            );

            // Stream the pass with proper headers
            header( 'Content-Type: application/vnd.apple.pkpass' );
            header( 'Content-Disposition: attachment; filename="inflagranti-' . $serial . '.pkpass"' );
            header( 'Content-Length: ' . strlen( $pkpass_data ) );
            header( 'Cache-Control: no-cache, no-store, must-revalidate' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
            
            echo $pkpass_data;
            exit;
        } catch ( \Exception $e ) {
            error_log( 'PKPass generation error: ' . $e->getMessage() );
            wp_die( esc_html__( 'Error generating pass: ', 'inflagranti-promotions' ) . esc_html( $e->getMessage() ), 507 );
        }
    }
}

Passes::init();