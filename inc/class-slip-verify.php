<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_Slip_Verify
 * ตรวจสอบสลิปผ่าน SlipOK API หรือบันทึกรอ Admin อนุมัติ
 */
class PromptPay_Slip_Verify {

    private const SLIPOK_API_DEFAULT = 'https://api.slipok.com/api/line/apikey/';
    public  const META_KEY   = '_promptpay_slip_bill';

    private string $api_key;

    public function __construct() {
        $this->api_key  = (string) get_option( 'promptpay_slipok_key', '' );
        $this->endpoint = (string) get_option( 'promptpay_slipok_endpoint', self::SLIPOK_API_DEFAULT );
        if ( $this->endpoint === '' ) {
            $this->endpoint = self::SLIPOK_API_DEFAULT;
        }
    }

    /**
     * Entry point — verify อัตโนมัติผ่าน SlipOK ถ้ามี API key, ไม่งั้น return manual flag
     * $mock: WP_DEBUG เท่านั้น — true = สำเร็จ, false = ล้มเหลว, null = ปกติ
     *
     * @return array{ success: bool, message: string, manual?: bool, data?: array }
     */
    public function verify( string $tmp_file, float $amount, ?bool $mock = null ): array {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $mock !== null ) {
            return $mock
                ? $this->ok( '[mock] ชำระเงินสำเร็จ' )
                : $this->fail( '[mock] สลิปไม่ผ่าน' );
        }

        if ( $this->api_key ) {
            return $this->verify_via_slipok( $tmp_file, $amount );
        }

        return [
            'success' => false,
            'manual'  => true,
            'message' => 'รอเจ้าหน้าที่ตรวจสอบสลิป',
        ];
    }

    /**
     * บันทึกไฟล์สลิปลง disk + เก็บ relative path ใน order meta (HPOS-compatible)
     * Returns false ถ้า move_uploaded_file ล้มเหลว หรือหา order ไม่เจอ
     */
    public static function save_slip_file( string $tmp_file, int $order_id, int $bill ): bool {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return false;

        $upload_dir   = wp_upload_dir();
        $relative_dir = 'slips/' . date( 'Y/m' );
        $custom_dir   = $upload_dir['basedir'] . '/' . $relative_dir;
        wp_mkdir_p( $custom_dir );

        // ป้องกันเข้าถึงตรงๆ ผ่าน URL
        $htaccess = $custom_dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, 'deny from all' );
        }

        $filename = 'slip-' . $order_id . '-bill' . $bill . '-' . time() . '.jpg';
        $filepath = $custom_dir . '/' . $filename;

        if ( ! move_uploaded_file( $tmp_file, $filepath ) ) return false;

        $relative = $relative_dir . '/' . $filename;
        $order->update_meta_data( self::META_KEY . $bill, $relative );
        $order->save();

        return true;
    }

    /**
     * อ่าน relative path ของสลิปจาก order meta
     */
    public static function get_slip_path( int $order_id, int $bill ): string {
        $order = wc_get_order( $order_id );
        return $order ? (string) $order->get_meta( self::META_KEY . $bill, true ) : '';
    }

    /**
     * ตรวจสอบสลิปผ่าน SlipOK API
     */
    private function verify_via_slipok( string $tmp_file, float $expected_amount ): array {
        $uri = trailingslashit( $this->endpoint ) . $this->api_key;

        $ch = curl_init( $uri );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [ 'files' => new CURLFile( $tmp_file ), 'log' => 'true' ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw_body  = curl_exec( $ch );
        $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_err  = curl_error( $ch );
        curl_close( $ch );

        if ( $raw_body === false || $curl_err ) {
            $this->log_slipok( $uri, null, null, $curl_err );
            return $this->fail( 'เชื่อมต่อ SlipOK API ไม่ได้: ' . $curl_err );
        }

        $body = json_decode( $raw_body, true );
        $this->log_slipok( $uri, $http_code, $body, null );

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

    // ---- Helpers ----

    private function ok( string $message, array $data = [] ): array {
        return [ 'success' => true, 'message' => $message, 'data' => $data ];
    }

    private function fail( string $message ): array {
        return [ 'success' => false, 'message' => $message ];
    }

    private function log_slipok( string $uri, ?int $http_code, ?array $body, ?string $curl_error ): void {
        $upload_dir = wp_upload_dir();
        $log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'slips';

        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        $log_file = $log_dir . '/slip-log-' . wp_date( 'Y-m-d' ) . '.log';

        $entry = [
            'time'   => wp_date( 'Y-m-d H:i:s' ),
            'method' => 'POST',
            'uri'    => $uri,
        ];

        if ( $curl_error !== null ) {
            $entry['curl_error'] = $curl_error;
        } else {
            $entry['http_code'] = $http_code;
            $entry['body']      = $body;
        }

        file_put_contents( $log_file, json_encode( $entry, JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND | LOCK_EX );
    }
}
