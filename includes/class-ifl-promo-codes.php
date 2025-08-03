<?php
namespace IFL\Promotions;
if ( ! defined( 'ABSPATH' ) ) exit;

class PromoCodes {
    
    public static function init() {
        // AJAX endpoint for promo code validation
        add_action('wp_ajax_ifl_validate_promo', [__CLASS__, 'validate_promo_code']);
        add_action('wp_ajax_nopriv_ifl_validate_promo', [__CLASS__, 'validate_promo_code']);
        
        // Admin page for managing promo codes
        add_action('admin_menu', [__CLASS__, 'add_admin_pages']);
        
        // Create promo codes table
        add_action('plugins_loaded', [__CLASS__, 'create_promo_codes_table']);
        
        // Handle admin form submissions
        add_action('admin_post_ifl_add_promo_code', [__CLASS__, 'handle_add_promo_code']);
        add_action('admin_post_ifl_toggle_promo_code', [__CLASS__, 'handle_toggle_promo_code']);
    }
    
    /**
     * Create promo codes table
     */
    public static function create_promo_codes_table() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ifl_promo_codes';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) NOT NULL UNIQUE,
            description VARCHAR(200),
            discount_pct TINYINT NOT NULL DEFAULT 10,
            max_uses INT DEFAULT 0,
            current_uses INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            created_by VARCHAR(100),
            INDEX(code),
            INDEX(is_active),
            INDEX(expires_at)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        // Insert default promo codes if table is empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count == 0) {
            $default_codes = [
                [
                    'code' => 'WELCOME10', 
                    'description' => 'Welcome discount for new members', 
                    'discount_pct' => 10, 
                    'max_uses' => 100,
                    'created_by' => 'System'
                ],
                [
                    'code' => 'VIP15', 
                    'description' => 'VIP member special discount', 
                    'discount_pct' => 15, 
                    'max_uses' => 50,
                    'created_by' => 'System'
                ],
                [
                    'code' => 'FRIEND20', 
                    'description' => 'Friend referral bonus', 
                    'discount_pct' => 20, 
                    'max_uses' => 200,
                    'created_by' => 'System'
                ],
                [
                    'code' => 'SUMMER25', 
                    'description' => 'Summer special promotion', 
                    'discount_pct' => 25, 
                    'max_uses' => 0, // Unlimited
                    'expires_at' => date('Y-m-d H:i:s', strtotime('+3 months')),
                    'created_by' => 'System'
                ]
            ];
            
