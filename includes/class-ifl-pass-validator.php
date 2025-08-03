<?php
namespace IFL\Promotions;
if ( ! defined( 'ABSPATH' ) ) exit;

class PassValidator {
    
    public static function init() {
        // AJAX endpoints for pass validation and updates
        add_action('wp_ajax_ifl_validate_pass', [__CLASS__, 'validate_pass']);
        add_action('wp_ajax_nopriv_ifl_validate_pass', [__CLASS__, 'validate_pass']);
        
        add_action('wp_ajax_ifl_update_discount', [__CLASS__, 'update_discount']);
        add_action('wp_ajax_nopriv_ifl_update_discount', [__CLASS__, 'update_discount']);
        
        add_action('wp_ajax_ifl_get_nonce', [__CLASS__, 'get_nonce']);
        add_action('wp_ajax_nopriv_ifl_get_nonce', [__CLASS__, 'get_nonce']);
        
        // Add admin pages
        add_action('admin_menu', [__CLASS__, 'add_admin_pages']);
    }
    
    /**
     * Get nonce for security
     */
    public static function get_nonce() {
        wp_send_json_success([
            'nonce' => wp_create_nonce('ifl_validate_pass_nonce')
        ]);
    }
    
    /**
     * Validate a scanned pass
     */
    public static function validate_pass() {
        // Verify nonce for security
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'ifl_validate_pass_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        $serial = sanitize_text_field($_POST['serial'] ?? '');
        
        if (empty($serial)) {
            wp_send_json_error(['message' => 'Chýba sériové číslo karty']);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_subscribers';
        
        // Find subscriber by pass serial
        $subscriber = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE pass_serial = %s", $serial)
        );
        
        if (!$subscriber) {
            wp_send_json_error(['message' => 'Karta nebola nájdená']);
        }
        
        // Log the scan
        self::log_scan($subscriber->id, $serial);
        
        // Return subscriber data
        wp_send_json_success([
            'name' => $subscriber->name,
            'email' => $subscriber->email,
            'phone' => $subscriber->phone,
            'discount_pct' => intval($subscriber->discount_pct),
            'pass_serial' => $subscriber->pass_serial,
            'created_at' => $subscriber->created_at,
            'scan_time' => current_time('mysql')
        ]);
    }
    
    /**
     * Update discount for a pass
     */
    public static function update_discount() {
        // Verify nonce for security
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'ifl_validate_pass_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        $serial = sanitize_text_field($_POST['serial'] ?? '');
        $new_discount = intval($_POST['new_discount'] ?? 0);
        
        if (empty($serial)) {
            wp_send_json_error(['message' => 'Chýba sériové číslo karty']);
        }
        
        if ($new_discount < 0 || $new_discount > 100) {
            wp_send_json_error(['message' => 'Zľava musí byť medzi 0-100%']);
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_subscribers';
        
        // Find subscriber by pass serial
        $subscriber = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE pass_serial = %s", $serial)
        );
        
        if (!$subscriber) {
            wp_send_json_error(['message' => 'Karta nebola nájdená']);
        }
        
        $old_discount = $subscriber->discount_pct;
        
        // Update discount
        $updated = $wpdb->update(
            $table,
            ['discount_pct' => $new_discount],
            ['pass_serial' => $serial],
            ['%d'],
            ['%s']
        );
        
        if ($updated === false) {
            wp_send_json_error(['message' => 'Chyba pri aktualizácii databázy']);
        }
        
        // Log the discount change
        self::log_discount_change($subscriber->id, $serial, $old_discount, $new_discount);
        
        wp_send_json_success([
            'message' => "Zľava aktualizovaná z {$old_discount}% na {$new_discount}%",
            'old_discount' => intval($old_discount),
            'new_discount' => intval($new_discount),
            'updated_at' => current_time('mysql')
        ]);
    }
    
