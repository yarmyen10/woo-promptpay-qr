<?php
/**
 * Template: QR Code + Upload สลิป
 *
 * Variables ที่ส่งมาจาก PromptPay_Shortcode::render():
 * @var string $qr_url    URL รูป QR Code
 * @var float  $amount    ยอดเงิน
 * @var string $phone     เบอร์ PromptPay
 * @var int    $order_id  WooCommerce order ID
 * @var string $nonce     WordPress nonce
 * @var string $ajax_url  admin-ajax.php URL
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div id="promptpay-wrap" class="pp-wrap">

    <!-- ===== QR Section ===== -->
    <div class="pp-qr-section">
        <div class="pp-badge">PromptPay</div>

        <img class="pp-qr-img"
             src="<?= esc_url( $qr_url ) ?>"
             alt="QR PromptPay" />

        <div class="pp-amount">
            ยอดชำระ
            <span><?= number_format( $amount, 2 ) ?> <em>บาท</em></span>
        </div>

        <div class="pp-phone">
            โอนมาที่ <strong><?= esc_html( $phone ) ?></strong>
        </div>
    </div>

    <!-- ===== Upload Section ===== -->
    <div class="pp-upload-section">

        <!-- Drop Zone -->
        <div id="pp-drop-zone" class="pp-drop-zone">
            <div class="pp-drop-icon">📎</div>
            <p class="pp-drop-text">
                ลากไฟล์สลิปมาวางที่นี่
                <small>หรือคลิกเพื่อเลือกไฟล์</small>
            </p>
            <input type="file"
                   id="pp-file-input"
                   accept="image/*"
                   style="display:none;" />
        </div>

        <!-- Preview -->
        <div id="pp-preview-wrap" class="pp-preview-wrap" style="display:none;">
            <img id="pp-preview-img" src="" alt="สลิป Preview" />
            <button id="pp-change-btn" type="button">✕ เปลี่ยนสลิป</button>
        </div>

        <!-- Submit -->
        <button id="pp-submit-btn"
                class="pp-submit-btn"
                type="button"
                data-nonce="<?= esc_attr( $nonce ) ?>"
                data-order="<?= esc_attr( $order_id ) ?>"
                disabled>
            <span class="pp-btn-text">ส่งสลิปเพื่อยืนยัน</span>
            <span class="pp-btn-loading" style="display:none;">กำลังตรวจสอบ…</span>
        </button>

        <!-- Result Message -->
        <div id="pp-result" class="pp-result" style="display:none;"></div>

    </div><!-- /.pp-upload-section -->

</div><!-- /#promptpay-wrap -->
