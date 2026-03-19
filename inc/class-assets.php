<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_Assets
 * Enqueue CSS และ JS
 */
class PromptPay_Assets {

    public static function register(): void {
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    public static function enqueue(): void {
        wp_enqueue_style(
            'promptpay-qr',
            PROMPTPAY_URL . 'assets/css/promptpay.css',
            [],
            PROMPTPAY_VERSION
        );

        wp_enqueue_script(
            'promptpay-qr',
            PROMPTPAY_URL . 'assets/js/promptpay.js',
            [ 'jquery' ],
            PROMPTPAY_VERSION,
            true // load in footer
        );

        // ส่ง ajax_url เข้า JS
        wp_localize_script( 'promptpay-qr', 'PromptPayData', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ]);
    }
}
