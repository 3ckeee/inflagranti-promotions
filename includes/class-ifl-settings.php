<?php
namespace IFL\Promotions;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {
    const PAGE_SLUG = 'ifl_promotions_settings';

    public static function init() {
        // Ensure Promotions menu exists before adding Settings submenu
        add_action( 'admin_menu',    [ __CLASS__, 'add_menu' ], 20 );
        add_action( 'admin_init',    [ __CLASS__, 'register_settings' ] );
    }

    public static function add_menu() {
        add_submenu_page(
            'ifl_promotions',
            __( 'Settings', 'inflagranti-promotions' ),
            __( 'Settings', 'inflagranti-promotions' ),
            'manage_options',
            self::PAGE_SLUG,
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function register_settings() {
        register_setting(
            'ifl_promotions_settings',
            'ifl_promotions_settings',
            [ __CLASS__, 'sanitize_settings' ]
        );

        add_settings_section(
            'ifl_promotions_main',
            __( 'PassKit Configuration', 'inflagranti-promotions' ),
            function() {
                echo '<p>' . esc_html__( 'Upload your certificates and set defaults.', 'inflagranti-promotions' ) . '</p>';
            },
            self::PAGE_SLUG
        );

        $fields = [
            'p12_file'         => __( 'P12 Certificate',      'inflagranti-promotions' ),
            'wwdr_pem'         => __( 'WWDR Certificate',     'inflagranti-promotions' ),
            'p12_password'     => __( 'P12 Password',         'inflagranti-promotions' ),
            'team_id'          => __( 'Apple Team ID',        'inflagranti-promotions' ),
            'pass_type_id'     => __( 'Pass Type ID',         'inflagranti-promotions' ),
            'default_discount' => __( 'Default Discount (%)', 'inflagranti-promotions' ),
        ];

        foreach ( $fields as $id => $label ) {
            add_settings_field(
                $id,
                $label,
                [ __CLASS__, 'render_field' ],
                self::PAGE_SLUG,
                'ifl_promotions_main',
                [ 'id' => $id ]
            );
        }
    }

    public static function sanitize_settings( $input ) {
        // Debug logging: inspect $_FILES and incoming $input
        error_log( "IFL SETTINGS • \$_FILES:\n" . print_r($_FILES, true) );
        error_log( "IFL SETTINGS • \$input BEFORE sanitizing:\n" . print_r($input, true) );

        $opts = get_option( 'ifl_promotions_settings', [] );

// Handle P12 certificate upload
if ( ! empty( $_FILES['ifl_promotions_settings']['name']['p12_file'] ) ) {
    $file   = self::normalize_file( 'p12_file' );
    $upload = wp_handle_upload( $file, [ 'test_form' => false ] );

    // DEBUG: log the entire upload response
    error_log( 'P12 upload response: ' . print_r( $upload, true ) );

    if ( ! empty( $upload['file'] ) ) {
        $input['p12_file'] = $upload['file'];
    } elseif ( ! empty( $upload['error'] ) ) {
        add_settings_error(
            'ifl_promotions_settings',
            'p12_upload_error',
            __( 'P12 upload failed: ', 'inflagranti-promotions' ) . esc_html( $upload['error'] )
        );
    }
}

// Handle WWDR certificate upload
if ( ! empty( $_FILES['ifl_promotions_settings']['name']['wwdr_pem'] ) ) {
    $file   = self::normalize_file( 'wwdr_pem' );
    $upload = wp_handle_upload( $file, [ 'test_form' => false ] );

    // DEBUG: log the entire upload response
    error_log( 'WWDR upload response: ' . print_r( $upload, true ) );

    if ( ! empty( $upload['file'] ) ) {
        $input['wwdr_pem'] = $upload['file'];
    } elseif ( ! empty( $upload['error'] ) ) {
        add_settings_error(
            'ifl_promotions_settings',
            'wwdr_upload_error',
            __( 'WWDR upload failed: ', 'inflagranti-promotions' ) . esc_html( $upload['error'] )
        );
    }
}

        // Sanitize text and numeric fields
        $input['p12_password']    = sanitize_text_field($input['p12_password'] ?? '');
        $input['team_id']         = sanitize_text_field($input['team_id'] ?? '');
        $input['pass_type_id']    = sanitize_text_field($input['pass_type_id'] ?? '');
        $input['default_discount']= intval($input['default_discount'] ?? 5);

        return $input;
    }

    private static function normalize_file($key) {
        return [
            'name'     => $_FILES['ifl_promotions_settings']['name'][$key],
            'type'     => $_FILES['ifl_promotions_settings']['type'][$key],
            'tmp_name' => $_FILES['ifl_promotions_settings']['tmp_name'][$key],
            'error'    => $_FILES['ifl_promotions_settings']['error'][$key],
            'size'     => $_FILES['ifl_promotions_settings']['size'][$key],
        ];
    }

    public static function render_field($args) {
        $id   = esc_attr($args['id']);
        $opts = get_option('ifl_promotions_settings', []);

        if ( in_array($id, ['p12_file', 'wwdr_pem'], true) ) {
            echo "<input type='file' name='ifl_promotions_settings[{$id}]' />";
            if ( ! empty($opts[$id]) ) {
                echo '<p><code>' . esc_html(basename($opts[$id])) . '</code></p>';
            }
        } else {
            $value = esc_attr($opts[$id] ?? '');
            echo "<input type='text' name='ifl_promotions_settings[{$id}]' id='{$id}' value='{$value}' class='regular-text' />";
        }
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Promotions Settings', 'inflagranti-promotions'); ?></h1>
            <form method="post" action="options.php" enctype="multipart/form-data">
                <?php
                settings_fields('ifl_promotions_settings');
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}

// Initialize the Settings page
Settings::init();