            foreach ($default_codes as $code_data) {
                $wpdb->insert($table, $code_data, ['%s', '%s', '%d', '%d', '%s', '%s']);
            }
        }
    }
    
    /**
     * Validate promo code via AJAX
     */
    public static function validate_promo_code() {
        // Verify nonce
        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'ifl_subscribe_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }
        
        $code = strtoupper(sanitize_text_field($_POST['code'] ?? ''));
        
        if (empty($code)) {
            wp_send_json_error(['message' => 'Zadajte promo kód']);
        }
        
        $promo_data = self::get_valid_promo_code($code);
        
        if ($promo_data) {
            wp_send_json_success([
                'discount' => $promo_data->discount_pct,
                'description' => $promo_data->description,
                'message' => "Promo kód aktivovaný! Dostávate {$promo_data->discount_pct}% zľavu."
            ]);
        } else {
            wp_send_json_error(['message' => 'Neplatný alebo expirovaný promo kód']);
        }
    }
    
    /**
     * Get valid promo code data
     */
    public static function get_valid_promo_code($code) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ifl_promo_codes';
        $promo = $wpdb->get_row(
            $wpdb->prepare("
                SELECT * FROM $table 
                WHERE code = %s 
                AND is_active = 1 
                AND (expires_at IS NULL OR expires_at > NOW())
                AND (max_uses = 0 OR current_uses < max_uses)
            ", $code)
        );
        
        return $promo;
    }
    
    /**
     * Use promo code (increment usage)
     */
    public static function use_promo_code($code) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ifl_promo_codes';
        $promo = self::get_valid_promo_code($code);
        
        if ($promo) {
            // Increment usage count
            $wpdb->update(
                $table,
                ['current_uses' => $promo->current_uses + 1],
                ['id' => $promo->id],
                ['%d'],
                ['%d']
            );
            
            // Log promo code usage
            self::log_promo_usage($promo->id, $code);
            
            return $promo->discount_pct;
        }
        
        return false;
    }
    
    /**
     * Log promo code usage
     */
    private static function log_promo_usage($promo_id, $code) {
        global $wpdb;
        
        $log_table = $wpdb->prefix . 'ifl_promo_usage_log';
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $log_table (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            promo_id BIGINT NOT NULL,
            promo_code VARCHAR(50) NOT NULL,
            used_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ip_address VARCHAR(45),
            user_agent TEXT,
            INDEX(promo_id),
            INDEX(used_at)
        ) $charset;";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        $wpdb->insert(
            $log_table,
            [
                'promo_id' => $promo_id,
                'promo_code' => $code,
                'used_at' => current_time('mysql'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }
    
    /**
     * Add admin pages
     */
    public static function add_admin_pages() {
        add_submenu_page(
            'ifl_promotions',
            __('Promo Codes', 'inflagranti-promotions'),
            __('Promo Codes', 'inflagranti-promotions'),
            'manage_options',
            'ifl_promo_codes',
            [__CLASS__, 'render_promo_codes_page']
        );
    }
    
    /**
     * Handle adding new promo code
     */
    public static function handle_add_promo_code() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        check_admin_referer('ifl_add_promo_code');
        
        $code = strtoupper(sanitize_text_field($_POST['code'] ?? ''));
        $description = sanitize_text_field($_POST['description'] ?? '');
        $discount_pct = intval($_POST['discount_pct'] ?? 10);
        $max_uses = intval($_POST['max_uses'] ?? 0);
        $expires_at = sanitize_text_field($_POST['expires_at'] ?? '');
        
        if (empty($code) || $discount_pct < 1 || $discount_pct > 100) {
            wp_redirect(admin_url('admin.php?page=ifl_promo_codes&error=invalid'));
            exit;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_promo_codes';
        
        $inserted = $wpdb->insert($table, [
            'code' => $code,
            'description' => $description,
            'discount_pct' => $discount_pct,
            'max_uses' => $max_uses,
            'expires_at' => $expires_at ? date('Y-m-d H:i:s', strtotime($expires_at)) : null,
            'created_by' => wp_get_current_user()->display_name
        ], ['%s', '%s', '%d', '%d', '%s', '%s']);
        
        if ($inserted) {
            wp_redirect(admin_url('admin.php?page=ifl_promo_codes&added=1'));
        } else {
            wp_redirect(admin_url('admin.php?page=ifl_promo_codes&error=duplicate'));
        }
        exit;
    }
    
    /**
     * Handle toggling promo code status
     */
    public static function handle_toggle_promo_code() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $promo_id = intval($_GET['promo_id'] ?? 0);
        $new_status = intval($_GET['status'] ?? 0);
        
        check_admin_referer('ifl_toggle_promo_' . $promo_id);
        
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_promo_codes';
        
        $wpdb->update(
            $table,
            ['is_active' => $new_status],
            ['id' => $promo_id],
            ['%d'],
            ['%d']
        );
        
        wp_redirect(admin_url('admin.php?page=ifl_promo_codes&updated=1'));
        exit;
    }
    
    /**
     * Render promo codes management page
     */
    public static function render_promo_codes_page() {
        global $wpdb;
        
        // Handle messages
        if (isset($_GET['added'])) {
            echo '<div class="notice notice-success"><p>Promo kód bol úspešne pridaný!</p></div>';
        }
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Promo kód bol aktualizovaný!</p></div>';
        }
        if (isset($_GET['error'])) {
            $error = $_GET['error'];
            if ($error === 'duplicate') {
                echo '<div class="notice notice-error"><p>Promo kód už existuje!</p></div>';
            } elseif ($error === 'invalid') {
                echo '<div class="notice notice-error"><p>Neplatné údaje!</p></div>';
            }
        }
        
        $table = $wpdb->prefix . 'ifl_promo_codes';
        $codes = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");
        $usage_table = $wpdb->prefix . 'ifl_promo_usage_log';
        
        ?>
        <div class="wrap">
            <h1>🎫 Správa Promo Kódov</h1>
            
            <!-- Statistics -->
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h2>📊 Štatistiky</h2>
                <?php
                $total_codes = count($codes);
                $active_codes = count(array_filter($codes, fn($c) => $c->is_active));
                $total_usage = $wpdb->get_var("SELECT COUNT(*) FROM $usage_table");
                $today_usage = $wpdb->get_var("SELECT COUNT(*) FROM $usage_table WHERE DATE(used_at) = CURDATE()");
                ?>
                <div style="display: flex; gap: 30px;">
                    <div>
                        <h3 style="margin: 0; color: #B8A75D;"><?php echo $total_codes; ?></h3>
                        <p style="margin: 5px 0; color: #666;">Celkový počet kódov</p>
                    </div>
                    <div>
                        <h3 style="margin: 0; color: #B8A75D;"><?php echo $active_codes; ?></h3>
                        <p style="margin: 5px 0; color: #666;">Aktívne kódy</p>
                    </div>
                    <div>
                        <h3 style="margin: 0; color: #B8A75D;"><?php echo $total_usage; ?></h3>
                        <p style="margin: 5px 0; color: #666;">Celkové použitia</p>
                    </div>
                    <div>
                        <h3 style="margin: 0; color: #B8A75D;"><?php echo $today_usage; ?></h3>
                        <p style="margin: 5px 0; color: #666;">Použité dnes</p>
                    </div>
                </div>
            </div>
            
            <!-- Add New Code Form -->
            <div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <h2>➕ Pridať Nový Promo Kód</h2>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('ifl_add_promo_code'); ?>
                    <input type="hidden" name="action" value="ifl_add_promo_code">
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="code">Kód *</label></th>
                            <td><input type="text" id="code" name="code" class="regular-text" placeholder="WELCOME10" required style="text-transform: uppercase;"></td>
                        </tr>
                        <tr>
                            <th><label for="description">Popis</label></th>
                            <td><input type="text" id="description" name="description" class="regular-text" placeholder="Popis promo kódu"></td>
                        </tr>
                        <tr>
                            <th><label for="discount_pct">Zľava (%) *</label></th>
                            <td><input type="number" id="discount_pct" name="discount_pct" value="10" min="1" max="100" required></td>
                        </tr>
                        <tr>
                            <th><label for="max_uses">Max. použití (0 = neobmedzené)</label></th>
                            <td><input type="number" id="max_uses" name="max_uses" value="100" min="0"></td>
                        </tr>
                        <tr>
                            <th><label for="expires_at">Expirácia</label></th>
                            <td><input type="datetime-local" id="expires_at" name="expires_at"></td>
                        </tr>
                    </table>
                    <?php submit_button('Pridať Promo Kód', 'primary'); ?>
                </form>
            </div>
            
            <!-- Existing Codes -->
            <h2>📋 Existujúce Promo Kódy</h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th>Kód</th>
                        <th>Popis</th>
                        <th>Zľava</th>
                        <th>Použitia</th>
                        <th>Status</th>
                        <th>Expirácia</th>
                        <th>Vytvoril</th>
                        <th>Akcie</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($codes)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 30px;">
                            Žiadne promo kódy zatiaľ neboli vytvorené.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($codes as $code): ?>
                        <tr style="<?php echo !$code->is_active ? 'opacity: 0.6;' : ''; ?>">
                            <td>
                                <strong style="font-family: monospace; background: #f1f1f1; padding: 4px 8px; border-radius: 4px;">
                                    <?php echo esc_html($code->code); ?>
                                </strong>
                            </td>
                            <td><?php echo esc_html($code->description); ?></td>
                            <td>
                                <span style="background: #B8A75D; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">
                                    <?php echo esc_html($code->discount_pct); ?>%
                                </span>
                            </td>
                            <td>
                                <?php echo esc_html($code->current_uses); ?> 
                                <?php if ($code->max_uses > 0): ?>
                                    / <?php echo esc_html($code->max_uses); ?>
                                <?php else: ?>
                                    / ∞
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($code->is_active): ?>
                                    <span style="color: #46b450;">✅ Aktívny</span>
                                <?php else: ?>
                                    <span style="color: #dc3232;">❌ Neaktívny</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($code->expires_at): ?>
                                    <?php echo esc_html(date('d.m.Y H:i', strtotime($code->expires_at))); ?>
                                <?php else: ?>
                                    <span style="color: #666;">Bez expirácie</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 11px; color: #666;">
                                <?php echo esc_html($code->created_by ?: 'System'); ?><br>
                                <?php echo esc_html(date('d.m.Y', strtotime($code->created_at))); ?>
                            </td>
                            <td>
                                <?php
                                $toggle_url = wp_nonce_url(
                                    admin_url('admin-post.php?action=ifl_toggle_promo_code&promo_id=' . $code->id . '&status=' . ($code->is_active ? 0 : 1)),
                                    'ifl_toggle_promo_' . $code->id
                                );
                                ?>
                                <a href="<?php echo esc_url($toggle_url); ?>" 
                                   onclick="return confirm('Ste si istý?')">
                                    <?php echo $code->is_active ? 'Deaktivovať' : 'Aktivovať'; ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <style>
        .form-table th {
            width: 150px;
        }
        #code {
            text-transform: uppercase;
        }
        </style>
        
        <script>
        // Auto-uppercase code input
        document.getElementById('code').addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });
        </script>
        <?php
    }
}

// Initialize Promo Codes
PromoCodes::init();