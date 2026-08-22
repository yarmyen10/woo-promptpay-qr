<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * KShop_QR_Generator
 * สร้าง Thai QR Payment (Biller) payload ตาม EMVCo standard
 * พร้อม amount pre-filled และ order ref ต่อ transaction
 */
class KShop_QR_Generator {

    private const AID    = 'A000000677010112'; // from decoded K-Shop QR
    private const SUB01  = '010753600031501';   // KBank internal identifier (from decoded QR)
    private const QR_API = 'https://api.qrserver.com/v1/create-qr-code/';

    /**
     * @param string $biller_id  เช่น "KB000002147245"
     * @param float  $amount     ยอดเงิน THB
     * @param string $ref        เลข order สำหรับ reconciliation (max 20 chars)
     * @return string            URL รูป QR
     */
    public static function generate( string $biller_id, float $amount, string $ref = '' ): string {
        $merchant = self::tlv( '00', self::AID )
            . self::tlv( '01', self::SUB01 )
            . self::tlv( '02', $biller_id )
            . ( $ref ? self::tlv( '03', substr( $ref, 0, 20 ) ) : '' );

        $amount_str = number_format( $amount, 2, '.', '' );

        $payload = self::tlv( '00', '01' )
            . self::tlv( '01', '12' )              // 12 = dynamic (amount embedded)
            . self::tlv( '30', $merchant )
            . self::tlv( '52', '0000' )
            . self::tlv( '53', '764' )             // THB
            . self::tlv( '54', $amount_str )
            . self::tlv( '58', 'TH' )
            . self::tlv( '59', 'JAONAICHAN' )
            . self::tlv( '60', 'CITY' )
            . '6304';                              // CRC tag placeholder

        $crc     = self::crc16( $payload );
        $payload .= strtoupper( str_pad( dechex( $crc ), 4, '0', STR_PAD_LEFT ) );

        return add_query_arg( [ 'size' => '300x300', 'data' => $payload ], self::QR_API );
    }

    private static function tlv( string $tag, string $value ): string {
        return $tag . str_pad( strlen( $value ), 2, '0', STR_PAD_LEFT ) . $value;
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
