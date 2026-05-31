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
 *   GET    /wp-json/promptpay/v1/slip/{order_id}/{bill}
 *   DELETE /wp-json/promptpay/v1/slip/{order_id}/{bill}
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

        // POST /config — อัปเดต phone + slipok_key + slipok_endpoint
        register_rest_route( self::NAMESPACE, '/config', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'update_config' ],
            'permission_callback' => [ self::class, 'is_admin' ],
            'args'                => [
                'phone'            => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'slipok_key'       => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'slipok_endpoint'  => [ 'type' => 'string', 'sanitize_callback' => 'esc_url_raw' ],
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

        // DELETE /slip/{order_id}/{bill} — ลบไฟล์สลิปและ order meta
        register_rest_route( self::NAMESPACE, '/slip/(?P<order_id>\d+)/(?P<bill>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ self::class, 'delete_slip' ],
            'permission_callback' => [ self::class, 'is_admin' ],
        ]);

        // POST /re-verify/{order_id}/{bill} — ส่งสลิปที่เก็บไว้ไปตรวจสอบ SlipOK อีกครั้ง
        register_rest_route( self::NAMESPACE, '/re-verify/(?P<order_id>\d+)/(?P<bill>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 're_verify_slip' ],
            'permission_callback' => [ self::class, 'is_admin' ],
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
            'phone'           => $gateway ? $gateway->get_option('phone')           : '',
            'slipok_key'      => $gateway ? $gateway->get_option('slipok_key')      : '',
            'slipok_endpoint' => $gateway ? $gateway->get_option('slipok_endpoint') : '',
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
        if ( $req->has_param('slipok_endpoint') ) {
            $settings['slipok_endpoint'] = $req->get_param('slipok_endpoint');
        }

        update_option( 'woocommerce_promptpay_qr_settings', $settings );

        // Sync standalone options read by PromptPay_Slip_Verify
        if ( isset( $settings['phone'] ) )           update_option( 'promptpay_phone',            $settings['phone'] );
        if ( isset( $settings['slipok_key'] ) )      update_option( 'promptpay_slipok_key',       $settings['slipok_key'] );
        if ( isset( $settings['slipok_endpoint'] ) ) update_option( 'promptpay_slipok_endpoint',  $settings['slipok_endpoint'] );

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
        $order_id      = intval( $req->get_param('order_id') );
        $bill          = intval( $req->get_param('bill') ?? 1 );
        $passed_amount = (float) ( $req->get_param('amount') ?? 0 );
        $amount        = $passed_amount > 0 ? $passed_amount : self::get_order_amount( $order_id );

        $mock_param = $req->get_param('mock_result');
        $mock       = ( defined( 'WP_DEBUG' ) && WP_DEBUG && $mock_param !== null )
                        ? filter_var( $mock_param, FILTER_VALIDATE_BOOLEAN )
                        : null;
        $verifier = new PromptPay_Slip_Verify();
        $result   = $verifier->verify( $slip_tmp, $amount, $mock );

        if ( $result['success'] ) {
            PromptPay_Slip_Verify::save_slip_file( $slip_tmp, $order_id, $bill );
            self::complete_order( $order_id, $bill );
            return rest_ensure_response([
                'success'  => true,
                'message'  => $result['message'],
                'redirect' => self::get_thankyou_url( $order_id ),
            ]);
        }

        return new WP_REST_Response([ 'success' => false, 'message' => $result['message'] ], 422);
    }

    /** GET /slip/{order_id}/{bill} — streams ไฟล์โดยตรง (ไม่ใช่ JSON response) */
    public static function serve_slip( WP_REST_Request $req ) {
        $order_id = (int) $req->get_param('order_id');
        $bill     = (int) $req->get_param('bill');
        $relative = PromptPay_Slip_Verify::get_slip_path( $order_id, $bill );

        $upload_dir = wp_upload_dir();
        $filepath   = $upload_dir['basedir'] . '/' . $relative;

        if ( ! $relative || ! file_exists( $filepath ) ) {
            return new WP_Error( 'slip_not_found', 'ไม่พบไฟล์สลิป', [ 'status' => 404 ] );
        }

        // ล้าง output buffer ทั้งหมดที่ WordPress เปิดทิ้งไว้
        // (ถ้าไม่ล้าง buffered content จะ prefix ไปกับ binary ทำให้ image เสีย)
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        $mime = mime_content_type( $filepath ) ?: 'application/octet-stream';

        header( 'Content-Type: '   . $mime );
        header( 'Content-Length: ' . filesize( $filepath ) );
        header( 'Cache-Control: private, no-transform' );
        readfile( $filepath );
        exit;
    }

    /** DELETE /slip/{order_id}/{bill} — ลบไฟล์สลิปจาก disk, ล้าง slip meta และ reset bill meta */
    public static function delete_slip( WP_REST_Request $req ): WP_REST_Response {
        $order_id = (int) $req->get_param('order_id');
        $bill     = (int) $req->get_param('bill');

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'ไม่พบ Order' ], 404 );
        }

        // ลบไฟล์สลิปจาก disk
        $relative = PromptPay_Slip_Verify::get_slip_path( $order_id, $bill );
        if ( $relative ) {
            $upload_dir = wp_upload_dir();
            $filepath   = $upload_dir['basedir'] . '/' . $relative;
            if ( file_exists( $filepath ) ) {
                wp_delete_file( $filepath );
            }
        }

        // ล้าง slip path meta (plugin)
        $order->delete_meta_data( PromptPay_Slip_Verify::META_KEY . $bill );

        // reset bill meta (child theme)
        $order->update_meta_data( "_bill{$bill}_status",  'pending' );
        $order->update_meta_data( "_bill{$bill}_paid_at", '' );

        $order->save();

        return rest_ensure_response( [ 'success' => true ] );
    }

    /** POST /re-verify/{order_id}/{bill} — ส่งสลิปที่เก็บไว้ไปตรวจสอบ SlipOK อีกครั้ง */
    public static function re_verify_slip( WP_REST_Request $req ): WP_REST_Response {
        $order_id = (int) $req->get_param('order_id');
        $bill     = (int) $req->get_param('bill');

        if ( $bill !== 1 && $bill !== 2 ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'bill ต้องเป็น 1 หรือ 2' ], 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'ไม่พบ Order' ], 404 );
        }

        $relative = PromptPay_Slip_Verify::get_slip_path( $order_id, $bill );
        if ( ! $relative ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'ไม่พบสลิปใน Order นี้' ], 404 );
        }

        $upload_dir = wp_upload_dir();
        $filepath   = $upload_dir['basedir'] . '/' . $relative;
        if ( ! file_exists( $filepath ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'ไม่พบไฟล์สลิปบน server' ], 404 );
        }

        $amount   = self::get_order_amount( $order_id );
        $verifier = new PromptPay_Slip_Verify();
        $result   = $verifier->verify( $filepath, $amount );

        if ( $result['success'] ) {
            self::complete_order( $order_id, $bill );
            return rest_ensure_response( [
                'success' => true,
                'message' => $result['message'],
            ] );
        }

        return new WP_REST_Response( [
            'success' => false,
            'message' => $result['message'],
        ], 422 );
    }

    // =========================================================
    // Helpers
    // =========================================================

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

    /** อ่าน Authorization header แบบ fallback (Apache บางตัว/php-fpm/proxy รบกวน $_SERVER) */
    private static function read_auth_header( WP_REST_Request $req ): string {
        $auth = (string) $req->get_header('Authorization');
        if ( $auth !== '' ) return $auth;

        if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            return (string) $_SERVER['HTTP_AUTHORIZATION'];
        }
        if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ( function_exists('apache_request_headers') ) {
            $headers = apache_request_headers();
            if ( is_array( $headers ) ) {
                return (string) ( $headers['Authorization'] ?? $headers['authorization'] ?? '' );
            }
        }
        return '';
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
        $auth = self::read_auth_header( $req );
        if ( $auth && str_starts_with( $auth, 'Basic ' ) ) {
            $credentials = base64_decode( substr( $auth, 6 ) );
            [ $username, $password ] = explode( ':', $credentials, 2 );

            $user = wp_authenticate_application_password( null, $username, $password );
            if ( is_wp_error( $user ) ) return false;

            return user_can( $user, 'manage_options' );
        }

        // แบบที่ 3 — JWT Bearer (เรียกจาก bigboss SPA)
        // JWT Auth plugin authenticate ผ่าน determine_current_user filter ไปแล้ว
        // gating ตรงกับ jaonaichan/v1/orders ที่ใช้ is_user_logged_in() อย่างเดียว
        if ( $auth && str_starts_with( $auth, 'Bearer ' ) && is_user_logged_in() ) {
            return true;
        }

        return false;
    }
}
