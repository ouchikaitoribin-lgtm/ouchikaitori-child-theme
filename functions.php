<?php
/**
 * XeoryBase Child Theme
 */

// 親テーマの読み込みが完了した後、ショートコードを再登録
add_action('init', function () {

    // [sc] ショートコード
    remove_shortcode('sc');

    add_shortcode('sc', function ($atts) {

        $atts = shortcode_atts([
            'file' => 'default',
        ], $atts, 'sc');

        $name = $atts['file'];

        // ファイル名に使用できる文字を制限
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            return '';
        }

        $relative = $name . '.php';

        // 子テーマ → 親テーマの順で検索
        $file = get_stylesheet_directory() . '/' . $relative;

        if (!is_file($file)) {
            $file = get_template_directory() . '/' . $relative;
        }

        if (!is_file($file)) {
            return '';
        }

        ob_start();
        include $file;
        return ob_get_clean();
    });

    // [include_php] ショートコード
    remove_shortcode('include_php');

    add_shortcode('include_php', function ($atts) {

        $atts = shortcode_atts([
            'file'    => '',
            'service' => '',
            'coupon'  => '',
        ], $atts, 'include_php');

        $allowed_files = [
            'campaign'      => 'parts_campaign_display.php',
            'campaign_kiji' => 'campaign_kiji.php',
        ];

        if (!isset($allowed_files[$atts['file']])) {
            return '';
        }

        $file = get_stylesheet_directory() . '/' . $allowed_files[$atts['file']];

        if (!is_file($file)) {
            $file = get_template_directory() . '/' . $allowed_files[$atts['file']];
        }

        if (!is_file($file)) {
            return '';
        }

        $service = $atts['service'];
        $coupon  = $atts['coupon'];

        ob_start();
        include $file;
        return ob_get_clean();
    });
}, 20);

// 出張買取の申込APIはステージング環境だけで読み込みます。
// 既存の宅配買取処理には接続しません。
if (function_exists('wp_get_environment_type') && 'staging' === wp_get_environment_type()) {
    $visit_application_backend = get_stylesheet_directory() . '/visit-lp-draft/plugin/visit-application-backend.php';
    if (is_readable($visit_application_backend)) {
        require_once $visit_application_backend;
    }
}
