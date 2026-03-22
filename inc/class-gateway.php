<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_Gateway
 * ลงทะเบียน PromptPay QR เป็น Payment Method ใน WooCommerce
 * ปรากฏใน WooCommerce → Settings → Payments
 */
class PromptPay_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'promptpay_qr';
        $this->icon               = ''; // ใส่ URL รูปโลโก้ได้
        $this->has_fields         = true; // แสดง custom form ใน checkout
        $this->method_title       = 'PromptPay QR';
        $this->method_description = 'ชำระเงินผ่าน PromptPay QR Code พร้อมแนบสลิปยืนยัน';

        // add_filter( 'woocommerce_gateway_icon', [ $this, 'custom_icon' ], 10, 2 );

        // โหลด settings
        $this->init_form_fields();
        $this->init_settings();

        // Map settings → properties
        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->phone       = $this->get_option( 'phone' );
        $this->slipok_key  = $this->get_option( 'slipok_key' );

        // Sync ค่ากับ wp_options ที่ Settings Page ใช้ร่วมกัน
        // update_option( 'promptpay_phone',      $this->phone );
        // update_option( 'promptpay_slipok_key', $this->slipok_key );

        // Hook บันทึก settings จาก WooCommerce Admin
        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [ $this, 'process_admin_options' ]
        );
    }

    public function get_title(): string {
        $icon = '<img src="' . plugin_dir_url(dirname( __FILE__ )) . 'assets/imgs/prompt-pay-logo.jpg"
            style="width:25%; height:auto; vertical-align:middle; margin-right:8px;" />';

        return $icon . $this->title;  // ใช้ $this->title แทน hardcode
    }

    public function get_icon(): string {
        return '';
    }

    // public function custom_icon( string $icon, string $gateway_id ): string {
    //     if ( $gateway_id !== $this->id ) return $icon;

    //     return '<img src="https://upload.wikimedia.org/wikipedia/commons/c/c5/PromptPay-logo.png"
    //                 alt="PromptPay"
    //                 width="80"
    //                 height="auto"
    //                 style="vertical-align:middle;" />';
    // }

    /**
     * ฟิลด์ตั้งค่าใน WooCommerce → Settings → Payments → PromptPay QR
     */
    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'เปิดใช้งาน',
                'type'    => 'checkbox',
                'label'   => 'เปิดใช้ PromptPay QR',
                'default' => 'yes',
            ],
            'title' => [
                'title'       => 'ชื่อที่แสดงในหน้า Checkout',
                'type'        => 'text',
                'default'     => 'ชำระผ่าน PromptPay QR',
                'description' => 'ชื่อที่ลูกค้าเห็นตอนเลือกวิธีชำระเงิน',
                'desc_tip'    => true,
            ],
            'description' => [
                'title'   => 'คำอธิบาย',
                'type'    => 'textarea',
                'default' => 'สแกน QR Code แล้วแนบสลิปเพื่อยืนยันการชำระเงิน',
            ],
            'phone' => [
                'title'       => 'เบอร์ PromptPay',
                'type'        => 'text',
                'placeholder' => '0812345678',
                'description' => 'เบอร์โทรที่ผูกกับบัญชี PromptPay',
                'desc_tip'    => true,
            ],
            'slipok_key' => [
                'title'       => 'SlipOK API Key',
                'type'        => 'text',
                'description' => 'รับได้ฟรีที่ <a href="https://slipok.com" target="_blank">slipok.com</a> (100 ครั้ง/เดือน) — ถ้าว่างจะรอ Admin อนุมัติแทน',
            ],
        ];
    }

    /**
     * แสดง QR Code + Form Upload สลิป ในหน้า Checkout
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo '<p>' . esc_html( $this->description ) . '</p>';
        }

        $amount   = (float) WC()->cart->get_total( 'edit' );
        $phone    = $this->phone;
        $order_id = (int) ( WC()->session ? WC()->session->get( 'order_awaiting_payment', 0 ) : 0 );
        $qr_url   = PromptPay_QR_Generator::generate( $phone, $amount );
        $nonce    = wp_create_nonce( 'promptpay_upload_slip' );
        $ajax_url = admin_url( 'admin-ajax.php' );

        // include PROMPTPAY_DIR . 'inc/templates/payment-form.php';
    }

    /**
     * Process payment เมื่อลูกค้ากด "Place Order"
     * — สลิปจะถูกตรวจผ่าน AJAX ก่อนกด Place Order อยู่แล้ว
     * — ตรงนี้เป็น fallback กรณีที่ยังไม่ได้ตรวจ
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );

        // ถ้าสลิปผ่านแล้ว (AJAX set status ไว้แล้ว) → redirect ไป thank you
        if ( in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];
        }

        // ถ้ายังไม่ได้ตรวจ → ตรวจ slip ที่แนบมากับ $_FILES
        $slip_tmp = $_FILES['slip']['tmp_name'] ?? '';

        if ( $slip_tmp ) {
            $verifier = new PromptPay_Slip_Verify();
            $result   = $verifier->verify( $slip_tmp, (float) $order->get_total(), $order_id );

            if ( $result['success'] ) {
                $order->payment_complete();
                $order->update_status( 'processing', 'ชำระเงินผ่าน PromptPay สำเร็จ' );
                return [
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order ),
                ];
            }

            wc_add_notice( '❌ ' . $result['message'], 'error' );
            return [ 'result' => 'fail' ];
        }

        // ไม่มีสลิป → รอ Admin (on-hold)
        $order->update_status( 'on-hold', 'รอลูกค้าแนบสลิปการโอนเงิน' );
        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        ];
    }
}
