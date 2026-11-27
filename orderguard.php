<?php
/**
 * Plugin Name: OrderGuard
 * Description: Lightweight anti-fake-order protection for WooCommerce (Classic, Block Checkout, and CartFlows). Locks IP and device for a set duration until an admin changes the order status from Processing.
 * Version: 1.0.0
 * Author: Abir
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OrderGuard_Plugin {

    private $option_name = 'orderguard_settings';
    private $cookie_name = 'orderguard_device_token';

    public function __construct() {
        // Declare WooCommerce HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

        // Ensure visitor has a device token cookie
        add_action( 'init', array( $this, 'ensure_device_cookie' ) );

        // Admin settings page under WooCommerce
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // Block checkout submissions (Classic & CartFlows)
        add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 10, 2 );

        // Block checkout submissions (WooCommerce Block Checkout / Store API)
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'validate_block_checkout' ), 5, 1 );

        // Lock customer after order is created (Classic, CartFlows, and Block Checkout)
        add_action( 'woocommerce_checkout_order_created', array( $this, 'lock_customer_on_order' ), 20, 1 );
        add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'lock_customer_on_order' ), 20, 1 );

        // Unlock customer immediately when admin changes status FROM processing TO anything else
        add_action( 'woocommerce_order_status_changed', array( $this, 'unlock_on_status_change' ), 10, 4 );

        // Display universal lock banner with WhatsApp button on all checkout types
        add_action( 'wp_footer', array( $this, 'render_checkout_lock_banner' ) );
    }

    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    private function get_settings() {
        $defaults = array(
            'lock_minutes'     => 10,
            'lock_message'     => 'Your previous order is currently processing. Please wait before placing another order.',
            'emergency_text'   => 'If this is an emergency or you need to update your order, please contact us on WhatsApp.',
            'whatsapp_number'  => '8801700000000',
            'whatsapp_message' => 'Hello, my previous order is processing and I need urgent assistance.',
            'whatsapp_btn'     => 'Contact on WhatsApp',
            'bypass_admin'     => 0,
        );

        $saved = get_option( $this->option_name, array() );
        return wp_parse_args( $saved, $defaults );
    }

    public function ensure_device_cookie() {
        if ( is_admin() ) {
            return;
        }
        if ( empty( $_COOKIE[ $this->cookie_name ] ) ) {
            $token = wp_generate_password( 32, false, false );
            setcookie(
                $this->cookie_name,
                $token,
                time() + ( 30 * DAY_IN_SECONDS ),
                COOKIEPATH ? COOKIEPATH : '/',
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
            $_COOKIE[ $this->cookie_name ] = $token;
        }
    }

    private function get_client_ip() {
        $keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip_list = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
                $ip      = trim( $ip_list[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    private function get_hashes() {
        $ip     = $this->get_client_ip();
        $device = ! empty( $_COOKIE[ $this->cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $this->cookie_name ] ) ) : '';

        return array(
            'ip'  => md5( 'og_ip_' . $ip ),
            'dev' => $device ? md5( 'og_dev_' . $device ) : '',
        );
    }

    private function is_customer_locked() {
        $settings = $this->get_settings();
        if ( ! empty( $settings['bypass_admin'] ) && current_user_can( 'manage_woocommerce' ) ) {
            return false;
        }

        $hashes = $this->get_hashes();

        if ( get_transient( 'orderguard_ip_' . $hashes['ip'] ) ) {
            return true;
        }

        if ( ! empty( $hashes['dev'] ) && get_transient( 'orderguard_dev_' . $hashes['dev'] ) ) {
            return true;
        }

        return false;
    }

    public function lock_customer_on_order( $order ) {
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return;
        }

        // Prevent duplicate locking if multiple hooks fire for the same order
        if ( $order->get_meta( '_orderguard_locked' ) === 'yes' ) {
            return;
        }

        $settings = $this->get_settings();
        $minutes  = max( 1, absint( $settings['lock_minutes'] ) );
        $expiry   = $minutes * MINUTE_IN_SECONDS;
        $hashes   = $this->get_hashes();
        $order_id = $order->get_id();

        set_transient( 'orderguard_ip_' . $hashes['ip'], $order_id, $expiry );
        if ( ! empty( $hashes['dev'] ) ) {
            set_transient( 'orderguard_dev_' . $hashes['dev'], $order_id, $expiry );
        }

        $order->update_meta_data( '_orderguard_locked', 'yes' );
        $order->update_meta_data( '_orderguard_ip_hash', $hashes['ip'] );
        $order->update_meta_data( '_orderguard_dev_hash', $hashes['dev'] );
        $order->save();
    }

    public function unlock_on_status_change( $order_id, $old_status, $new_status, $order ) {
        if ( $old_status !== 'processing' || $new_status === 'processing' ) {
            return;
        }

        if ( ! is_a( $order, 'WC_Order' ) ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $ip_hash  = $order->get_meta( '_orderguard_ip_hash' );
        $dev_hash = $order->get_meta( '_orderguard_dev_hash' );

        if ( ! empty( $ip_hash ) ) {
            delete_transient( 'orderguard_ip_' . $ip_hash );
        }
        if ( ! empty( $dev_hash ) ) {
            delete_transient( 'orderguard_dev_' . $dev_hash );
        }
    }

    public function validate_classic_checkout( $data, $errors ) {
        if ( $this->is_customer_locked() ) {
            $settings = $this->get_settings();
            $errors->add( 'orderguard_locked', wp_kses_post( $settings['lock_message'] ) );
        }
    }

    public function validate_block_checkout( $order ) {
        if ( $this->is_customer_locked() ) {
            $settings = $this->get_settings();
            if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
                    'orderguard_locked',
                    wp_strip_all_tags( $settings['lock_message'] ),
                    400
                );
            }
        }
    }

    private function is_any_checkout_page() {
        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            return false;
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            return true;
        }
        if ( function_exists( 'wcf_is_checkout_page' ) && wcf_is_checkout_page() ) {
            return true;
        }
        return false;
    }

    public function render_checkout_lock_banner() {
        if ( ! $this->is_any_checkout_page() || ! $this->is_customer_locked() ) {
            return;
        }

        $settings = $this->get_settings();
        $phone    = preg_replace( '/[^0-9]/', '', $settings['whatsapp_number'] );
        $wa_url   = 'https://wa.me/' . $phone . '?text=' . rawurlencode( $settings['whatsapp_message'] );
        ?>
        <div id="orderguard-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,0.72);z-index:999999;display:flex;align-items:center;justify-content:center;padding:16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <div style="background:#ffffff;color:#0f172a;max-width:440px;width:100%;border-radius:12px;padding:28px 24px;box-shadow:0 20px 40px rgba(0,0,0,0.25);text-align:center;">
                <h3 style="margin:0 0 10px;font-size:19px;font-weight:700;color:#0f172a;">Order Already Processing</h3>
                <p style="margin:0 0 16px;font-size:15px;line-height:1.5;color:#334155;">
                    <?php echo esc_html( $settings['lock_message'] ); ?>
                </p>
                <p style="margin:0 0 18px;font-size:12px;line-height:1.5;color:#64748b;">
                    <?php echo esc_html( $settings['emergency_text'] ); ?>
                </p>
                <a href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;background:#25D366;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 22px;border-radius:8px;">
                    <?php echo esc_html( $settings['whatsapp_btn'] ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'OrderGuard Settings',
            'OrderGuard',
            'manage_woocommerce',
            'orderguard-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'orderguard_settings_group', $this->option_name, array( $this, 'sanitize_settings' ) );
    }

    public function sanitize_settings( $input ) {
        return array(
            'lock_minutes'     => max( 1, absint( $input['lock_minutes'] ?? 10 ) ),
            'lock_message'     => sanitize_textarea_field( $input['lock_message'] ?? '' ),
            'emergency_text'   => sanitize_textarea_field( $input['emergency_text'] ?? '' ),
            'whatsapp_number'  => preg_replace( '/[^0-9]/', '', $input['whatsapp_number'] ?? '' ),
            'whatsapp_message' => sanitize_textarea_field( $input['whatsapp_message'] ?? '' ),
            'whatsapp_btn'     => sanitize_text_field( $input['whatsapp_btn'] ?? 'Contact on WhatsApp' ),
            'bypass_admin'     => ! empty( $input['bypass_admin'] ) ? 1 : 0,
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $s = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>OrderGuard Settings</h1>
            <p>Prevent duplicate or fake orders by temporarily locking customer IP and device after checkout.</p>
            <form method="post" action="options.php">
                <?php settings_fields( 'orderguard_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="og_lock_minutes">Lock Duration (Minutes)</label></th>
                        <td>
                            <input name="<?php echo esc_attr( $this->option_name ); ?>[lock_minutes]" id="og_lock_minutes" type="number" min="1" value="<?php echo esc_attr( $s['lock_minutes'] ); ?>" class="small-text" />
                            <p class="description">Automatically unlocks when this time expires, or immediately when an admin changes the order status from Processing.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="og_lock_message">Main Lock Message</label></th>
                        <td>
                            <textarea name="<?php echo esc_attr( $this->option_name ); ?>[lock_message]" id="og_lock_message" rows="3" class="large-text"><?php echo esc_textarea( $s['lock_message'] ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="og_emergency_text">Emergency Note (Small Text)</label></th>
                        <td>
                            <textarea name="<?php echo esc_attr( $this->option_name ); ?>[emergency_text]" id="og_emergency_text" rows="2" class="large-text"><?php echo esc_textarea( $s['emergency_text'] ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="og_whatsapp_number">WhatsApp Number</label></th>
                        <td>
                            <input name="<?php echo esc_attr( $this->option_name ); ?>[whatsapp_number]" id="og_whatsapp_number" type="text" value="<?php echo esc_attr( $s['whatsapp_number'] ); ?>" class="regular-text" placeholder="8801700000000" />
                            <p class="description">Include country code without + or spaces (example: <code>8801712345678</code>).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="og_whatsapp_message">Pre-filled WhatsApp Message</label></th>
                        <td>
                            <textarea name="<?php echo esc_attr( $this->option_name ); ?>[whatsapp_message]" id="og_whatsapp_message" rows="2" class="large-text"><?php echo esc_textarea( $s['whatsapp_message'] ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="og_whatsapp_btn">WhatsApp Button Label</label></th>
                        <td>
                            <input name="<?php echo esc_attr( $this->option_name ); ?>[whatsapp_btn]" id="og_whatsapp_btn" type="text" value="<?php echo esc_attr( $s['whatsapp_btn'] ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Admin Testing</th>
                        <td>
                            <label>
                                <input name="<?php echo esc_attr( $this->option_name ); ?>[bypass_admin]" type="checkbox" value="1" <?php checked( 1, $s['bypass_admin'] ); ?> />
                                Bypass lock for logged-in Administrators (leave unchecked while testing)
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
        <?php
    }
}

new OrderGuard_Plugin();