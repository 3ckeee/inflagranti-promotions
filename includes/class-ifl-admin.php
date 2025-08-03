<?php
namespace IFL\Promotions;
if ( ! defined( 'ABSPATH' ) ) exit;

class Admin {
    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_post_ifl_update_subscriber', [ __CLASS__, 'handle_update_subscriber' ] );
        add_action( 'admin_post_ifl_delete_subscriber', [ __CLASS__, 'handle_delete_subscriber' ] );
    }

    public static function add_menu() {
        // Top‐level “Promotions”
        add_menu_page(
            __( 'InFlagranti Promotions', 'inflagranti-promotions' ),
            __( 'Promotions',             'inflagranti-promotions' ),
            'manage_options',
            'ifl_promotions',
            [ __CLASS__, 'render_subscribers_page' ],
            'dashicons-tickets'
        );

        // Subpage: Edit one subscriber
        add_submenu_page(
            'ifl_promotions',
            __( 'Edit Subscriber', 'inflagranti-promotions' ),
            __( 'Edit Subscriber', 'inflagranti-promotions' ),
            'manage_options',
            'ifl_promotions_edit',
            [ __CLASS__, 'render_edit_subscriber_page' ]
        );
    }

    public static function render_subscribers_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_subscribers';
        $rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC" );

        echo '<div class="wrap"><h1>' . esc_html__( 'Subscribers', 'inflagranti-promotions' ) . '</h1>';
        if ( isset( $_GET['updated'] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Subscriber updated.', 'inflagranti-promotions' ) . '</p></div>';
        }
        if ( isset( $_GET['deleted'] ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Subscriber deleted.', 'inflagranti-promotions' ) . '</p></div>';
        }

        echo '<table class="widefat fixed striped"><thead><tr>'
           . '<th>' . esc_html__( 'Name', 'inflagranti-promotions' ) . '</th>'
           . '<th>' . esc_html__( 'Email', 'inflagranti-promotions' ) . '</th>'
           . '<th>' . esc_html__( 'Discount', 'inflagranti-promotions' ) . '</th>'
           . '<th>' . esc_html__( 'Pass Serial', 'inflagranti-promotions' ) . '</th>'
           . '<th>' . esc_html__( 'Joined', 'inflagranti-promotions' ) . '</th>'
           . '<th>' . esc_html__( 'Downloaded?', 'inflagranti-promotions' ) . '</th>'
           . '<th>' . esc_html__( 'Actions', 'inflagranti-promotions' ) . '</th>'
           . '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            $edit_url = admin_url( 'admin.php?page=ifl_promotions_edit&subscriber_id=' . intval( $r->id ) );
            $delete_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=ifl_delete_subscriber&subscriber_id=' . intval( $r->id ) ),
                'ifl_delete_subscriber_' . intval( $r->id )
            );
            $downloaded = $r->pass_downloaded ? esc_html__( 'Yes', 'inflagranti-promotions' ) : esc_html__( 'No', 'inflagranti-promotions' );

            printf(
                '<tr>
                    <td>%1$s</td>
                    <td>%2$s</td>
                    <td>%3$d%%</td>
                    <td>%4$s</td>
                    <td>%5$s</td>
                    <td>%6$s</td>
                    <td><a href="%7$s">%8$s</a> | <a href="%9$s" onclick="return confirm(\'Are you sure you want to delete this subscriber?\')">%10$s</a></td>
                 </tr>',
                esc_html( $r->name ),
                esc_html( $r->email ),
                esc_html( $r->discount_pct ),
                esc_html( $r->pass_serial ),
                esc_html( $r->created_at ),
                esc_html( $downloaded ),
                esc_url( $edit_url ),
                esc_html__( 'Edit', 'inflagranti-promotions' ),
                esc_url( $delete_url ),
                esc_html__( 'Delete', 'inflagranti-promotions' )
            );
        }

        echo '</tbody></table></div>';
    }

    public static function render_edit_subscriber_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_subscribers';
        $id    = intval( $_GET['subscriber_id'] ?? 0 );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );

        if ( ! $row ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Subscriber not found.', 'inflagranti-promotions' ) . '</p></div>';
            return;
        }
        ?>
        <div class="wrap">
          <h1><?php esc_html_e( 'Edit Subscriber', 'inflagranti-promotions' ); ?></h1>
          <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'ifl_update_subscriber_' . $id ); ?>
            <input type="hidden" name="action" value="ifl_update_subscriber">
            <input type="hidden" name="subscriber_id" value="<?php echo esc_attr( $id ); ?>">
            <table class="form-table">
              <tr>
                <th><label for="ifl-name"><?php esc_html_e( 'Name', 'inflagranti-promotions' ); ?></label></th>
                <td><input type="text" id="ifl-name" name="name" value="<?php echo esc_attr( $row->name ); ?>" class="regular-text"></td>
              </tr>
              <tr>
                <th><label for="ifl-discount"><?php esc_html_e( 'Discount (%)', 'inflagranti-promotions' ); ?></label></th>
                <td><input type="number" id="ifl-discount" name="discount_pct" value="<?php echo esc_attr( $row->discount_pct ); ?>" min="0" max="100"></td>
              </tr>
              <tr>
                <th><label for="ifl-pass-serial"><?php esc_html_e( 'Pass Serial', 'inflagranti-promotions' ); ?></label></th>
                <td><input type="text" id="ifl-pass-serial" name="pass_serial" value="<?php echo esc_attr( $row->pass_serial ); ?>" class="regular-text"></td>
              </tr>
              <tr>
                <th><label for="ifl-downloaded"><?php esc_html_e( 'Downloaded?', 'inflagranti-promotions' ); ?></label></th>
                <td><input type="checkbox" id="ifl-downloaded" name="pass_downloaded" value="1" <?php checked( 1, $row->pass_downloaded ); ?>></td>
              </tr>
            </table>
            <?php submit_button( __( 'Save Subscriber', 'inflagranti-promotions' ) ); ?>
          </form>
        </div>
        <?php
    }

    public static function handle_update_subscriber() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'inflagranti-promotions' ) );
        }
        $id = intval( $_POST['subscriber_id'] ?? 0 );
        check_admin_referer( 'ifl_update_subscriber_' . $id );
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_subscribers';
        $wpdb->update(
            $table,
            [
                'name'            => sanitize_text_field( $_POST['name'] ?? '' ),
                'discount_pct'    => intval( $_POST['discount_pct'] ?? 10 ),
                'pass_serial'     => sanitize_text_field( $_POST['pass_serial'] ?? '' ),
                'pass_downloaded' => isset( $_POST['pass_downloaded'] ) ? 1 : 0,
            ],
            [ 'id' => $id ],
            [ '%s','%d','%s','%d' ],
            [ '%d' ]
        );
        wp_redirect( admin_url( 'admin.php?page=ifl_promotions&updated=1' ) );
        exit;
    }

    public static function handle_delete_subscriber() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'inflagranti-promotions' ) );
        }
        $id = intval( $_GET['subscriber_id'] ?? 0 );
        check_admin_referer( 'ifl_delete_subscriber_' . $id );
        global $wpdb;
        $table = $wpdb->prefix . 'ifl_subscribers';
        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        wp_redirect( admin_url( 'admin.php?page=ifl_promotions&deleted=1' ) );
        exit;
    }
}

Admin::init();