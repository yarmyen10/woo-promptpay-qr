<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_Shortcode
 * ลงทะเบียนและ render [promptpay_qr]
 */
class PromptPay_Shortcode {

    public static function register(): void {
        add_shortcode( 'promptpay_qr', [ self::class, 'render' ] );
    }

    /**
     * Render shortcode
     * [promptpay_qr]
     * [promptpay_qr phone="0812345678" amount="500"]
     */
    public static function render( array $atts ): string {
        $atts = shortcode_atts([
            'phone'  => get_option( 'promptpay_phone', '' ),
            'amount' => '',
        ], $atts, 'promptpay_qr' );

        // ดึงยอดจาก WooCommerce Cart ถ้าไม่ได้ระบุ
        if ( $atts['amount'] === '' && function_exists( 'WC' ) && WC()->cart ) {
            $atts['amount'] = WC()->cart->get_total( 'edit' );
        }

        if ( empty( $atts['phone'] ) ) {
            return '<p class="pp-error">⚠️ กรุณาตั้งค่าเบอร์ PromptPay ใน Settings → PromptPay QR</p>';
        }

        $phone    = sanitize_text_field( $atts['phone'] );
        $amount   = (float) $atts['amount'];
        $qr_url   = PromptPay_QR_Generator::generate( $phone, $amount );
        $order_id = self::get_pending_order_id();
        $nonce    = wp_create_nonce( 'promptpay_upload_slip' );
        $ajax_url = admin_url( 'admin-ajax.php' );

        ob_start();
        include PROMPTPAY_DIR . 'inc/templates/payment-form.php';
        return ob_get_clean();
    }

    /**
     * ดึง Order ID ที่รอชำระจาก WooCommerce Session
     */
    private static function get_pending_order_id(): int {
        if ( function_exists( 'WC' ) && WC()->session ) {
            return (int) WC()->session->get( 'order_awaiting_payment', 0 );
        }
        return 0;
    }
}