    /**
     * Log scan activity
     */
    private static function log_scan($subscriber_id, $serial) {
        global $wpdb;
        
        // Create scan log table if it doesn't exist
        $log_table = $wpdb->prefix . 'ifl_scan_logs';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $log_table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            subscriber_id BIGINT NOT NULL,
            pass_serial VARCHAR(50) NOT NULL,
            scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            user_agent TEXT,
            INDEX(subscriber_id),
            INDEX(scanned_at)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Log the scan
        $wpdb->insert(
            $log_table,
            [
                'subscriber_id' => $subscriber_id,
                'pass_serial' => $serial,
                'scanned_at' => current_time('mysql'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }
    
    /**
     * Log discount changes
     */
    private static function log_discount_change($subscriber_id, $serial, $old_discount, $new_discount) {
        global $wpdb;
        
        // Create discount change log table if it doesn't exist
        $log_table = $wpdb->prefix . 'ifl_discount_changes';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $log_table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            subscriber_id BIGINT NOT NULL,
            pass_serial VARCHAR(50) NOT NULL,
            old_discount TINYINT NOT NULL,
            new_discount TINYINT NOT NULL,
            changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            changed_by_ip VARCHAR(45),
            user_agent TEXT,
            INDEX(subscriber_id),
            INDEX(changed_at)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Log the change
        $wpdb->insert(
            $log_table,
            [
                'subscriber_id' => $subscriber_id,
                'pass_serial' => $serial,
                'old_discount' => $old_discount,
                'new_discount' => $new_discount,
                'changed_at' => current_time('mysql'),
                'changed_by_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s']
        );
    }
    
    /**
     * Add admin pages
     */
    public static function add_admin_pages() {
        add_submenu_page(
            'ifl_promotions',
            __('QR Scanner', 'inflagranti-promotions'),
            __('QR Scanner', 'inflagranti-promotions'),
            'manage_options',
            'ifl_qr_scanner',
            [__CLASS__, 'render_scanner_page']
        );
        
        add_submenu_page(
            'ifl_promotions',
            __('Scan History', 'inflagranti-promotions'),
            __('Scan History', 'inflagranti-promotions'),
            'manage_options',
            'ifl_scan_history',
            [__CLASS__, 'render_scan_history_page']
        );
    }
    
    /**
     * Render admin scanner page
     */
    public static function render_scanner_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('QR Code Scanner', 'inflagranti-promotions'); ?></h1>
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4;">
                <h2>📱 Mobile Scanner</h2>
                <p>Your mobile QR scanner is deployed and ready to use:</p>
                <p><strong>Scanner URL:</strong> <a href="https://inflagranti.sk/scanner.html" target="_blank">https://inflagranti.sk/scanner.html</a></p>
                
                <h3>📋 Instructions for Staff:</h3>
                <ol>
                    <li><strong>Open the scanner URL</strong> on your phone/tablet</li>
                    <li><strong>Allow camera access</strong> when prompted</li>
                    <li><strong>Point camera at QR code</strong> on customer's pass</li>
                    <li><strong>View customer info</strong> and current discount</li>
                    <li><strong>Adjust discount</strong> if needed using the controls</li>
                </ol>
                
                <h3>🔧 Quick Setup:</h3>
                <p><strong>iPhone:</strong> Safari → Share → "Add to Home Screen"</p>
                <p><strong>Android:</strong> Chrome → Menu → "Add to Home screen"</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render scan history page
     */
    public static function render_scan_history_page() {
        global $wpdb;
        
        // Get recent scans
        $scan_table = $wpdb->prefix . 'ifl_scan_logs';
        $subscriber_table = $wpdb->prefix . 'ifl_subscribers';
        
        $recent_scans = $wpdb->get_results("
            SELECT s.*, sub.name, sub.email, sub.discount_pct 
            FROM $scan_table s 
            LEFT JOIN $subscriber_table sub ON s.subscriber_id = sub.id 
            ORDER BY s.scanned_at DESC 
            LIMIT 50
        ");
        
        // Get recent discount changes
        $discount_table = $wpdb->prefix . 'ifl_discount_changes';
        $recent_changes = $wpdb->get_results("
            SELECT d.*, sub.name, sub.email 
            FROM $discount_table d 
            LEFT JOIN $subscriber_table sub ON d.subscriber_id = sub.id 
            ORDER BY d.changed_at DESC 
            LIMIT 20
        ");
        
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Scan History', 'inflagranti-promotions'); ?></h1>
            
            <div style="display: flex; gap: 20px;">
                <div style="flex: 1;">
                    <h2>📊 Recent Scans</h2>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Discount</th>
                                <th>Serial</th>
                                <th>Scanned At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_scans)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px;">
                                    No scans yet. <a href="https://inflagranti.sk/scanner.html" target="_blank">Start scanning!</a>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($recent_scans as $scan): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($scan->name ?: 'VIP Member'); ?></strong><br>
                                        <small><?php echo esc_html($scan->email); ?></small>
                                    </td>
                                    <td><span style="background: #B8A75D; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px;"><?php echo esc_html($scan->discount_pct); ?>%</span></td>
                                    <td><code style="font-size: 11px;"><?php echo esc_html($scan->pass_serial); ?></code></td>
                                    <td><?php echo esc_html(date('M j, H:i', strtotime($scan->scanned_at))); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="flex: 1;">
                    <h2>✏️ Recent Discount Changes</h2>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Change</th>
                                <th>Serial</th>
                                <th>Changed At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_changes)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px;">
                                    No discount changes yet.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($recent_changes as $change): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($change->name ?: 'VIP Member'); ?></strong><br>
                                        <small><?php echo esc_html($change->email); ?></small>
                                    </td>
                                    <td>
                                        <span style="color: #666;"><?php echo esc_html($change->old_discount); ?>%</span> → 
                                        <strong style="color: #B8A75D;"><?php echo esc_html($change->new_discount); ?>%</strong>
                                    </td>
                                    <td><code style="font-size: 11px;"><?php echo esc_html($change->pass_serial); ?></code></td>
                                    <td><?php echo esc_html(date('M j, H:i', strtotime($change->changed_at))); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}

// Initialize the validator
PassValidator::init();