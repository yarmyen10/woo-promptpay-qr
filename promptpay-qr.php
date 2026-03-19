<?php
/**
 * Plugin Name: PromptPay QR Shortcode
 * Description: Shortcode [promptpay_qr] แสดง QR Code + Upload สลิป พร้อมตรวจสอบอัตโนมัติ
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: promptpay-qr
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PROMPTPAY_DIR',     plugin_dir_path( __FILE__ ) );
define( 'PROMPTPAY_URL',     plugin_dir_url( __FILE__ ) );
define( 'PROMPTPAY_VERSION', '1.0.0' );

// Autoload classes
spl_autoload_register( function( $class ) {
    $map = [
        'PromptPay_QR_Generator'  => 'inc/class-qr-generator.php',
        'PromptPay_Slip_Verify'   => 'inc/class-slip-verify.php',
        'PromptPay_Shortcode'     => 'inc/class-shortcode.php',
        'PromptPay_Ajax'          => 'inc/class-ajax.php',
        'PromptPay_Settings'      => 'inc/class-settings.php',
        'PromptPay_Assets'        => 'inc/class-assets.php',
        'PromptPay_Gateway'       => 'inc/class-gateway.php',
    ];
    if ( isset( $map[ $class ] ) ) {
        require_once PROMPTPAY_DIR . $map[ $class ];
    }
});

// Boot plugin
add_action( 'plugins_loaded', [ 'PromptPay_Plugin', 'init' ] );

/**
 * Main Plugin Bootstrap
 */
final class PromptPay_Plugin {

    private static ?self $instance = null;

    public static function init(): void {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
    }

    private function __construct() {
        PromptPay_Settings::register();
        PromptPay_Assets::register();
        PromptPay_Shortcode::register();
        PromptPay_Ajax::register();
        self::register_gateway();
    }

    /**
     * ลงทะเบียน Payment Gateway กับ WooCommerce
     */
    private static function register_gateway(): void {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

        add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
            $gateways[] = 'PromptPay_Gateway';
            return $gateways;
        });
    }
}
