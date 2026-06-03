<?php
/**
 * Plugin Name: money1616 iPhone価格テーブル
 * Plugin URI:  https://money1616.com/
 * Description: Google Drive上の価格JSONを元に、キャリア×iPhone機種の価格テーブルを管理画面で自由に作成し、ショートコードやブロックで記事に挿入できます。キャリア追加はJSON＋設定だけで対応（コード編集不要）。毎日、価格が当日更新されている場合のみ対象記事の更新日を進めます。
 * Version:     2.4.0
 * Author:      money1616
 * License:     GPL-2.0+
 * Text Domain: m1616-iphone-price
 *
 * ============================================================
 * ショートコード
 *   [m1616_table id="スラッグ または 投稿ID"]   ← 管理画面で作った価格テーブルを表示
 *   [iphone_price_table model="17" carriers="ahamo,楽天モバイル"]  ← 旧来の簡易表（後方互換）
 *   [iphone_price model="17" carrier="ahamo" capacity="256GB" type="monthly"]
 *   [iphone_price_updated_at]
 *
 * 管理画面：左サイドバー「iPhone価格」
 *   - 価格テーブル一覧 / 新規作成（プリセットごとに機種・キャリア・容量・強調を選ぶ）
 *   - 設定（JSON URL・キャッシュ・キャリアの表示名/色/アフィリエイトURL）
 *   - データ確認（JSONから検出した機種・キャリア・容量／最終更新／キャッシュ削除）
 * ============================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

define('M1616_VERSION', '2.4.0');
define('M1616_PLUGIN_FILE', __FILE__);
define('M1616_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('M1616_PLUGIN_URL', plugin_dir_url(__FILE__));

// Google Drive 公開JSONのダウンロードURL（設定画面で上書き可。定数があれば最優先）
if (!defined('M1616_JSON_URL_DEFAULT')) {
    define('M1616_JSON_URL_DEFAULT', 'https://drive.google.com/uc?export=download&id=1-TkGXCWmcE_mdiYF0AE-wvvqtW05QBLA');
}

// GitHub自動更新の参照先（ここを自分のGitHubに合わせる）
if (!defined('M1616_GH_USER')) {
    define('M1616_GH_USER', 'hiro-mtblue');          // GitHubユーザー名
}
if (!defined('M1616_GH_REPO')) {
    define('M1616_GH_REPO', 'm1616-iphone-price');  // リポジトリ名
}

require_once M1616_PLUGIN_DIR . 'includes/core.php';
require_once M1616_PLUGIN_DIR . 'includes/render.php';
require_once M1616_PLUGIN_DIR . 'includes/admin.php';
require_once M1616_PLUGIN_DIR . 'includes/block.php';
require_once M1616_PLUGIN_DIR . 'includes/updater.php';

// 管理画面で自動更新チェックを有効化（M1616_GH_USERが未設定なら何もしない）
if (is_admin() && M1616_GH_USER !== 'CHANGE_ME' && M1616_GH_USER !== '') {
    new M1616_GitHub_Updater(__FILE__, M1616_GH_USER, M1616_GH_REPO);
}

// カスタム投稿タイプ「価格テーブル」登録（フロントでもショートコード解決に必要なので常時）
function m1616_register_cpt() {
    register_post_type('m1616_table', [
        'labels' => [
            'name'          => '価格テーブル',
            'singular_name' => '価格テーブル',
            'add_new'       => '新規テーブル',
            'add_new_item'  => '価格テーブルを新規作成',
            'edit_item'     => '価格テーブルを編集',
            'new_item'      => '新規テーブル',
            'view_item'     => 'テーブルを表示',
            'search_items'  => 'テーブルを検索',
            'not_found'     => 'テーブルがありません',
            'menu_name'     => 'iPhone価格',
        ],
        'public'             => false,
        'show_ui'            => true,
        'show_in_menu'       => 'm1616_root',   // 独自トップメニュー配下に表示
        'show_in_rest'       => true,           // ブロックエディタ対応
        'supports'           => ['title'],
        'capability_type'    => 'post',
        'rewrite'            => false,
        'has_archive'        => false,
        'menu_position'      => 58,
    ]);
}
add_action('init', 'm1616_register_cpt');

// WP-Cron（毎日7:00 post_modified更新）
function m1616_schedule_cron() {
    if (!wp_next_scheduled('m1616_daily_modified_cron')) {
        $tz = wp_timezone();
        $t = new DateTime('tomorrow 07:00', $tz);
        wp_schedule_event($t->getTimestamp(), 'daily', 'm1616_daily_modified_cron');
    }
}
add_action('init', 'm1616_schedule_cron');

// 有効化：CPT登録＋cron＋キャリア初期データ投入
function m1616_activate() {
    m1616_register_cpt();
    m1616_schedule_cron();
    m1616_seed_default_carriers();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'm1616_activate');

// 無効化：cron解除
function m1616_deactivate() {
    $ts = wp_next_scheduled('m1616_daily_modified_cron');
    if ($ts) {
        wp_unschedule_event($ts, 'm1616_daily_modified_cron');
    }
    wp_clear_scheduled_hook('m1616_daily_modified_cron');
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'm1616_deactivate');
