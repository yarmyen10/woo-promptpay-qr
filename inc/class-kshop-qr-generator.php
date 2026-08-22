<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KShop_QR_Generator
 *
 * สร้าง dynamic K-Shop QR โดยเอา static payload ต้นฉบับมา
 * เปลี่ยน initiation 11→12 และแทรก tag 54 (amount) แล้ว recalculate CRC
 * ไม่ rebuild จาก scratch เพื่อรักษา structure ของ K-Shop ครบถ้วน
 */
class KShop_QR_Generator {

    // Static K-Shop payload ไม่รวม CRC value (ตัดท้าย 4 hex chars ออก, เหลือ '6304')
    private const STATIC_BASE = '0002010102110216478772000475103204155303920004751521531343007640052044640122250933100130810016A00000067701011201150107536000315010214KB0000021472450320KPS004KB00000214724531690016A00000067701011301030040214KB0000021472450420KPS004KB00000214724551430014A000000004101001064169710211123456789015204599553037645802TH5910JAONAICHAN6004CITY622505094794393940708422509336304';

    private const QR_API = 'https://api.qrserver.com/v1/create-qr-code/';

    /**
     * @param string $biller_id  ไม่ใช้ (baked ใน STATIC_BASE) — เก็บ signature ไว้ compatible กับ caller
     * @param float  $amount     ยอดเงิน THB
     * @param string $ref        ไม่ใช้ในตอนนี้
     */
    public static function generate( string $biller_id, float $amount, string $ref = '' ): string {
        // static → dynamic
        $payload = str_replace( '010211', '010212', self::STATIC_BASE );

        // แทรก tag 54 (amount) ก่อน tag 58 (country)
        $amount_str = number_format( $amount, 2, '.', '' );
        $amount_tlv = '54' . str_pad( strlen( $amount_str ), 2, '0', STR_PAD_LEFT ) . $amount_str;
        $payload    = str_replace( '5802TH', $amount_tlv . '5802TH', $payload );

        // คำนวณ CRC ใหม่
        $crc     = self::crc16( $payload );
        $payload .= strtoupper( str_pad( dechex( $crc ), 4, '0', STR_PAD_LEFT ) );

        return add_query_arg( [ 'size' => '300x300', 'data' => $payload ], self::QR_API );
    }

    private static function crc16( string $data ): int {
        $crc = 0xFFFF;
        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $crc ^= ( ord( $data[ $i ] ) << 8 );
            for ( $j = 0; $j < 8; $j++ ) {
                $crc = ( $crc & 0x8000 ) ? ( ( $crc << 1 ) ^ 0x1021 ) : ( $crc << 1 );
                $crc &= 0xFFFF;
            }
        }
        return $crc;
    }
}
