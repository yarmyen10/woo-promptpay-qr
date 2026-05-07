<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_Ajax
 * จัดการ AJAX request รับสลิปและตรวจสอบ
 */
class PromptPay_Ajax {

    public static function register(): void {
        add_action( 'wp_ajax_promptpay_verify_slip',        [ self::class, 'handle' ] );
        add_action( 'wp_ajax_nopriv_promptpay_verify_slip', [ self::class, 'handle' ] );
    }

    /**
     * Handle AJAX — ตรวจสอบสลิปที่ส่งมา
     */
    public static function handle(): void {
        check_ajax_referer( 'promptpay_upload_slip', 'nonce' );

        // ตรวจสอบไฟล์
        if ( empty( $_FILES['slip']['tmp_name'] ) ) {
            wp_send_json_error([ 'message' => 'ไม่พบไฟล์สลิป' ]);
        }

        $slip_tmp = $_FILES['slip']['tmp_name'];
        $order_id = intval( $_POST['order_id'] ?? 0 );
        $bill     = intval( $_POST['bill'] ?? 1 );
        $amount   = self::get_order_amount( $order_id );

        $mock     = ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_POST['mock_result'] ) )
                        ? filter_var( $_POST['mock_result'], FILTER_VALIDATE_BOOLEAN )
                        : null;
        $verifier = new PromptPay_Slip_Verify();
        $result   = $verifier->verify( $slip_tmp, $amount, $mock );

        // Save the slip in every case so admins can review it later.
        PromptPay_Slip_Verify::save_slip_file( $slip_tmp, $order_id, $bill );

        if ( $result['success'] ) {
            self::set_status(
                $order_id,
                'paid-' . $bill,
                sprintf( 'ตรวจสอบสลิปบิลที่ %d ผ่าน', $bill )
            );
            wp_send_json_success([
                'verify'   => true,
                'message'  => $result['message'],
                'redirect' => self::get_thankyou_url( $order_id ),
            ]);
        }

        self::set_status(
            $order_id,
            'wait-verify-' . $bill,
            sprintf( 'รอเจ้าหน้าที่ตรวจสอบสลิปบิลที่ %d (%s)', $bill, $result['message'] )
        );

        if ( ! empty( $result['manual'] ) ) {
            wp_send_json_success([
                'verify'  => false,
                'message' => $result['message'],
            ]);
        }

        wp_send_json_error([
            'verify'  => false,
            'message' => $result['message'],
        ]);
    }

    /**
     * ดึงยอด Order
     */
    private static function get_order_amount( int $order_id ): float {
        if ( $order_id && function_exists( 'wc_get_order' ) ) {
            $order = wc_get_order( $order_id );
            if ( $order ) return (float) $order->get_total();
        }
        return 0.0;
    }

    /**
     * อัปเดต status ของ order — ใช้ slug แบบไม่มี wc- prefix (Woo จะเติมให้เอง)
     */
    private static function set_status( int $order_id, string $status, string $note ): void {
        if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $order->update_status( $status, $note );
    }

    /**
     * URL หน้าขอบคุณ
     */
    private static function get_thankyou_url( int $order_id ): string {
        if ( $order_id && function_exists( 'wc_get_checkout_url' ) ) {
            return wc_get_endpoint_url( 'order-received', $order_id, wc_get_checkout_url() );
        }
        return home_url();
    }

}
