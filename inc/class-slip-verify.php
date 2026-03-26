<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_Slip_Verify
 * ตรวจสอบสลิปผ่าน SlipOK API หรือบันทึกรอ Admin อนุมัติ
 */
class PromptPay_Slip_Verify {

    private const SLIPOK_API = 'https://api.slipok.com/api/line/apikey/';

    private string $api_key;

    public function __construct() {
        $this->api_key = (string) get_option( 'promptpay_slipok_key', '' );
    }

    /**
     * Entry point — เลือก verify แบบอัตโนมัติ หรือ manual
     *
     * @param string   $tmp_file  path ของไฟล์สลิปที่ upload
     * @param float    $amount    ยอดที่คาดว่าต้องตรง
     * @param int|null $order_id  WooCommerce order ID
     * @return array{ success: bool, message: string }
     */
    public function verify( string $tmp_file, float $amount, ?int $order_id = null ): array {
        if ( $this->api_key ) {
            return $this->verify_via_slipok( $tmp_file, $amount );
        }

        return $this->save_for_manual_review( $tmp_file, $order_id );
    }

    /**
     * ตรวจสอบสลิปผ่าน SlipOK API
     */
    private function verify_via_slipok( string $tmp_file, float $expected_amount ): array {
        $response = wp_remote_post( self::SLIPOK_API . $this->api_key, [
            'timeout' => 30,
            'body'    => [
                'files' => new CURLFile( $tmp_file ),
                'log'   => true,
            ],
        ]);

        if ( is_wp_error( $response ) ) {
            return $this->fail( 'เชื่อมต่อ SlipOK API ไม่ได้: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['success'] ) ) {
            $reason = $body['message'] ?? 'อ่านสลิปไม่ได้';
            return $this->fail( 'สลิปไม่ถูกต้อง: ' . $reason );
        }

        $slip_amount = floatval( $body['data']['amount'] ?? 0 );

        if ( $expected_amount > 0 && $slip_amount !== $expected_amount ) {
            return $this->fail(
                sprintf( 'ยอดเงินในสลิป (%.2f) ไม่ตรงกับยอดสั่งซื้อ (%.2f)', $slip_amount, $expected_amount )
            );
        }

        return $this->ok( 'ชำระเงินสำเร็จ! ขอบคุณครับ', $body['data'] );
    }

    /**
     * บันทึกสลิปและเปลี่ยน Order เป็น on-hold รอ Admin
     */
    private function save_for_manual_review( string $tmp_file, ?int $order_id ): array {

        // กำหนด custom path
        $upload_dir = wp_upload_dir();
        $custom_dir = $upload_dir['basedir'] . '/slips/' . date('Y/m');
        
        // สร้างโฟลเดอร์ถ้ายังไม่มี
        wp_mkdir_p( $custom_dir );

        // ป้องกันเข้าถึงตรงๆ ผ่าน URL
        file_put_contents( $custom_dir . '/.htaccess', 'deny from all' );

        $filename = 'slip-' . ( $order_id ?? 'noid' ) . '-' . time() . '.jpg';
        $filepath = $custom_dir . '/' . $filename;

        // copy ไฟล์ไปไว้ที่ path ใหม่
        move_uploaded_file( $tmp_file, $filepath );

        // เก็บแค่ relative path ใน DB
        $relative = 'slips/' . date('Y/m') . '/' . $filename;
        
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_status( 'on-hold', 'รอ Admin ตรวจสอบสลิป' );
                update_post_meta( $order_id, '_promptpay_slip_path', $relative );
            }
        }

        return $this->ok( 'ส่งสลิปเรียบร้อย รอเจ้าหน้าที่ตรวจสอบ' );
    }

    // ---- Helpers ----

    private function ok( string $message, array $data = [] ): array {
        return [ 'success' => true, 'message' => $message, 'data' => $data ];
    }

    private function fail( string $message ): array {
        return [ 'success' => false, 'message' => $message ];
    }
}
