<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_REST_API
 *
 * Endpoints:
 *   GET  /wp-json/promptpay/v1/config
 *   POST /wp-json/promptpay/v1/config
 *   GET  /wp-json/promptpay/v1/qr?amount=500
 *   POST /wp-json/promptpay/v1/verify-slip
 *   GET  /wp-json/promptpay/v1/slip/{order_id}/{bill}
 */
class PromptPay_REST_API {

    private const NAMESPACE = 'promptpay/v1';

    public static function register(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
    }

    public static function register_routes(): void {

        // GET /config — ดึง phone + slipok_key
        register_rest_route( self::NAMESPACE, '/config', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_config' ],
            'permission_callback' => [ self::class, 'is_admin' ],
        ]);

        // POST /config — อัปเดต phone + slipok_key
        register_rest_route( self::NAMESPACE, '/config', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'update_config' ],
            'permission_callback' => [ self::class, 'is_admin' ],
            'args'                => [
                'phone'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'slipok_key' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ]);

        // GET /qr?amount=500 — สร้าง QR URL
        register_rest_route( self::NAMESPACE, '/qr', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'get_qr' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'amount' => [ 'type' => 'number', 'default' => 0 ],
            ],
        ]);

        // POST /verify-slip — ตรวจสอบสลิป (แทน AJAX)
        register_rest_route( self::NAMESPACE, '/verify-slip', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'verify_slip' ],
            'permission_callback' => '__return_true',
        ]);

        // GET /slip/{order_id}/{bill} — ดูไฟล์สลิป
        register_rest_route( self::NAMESPACE, '/slip/(?P<order_id>\d+)/(?P<bill>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ self::class, 'serve_slip' ],
            'permission_callback' => [ self::class, 'can_view_slip' ],
        ]);


        register_rest_route( self::NAMESPACE, '/me', [
            'methods'             => 'GET',
            'callback'            => function() {
                return rest_ensure_response([
                    'user_id' => get_current_user_id(),
                    'is_admin' => current_user_can('manage_options'),
                ]);
            },
            'permission_callback' => '__return_true',
        ]);
    }

    // =========================================================
    // Handlers
    // =========================================================

    /** GET /config */
    public static function get_config(): WP_REST_Response {
        $gateway = self::gateway();
        return rest_ensure_response([
            'phone'      => $gateway ? $gateway->get_option('phone')      : '',
            'slipok_key' => $gateway ? $gateway->get_option('slipok_key') : '',
        ]);
    }

    /** POST /config */
    public static function update_config( WP_REST_Request $req ): WP_REST_Response {
        $settings = get_option( 'woocommerce_promptpay_qr_settings', [] );

        if ( $req->has_param('phone') ) {
            $settings['phone'] = $req->get_param('phone');
        }
        if ( $req->has_param('slipok_key') ) {
            $settings['slipok_key'] = $req->get_param('slipok_key');
        }

        update_option( 'woocommerce_promptpay_qr_settings', $settings );

        return rest_ensure_response([ 'success' => true, 'settings' => $settings ]);
    }

    /** GET /qr?amount=500 */
    public static function get_qr( WP_REST_Request $req ): WP_REST_Response {
        $gateway = self::gateway();
        $phone   = $gateway ? $gateway->get_option('phone') : '';
        $amount  = (float) $req->get_param('amount');
        $qr_url  = PromptPay_QR_Generator::generate( $phone, $amount );

        return rest_ensure_response([
            'phone'  => $phone,
            'amount' => $amount,
            'qr_url' => $qr_url,
        ]);
    }

    /** POST /verify-slip */
    public static function verify_slip( WP_REST_Request $req ): WP_REST_Response {
        $files = $req->get_file_params();

        if ( empty( $files['slip']['tmp_name'] ) ) {
            return new WP_REST_Response([ 'success' => false, 'message' => 'ไม่พบไฟล์สลิป' ], 400);
        }

        $slip_tmp = $files['slip']['tmp_name'];
        $order_id = intval( $req->get_param('order_id') );
        $bill     = intval( $req->get_param('bill') ?? 1 );
        $amount   = self::get_order_amount( $order_id );

        $verifier = new PromptPay_Slip_Verify();
        $result   = $verifier->verify( $slip_tmp, $amount, $order_id ?: null, $bill );

        if ( $result['success'] ) {
            self::save_slip( $files['slip']['tmp_name'], $order_id, $bill );
            self::complete_order( $order_id, $bill );
            return rest_ensure_response([
                'success'  => true,
                'message'  => $result['message'],
                'redirect' => self::get_thankyou_url( $order_id ),
            ]);
        }

        return new WP_REST_Response([ 'success' => false, 'message' => $result['message'] ], 422);
    }

    /** GET /slip/{order_id}/{bill} */
    public static function serve_slip( WP_REST_Request $req ): void {
        $order_id = $req->get_param('order_id');
        $bill     = $req->get_param('bill');
        $relative = get_post_meta( $order_id, '_promptpay_slip_bill' . $bill, true );

        $upload_dir = wp_upload_dir();
        $filepath   = $upload_dir['basedir'] . '/' . $relative;

        // do_action('qm/info', '$order_id: ' . $order_id);
        // do_action('qm/info', '$bill: ' . $bill);
        // do_action('qm/info', '$relative: ' . $relative);
        // do_action('qm/info', '$upload_dir: ' . $upload_dir);
        // do_action('qm/info', '$filepath: ' . $filepath);

        if ( ! $relative || ! file_exists( $filepath ) ) {
            wp_send_json_error([ 
                'message'  => 'ไม่พบไฟล์',
                'basedir'  => $upload_dir['basedir'],
                'relative' => $relative,
                'filepath' => $filepath,
            ], 404);
        }

        header( 'Content-Type: '   . mime_content_type( $filepath ) );
        header( 'Content-Length: ' . filesize( $filepath ) );
        readfile( $filepath );
        exit;
    }

    // =========================================================
    // Helpers
    // =========================================================

    /** บันทึกไฟล์สลิปลง disk */
    private static function save_slip( string $tmp_file, int $order_id, int $bill ): void {
        $upload_dir = wp_upload_dir();
        $custom_dir = $upload_dir['basedir'] . '/slips/' . date('Y/m');
        wp_mkdir_p( $custom_dir );

        // ป้องกันเข้าถึงตรงๆ ผ่าน URL
        $htaccess = $custom_dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, 'deny from all' );
        }

        $filename = 'slip-' . $order_id . '-bill' . $bill . '-' . time() . '.jpg';
        $filepath = $custom_dir . '/' . $filename;
        move_uploaded_file( $tmp_file, $filepath );

        $relative = 'slips/' . date('Y/m') . '/' . $filename;
        update_post_meta( $order_id, '_promptpay_slip_bill' . $bill, $relative );
    }

    /** Mark order complete ตาม bill */
    private static function complete_order( int $order_id, int $bill = 1 ): void {
        if ( ! $order_id || ! function_exists('wc_get_order') ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        if ( $bill === 1 ) {
            $order->update_status( 'on-hold', 'ชำระบิลแรกผ่าน PromptPay แล้ว' );
        } else {
            $order->payment_complete();
            $order->update_status( 'processing', 'ชำระครบทุกบิลผ่าน PromptPay แล้ว' );
        }
    }

    /** ดึงยอด Order */
    private static function get_order_amount( int $order_id ): float {
        if ( ! $order_id || ! function_exists('wc_get_order') ) return 0.0;
        $order = wc_get_order( $order_id );
        return $order ? (float) $order->get_total() : 0.0;
    }

    /** URL หน้าขอบคุณ */
    private static function get_thankyou_url( int $order_id ): string {
        if ( $order_id && function_exists('wc_get_checkout_url') ) {
            return wc_get_endpoint_url( 'order-received', $order_id, wc_get_checkout_url() );
        }
        return home_url();
    }

    /** ดึง Gateway instance */
    private static function gateway(): ?object {
        if ( ! function_exists('WC') ) return null;
        return WC()->payment_gateways->payment_gateways()['promptpay_qr'] ?? null;
    }

    // =========================================================
    // Permissions
    // =========================================================

    public static function is_admin(): bool {
        return current_user_can('manage_options');
    }

    /** Admin หรือเจ้าของ Order เท่านั้น */
    public static function can_view_slip( WP_REST_Request $req ): bool {

        // แบบที่ 1 — Nonce (เรียกจาก theme/WordPress page)
        $nonce = $req->get_header('X-WP-Nonce');
        if ( $nonce && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            $order = wc_get_order( $req->get_param('order_id') );
            if ( ! $order ) return false;

            // Admin หรือเจ้าของ order
            if ( current_user_can('manage_options') ) return true;
            return (int) $order->get_customer_id() === get_current_user_id();
        }

        // แบบที่ 2 — Application Password (เรียกจาก TailAdmin)
        $auth = $req->get_header('Authorization');
        if ( $auth && str_starts_with( $auth, 'Basic ' ) ) {
            $credentials = base64_decode( substr( $auth, 6 ) );
            [ $username, $password ] = explode( ':', $credentials, 2 );

            $user = wp_authenticate_application_password( null, $username, $password );
            if ( is_wp_error( $user ) ) return false;

            return user_can( $user, 'manage_options' );
        }

        return false;
    }
}
