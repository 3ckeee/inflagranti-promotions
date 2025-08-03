<?php
/**
 * Plugin Name:     InFlagranti Promotions
 * Description:     Newsletter subscriber discounts and digital wallet passes.
 * Version:         0.9.1
 * Author:          Erik Kokinda
 * Text Domain:     inflagranti-promotions
 * Domain Path:     /languages
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Autoload Composer dependencies (e.g. PHP-PKPass)
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Allow certificate file uploads - IMPROVED VERSION
add_filter('upload_mimes', function($mimes){
    $mimes['p12'] = 'application/x-pkcs12';
    $mimes['pem'] = 'application/x-pem-file';
    $mimes['cer'] = 'application/x-x509-ca-cert';
    $mimes['crt'] = 'application/x-x509-ca-cert';
    return $mimes;
});

// Additional security bypass for certificate files
add_filter('wp_check_filetype_and_ext', function($data, $file, $filename, $mimes) {
    $filetype = wp_check_filetype($filename, $mimes);
    
    if ($filetype['ext'] === 'pem' || $filetype['ext'] === 'cer' || $filetype['ext'] === 'p12') {
        $data['ext'] = $filetype['ext'];
        $data['type'] = $filetype['type'];
    }
    
    return $data;
}, 10, 4);

// Override file extension validation for admin users
add_filter('wp_handle_upload_prefilter', function($file) {
    if (current_user_can('manage_options')) {
        $allowed_extensions = ['pem', 'cer', 'p12', 'crt'];
        $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        if (in_array(strtolower($file_extension), $allowed_extensions)) {
            // Force acceptance of certificate files
            $file['type'] = 'application/x-x509-ca-cert';
        }
    }
    return $file;
});

// Load translations
add_action( 'plugins_loaded', function() {
    load_plugin_textdomain(
        'inflagranti-promotions',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
} );

// Activation: create subscribers table and flush rewrite rules
register_activation_hook( __FILE__, function() {
    global $wpdb;
    $table   = $wpdb->prefix . 'ifl_subscribers';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id              BIGINT AUTO_INCREMENT PRIMARY KEY,
        email           VARCHAR(200) NOT NULL UNIQUE,
        name            VARCHAR(100) DEFAULT NULL,
        phone           VARCHAR(50)  DEFAULT NULL,
        discount_pct    TINYINT      NOT NULL DEFAULT 5,
        pass_serial     VARCHAR(50)  NOT NULL UNIQUE,
        pass_downloaded TINYINT(1)   NOT NULL DEFAULT 0,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    flush_rewrite_rules();
} );

// Add these lines to enable iOS app integration
  add_action('wp_ajax_ifl_check_subscriber', 'ifl_handle_check_subscriber');
  add_action('wp_ajax_nopriv_ifl_check_subscriber', 'ifl_handle_check_subscriber');

function ifl_handle_check_subscriber() {
      global $wpdb;

      $email = sanitize_email($_POST['email']);

      if (empty($email)) {
          wp_send_json(array('success' => false, 'message' => 'Email required'));
          return;
      }

      $table_name = $wpdb->prefix . 'ifl_subscribers';
      $subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE email = %s", $email));

      if ($subscriber) {
          wp_send_json(array(
              'success' => true,
              'subscriber' => array(
                  'id' => intval($subscriber->id),
                  'email' => $subscriber->email,
                  'name' => $subscriber->name,
                  'phone' => $subscriber->phone,
                  'discount_percentage' => intval($subscriber->discount_pct), // ← FIXED THIS
                  'pass_serial' => $subscriber->pass_serial,
                  'pass_downloaded' => (bool)$subscriber->pass_downloaded,
                  'created_at' => $subscriber->created_at
              )
          ));
      } else {
          wp_send_json(array('success' => false, 'message' => 'Subscriber not found'));
      }
  }




// Deactivation: flush rewrite rules
register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );

// Uninstall: drop table
function ifl_uninstall() {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ifl_subscribers" );
}
register_uninstall_hook( __FILE__, 'ifl_uninstall' );

// Include core classes
require_once __DIR__ . '/includes/class-ifl-settings.php';
require_once __DIR__ . '/includes/class-ifl-subscribers.php';
require_once __DIR__ . '/includes/class-ifl-passes.php';
require_once __DIR__ . '/includes/class-ifl-admin.php';
require_once __DIR__ . '/includes/class-ifl-pass-validator.php';
require_once __DIR__ . '/includes/class-ifl-promo-codes.php';  // ← ADD THIS LINE

// Initialize functionality
IFL\Promotions\Subscribers::init();
IFL\Promotions\Passes::init();
IFL\Promotions\PassValidator::init();
IFL\Promotions\PromoCodes::init();  // ← ADD THIS LINE
if ( is_admin() ) {
    IFL\Promotions\Admin::init();
    IFL\Promotions\Settings::init();
}