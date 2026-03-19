<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_QR_Generator
 * สร้าง QR Code URL สำหรับ PromptPay
 */
class PromptPay_QR_Generator {

    private const API_BASE = 'https://promptpay.io/';

    /**
     * สร้าง QR URL จากเบอร์โทรและยอดเงิน
     *
     * @param string $phone   เบอร์โทร เช่น 0812345678
     * @param float  $amount  ยอดเงิน (0 = ไม่ระบุยอด)
     * @return string         URL รูป QR Code
     */
    public static function generate( string $phone, float $amount = 0 ): string {
        $phone_id = self::normalize_phone( $phone );
        $url      = self::API_BASE . $phone_id;

        if ( $amount > 0 ) {
            $url .= '/' . number_format( $amount, 2, '.', '' );
        }

        return $url;
    }

    /**
     * แปลงเบอร์ไทยเป็น format PromptPay
     * 0812345678 → 66812345678
     */
    private static function normalize_phone( string $phone ): string {
        $phone = preg_replace( '/\D/', '', $phone ); // เอาแต่ตัวเลข
        return '66' . ltrim( $phone, '0' );
    }
}
