<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Class PromptPay_Settings
 * Admin Settings Page: Settings → PromptPay QR
 */
class PromptPay_Settings {

    private const OPTION_GROUP = 'promptpay_qr_group';

    public static function register(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu' ] );
        add_action( 'admin_init', [ self::class, 'register_options' ] );
    }

    public static function add_menu(): void {
        add_options_page(
            'PromptPay QR Settings',
            'PromptPay QR',
            'manage_options',
            'promptpay-qr-settings',
            [ self::class, 'render_page' ]
        );
    }

    public static function register_options(): void {
        register_setting( self::OPTION_GROUP, 'promptpay_phone',      [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::OPTION_GROUP, 'promptpay_slipok_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>⚙️ PromptPay QR Settings</h1>

            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="promptpay_phone">เบอร์ PromptPay</label></th>
                        <td>
                            <input type="text"
                                   id="promptpay_phone"
                                   name="promptpay_phone"
                                   value="<?= esc_attr( get_option( 'promptpay_phone' ) ) ?>"
                                   placeholder="0812345678"
                                   class="regular-text" />
                            <p class="description">เบอร์โทรที่ผูกกับ PromptPay</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="promptpay_slipok_key">SlipOK API Key</label></th>
                        <td>
                            <input type="text"
                                   id="promptpay_slipok_key"
                                   name="promptpay_slipok_key"
                                   value="<?= esc_attr( get_option( 'promptpay_slipok_key' ) ) ?>"
                                   class="regular-text" />
                            <p class="description">
                                สมัครฟรีได้ที่ <a href="https://slipok.com" target="_blank">slipok.com</a>
                                (ฟรี 100 ครั้ง/เดือน) — ถ้าไม่ใส่ ระบบจะรอ Admin อนุมัติแทน
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'บันทึกการตั้งค่า' ); ?>
            </form>

            <hr>
            <h3>📋 วิธีใช้งาน Shortcode</h3>
            <table class="widefat" style="max-width:600px;">
                <thead><tr><th>Shortcode</th><th>การทำงาน</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>[promptpay_qr]</code></td>
                        <td>ดึงยอดจาก WooCommerce Cart อัตโนมัติ</td>
                    </tr>
                    <tr>
                        <td><code>[promptpay_qr phone="0812345678" amount="500"]</code></td>
                        <td>ระบุเบอร์และยอดเงินเอง</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}
