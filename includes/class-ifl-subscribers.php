<?php
namespace IFL\Promotions;
if ( ! defined( 'ABSPATH' ) ) exit;

class Subscribers {
    public static function init() {
        // Shortcode and AJAX hooks
        add_shortcode( 'ifl_subscribe_form',          [ __CLASS__, 'render_form' ] );
        add_action(    'wp_enqueue_scripts',          [ __CLASS__, 'enqueue_scripts' ] );
        add_action(    'wp_ajax_ifl_subscribe',       [ __CLASS__, 'handle_subscription' ] );
        add_action(    'wp_ajax_nopriv_ifl_subscribe',[ __CLASS__, 'handle_subscription' ] );
    }

    public static function enqueue_scripts() {
        wp_enqueue_script(
            'ifl-subscribers',
            plugin_dir_url( __FILE__ ) . '../js/ifl-subscribers.js',
            [ 'jquery' ],
            '0.9.0',
            true
        );
        wp_localize_script( 'ifl-subscribers', 'iflSubscribers', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ifl_subscribe_nonce' ),
        ]);

        wp_enqueue_style(
            'ifl-google-font-archivo',
            'https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600&display=swap'
        );

        wp_enqueue_style(
            'ifl-subscribers-style',
            plugin_dir_url( __FILE__ ) . '../public/css/inflagranti-promotions.css',
            [],
            '0.9.0'
        );
    }

    public static function render_form() {
        ob_start(); ?>
        <div class="ifl-form-subtitle">
            Získajte exkluzívny prístup k prémiým výhodám, zľavám a špeciálnym ponukám.
        </div>
        
        <div class="ifl-benefits">
            <div class="ifl-benefits-title">Vaše VIP výhody</div>
            <ul class="ifl-benefits-list">
                <li>Exkluzívne zľavy až do 25%</li>
                <li>Prioritné rezervácie a obsluha</li>
                <li>Digitálna členská karta v Apple Wallet</li>
                <li>Špeciálne ponuky len pre členov</li>
                <li>Pozvánky na uzavreté podujatia</li>
            </ul>
        </div>
        
        <form id="ifl-subscribe-form">
          <p>
            <label for="ifl-email"><?php esc_html_e( 'E-mailová adresa', 'inflagranti-promotions' ); ?></label>
            <input type="email" id="ifl-email" name="email" placeholder="vas@email.com" required />
          </p>
          <p>
            <label for="ifl-name"><?php esc_html_e( 'Meno a priezvisko', 'inflagranti-promotions' ); ?></label>
            <input type="text" id="ifl-name" name="name" placeholder="Vaše celé meno" />
          </p>
          <p>
            <label for="ifl-phone"><?php esc_html_e( 'Telefónne číslo', 'inflagranti-promotions' ); ?></label>
            <input type="tel" id="ifl-phone" name="phone" placeholder="+421 xxx xxx xxx" />
          </p>
          <p>
            <label for="ifl-promo"><?php esc_html_e( 'Promo kód (voliteľné)', 'inflagranti-promotions' ); ?></label>
            <div class="ifl-promo-container">
              <input type="text" id="ifl-promo" name="promo" placeholder="Zadajte promo kód" style="text-transform: uppercase;" />
              <button type="button" id="ifl-check-promo" class="ifl-promo-check-btn">Overiť</button>
            </div>
            <div id="ifl-promo-message" class="ifl-promo-message"></div>
          </p>
          <p>
            <button type="submit"><?php esc_html_e( '🎉 Aktivovať členstvo', 'inflagranti-promotions' ); ?></button>
          </p>
          <div id="ifl-subscribe-message"></div>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function handle_subscription() {
        check_ajax_referer( 'ifl_subscribe_nonce', 'nonce' );

        $email = filter_var( $_POST['email'] ?? '', FILTER_VALIDATE_EMAIL );
        if ( ! $email ) {
            wp_send_json_error([ 'message' => __( 'Zadajte platný e-mail.', 'inflagranti-promotions' ) ]);
        }
        $name  = sanitize_text_field( $_POST['name']  ?? '' );
        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        $promo_code = strtoupper(sanitize_text_field( $_POST['promo_code'] ?? '' ));
        $promo_discount = intval( $_POST['promo_discount'] ?? 0 );

        global $wpdb;
        $table = $wpdb->prefix . 'ifl_subscribers';

        if ( $wpdb->get_var( $wpdb->prepare("SELECT id FROM $table WHERE email = %s", $email) ) ) {
            wp_send_json_error([ 'message' => __( 'Tento e-mail už je odobraný.', 'inflagranti-promotions' ) ]);
        }

        // Determine final discount
        $final_discount = 5; // Default discount
        $applied_promo = false;
        
        if ( $promo_code && $promo_discount > 0 ) {
            // Validate and use promo code
            if ( class_exists( 'IFL\Promotions\PromoCodes' ) ) {
                $promo_discount_validated = \IFL\Promotions\PromoCodes::use_promo_code( $promo_code );
                if ( $promo_discount_validated !== false ) {
                    $final_discount = $promo_discount_validated;
                    $applied_promo = $promo_code;
                }
            }
        }

        do {
            $serial = wp_generate_password( 12, false, false );
            $exists = $wpdb->get_var( $wpdb->prepare("SELECT id FROM $table WHERE pass_serial = %s", $serial) );
        } while ( $exists );

        $inserted = $wpdb->insert(
            $table,
            [
                'email'           => $email,
                'name'            => $name,
                'phone'           => $phone,
                'pass_serial'     => $serial,
                'discount_pct'    => $final_discount,
                'pass_downloaded' => 0,
                'created_at'      => current_time( 'mysql', 1 ),
            ],
            [ '%s','%s','%s','%s','%d','%d','%s' ]
        );

        if ( $inserted ) {
            // Log promo code usage if applied
            if ( $applied_promo ) {
                self::log_promo_usage( $wpdb->insert_id, $applied_promo, $final_discount );
            }
            
            // Build AJAX download URL for the pass
            $download_url = add_query_arg(
                [
                    'action' => 'ifl_serve_pass',
                    'serial' => $serial,
                ],
                admin_url( 'admin-ajax.php' )
            );

            $response_data = [
                'message'      => __( 'Ďakujeme za odber!', 'inflagranti-promotions' ),
                'download_url' => esc_url_raw( $download_url ),
                'applied_discount' => $final_discount,
            ];
            
            if ( $applied_promo ) {
                $response_data['promo_applied'] = $applied_promo;
            }

            wp_send_json_success( $response_data );
        } else {
            wp_send_json_error([ 'message' => __( 'Odber sa nepodaril. Skúste neskôr.', 'inflagranti-promotions' ) ]);
        }
    }
    
    /**
     * Log promo code usage for subscribers
     */
    private static function log_promo_usage( $subscriber_id, $promo_code, $discount ) {
        global $wpdb;
        
        $log_table = $wpdb->prefix . 'ifl_subscriber_promos';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $log_table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            subscriber_id BIGINT NOT NULL,
            promo_code VARCHAR(50) NOT NULL,
            discount_applied TINYINT NOT NULL,
            used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(subscriber_id),
            INDEX(promo_code)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        $wpdb->insert(
            $log_table,
            [
                'subscriber_id' => $subscriber_id,
                'promo_code' => $promo_code,
                'discount_applied' => $discount,
                'used_at' => current_time('mysql')
            ],
            ['%d', '%s', '%d', '%s']
        );
    }
}

Subscribers::init();