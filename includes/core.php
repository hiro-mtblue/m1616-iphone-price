<?php
/**
 * コア：JSON取得、キャリアレジストリ、データ検出、Cron
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!defined('M1616_CACHE_SECONDS')) {
    define('M1616_CACHE_SECONDS', 6 * HOUR_IN_SECONDS);
}

/**
 * 実際に使うJSON URL
 * 優先順位：定数 M1616_JSON_URL（あれば）> 設定オプション > デフォルト
 */
function m1616_json_url() {
    if (defined('M1616_JSON_URL') && M1616_JSON_URL) {
        $url = M1616_JSON_URL;
    } else {
        $url = get_option('m1616_json_url', M1616_JSON_URL_DEFAULT);
        if (!$url) {
            $url = M1616_JSON_URL_DEFAULT;
        }
    }
    return apply_filters('m1616_json_url', $url);
}

/**
 * 価格データ取得（Transientキャッシュ＋失敗時フォールバック）
 */
function m1616_get_iphone_prices($force_refresh = false) {
    $cache_key = 'm1616_iphone_prices_v1';

    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
    }

    $response = wp_remote_get(m1616_json_url(), [
        'timeout'     => 15,
        'redirection' => 5,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $stale = get_option('m1616_iphone_prices_stale_backup');
        return $stale ?: false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!is_array($data) || !isset($data['prices'])) {
        $stale = get_option('m1616_iphone_prices_stale_backup');
        return $stale ?: false;
    }

    set_transient($cache_key, $data, M1616_CACHE_SECONDS);
    update_option('m1616_iphone_prices_stale_backup', $data, false);

    return $data;
}

/**
 * 手動価格（自動取得できないキャリア用）。option 'm1616_manual_prices' = [行,...]
 * 行のキーはJSONと同じ：id, carrier, model, model_slug, capacity,
 *   monthly_payment, monthly_payment_label, total_price, total_price_label,
 *   total_paid_label, stock_status, source_url
 */
function m1616_get_manual_prices() {
    $m = get_option('m1616_manual_prices', []);
    return is_array($m) ? $m : [];
}

/**
 * JSON価格＋手動価格をマージした全価格（同一idは手動が優先）
 */
function m1616_all_prices() {
    $data = m1616_get_iphone_prices();
    $rows = ($data && isset($data['prices']) && is_array($data['prices'])) ? $data['prices'] : [];

    $by_id = [];
    foreach ($rows as $r) {
        if (!empty($r['id'])) $by_id[$r['id']] = $r;
        else $by_id[] = $r;
    }
    foreach (m1616_get_manual_prices() as $r) {
        if (!empty($r['id'])) $by_id[$r['id']] = array_merge(isset($by_id[$r['id']]) ? $by_id[$r['id']] : [], $r);
        else $by_id[] = $r;
    }
    return array_values($by_id);
}

/**
 * 条件に一致する1件を返す（$model は model_slug もしくは "17"等の短縮）
 */
function m1616_find_price($model, $carrier, $capacity) {
    $model_slug = (strpos($model, 'iphone') === 0) ? $model : ('iphone' . strtolower($model));
    foreach (m1616_all_prices() as $row) {
        if (isset($row['model_slug'], $row['carrier'], $row['capacity'])
            && $row['model_slug'] === $model_slug
            && $row['carrier'] === $carrier
            && $row['capacity'] === $capacity) {
            return $row;
        }
    }
    return null;
}

/**
 * JSONから「選べる機種・キャリア・容量」を検出して返す
 * return: ['models'=>[slug=>label], 'carriers'=>[name,...], 'capacities'=>[cap,...]]
 */
function m1616_available_options() {
    $out = ['models' => [], 'carriers' => [], 'capacities' => []];
    $rows = m1616_all_prices();
    if (empty($rows)) return $out;

    $cap_order = ['256GB' => 1, '512GB' => 2, '1TB' => 3, '2TB' => 4];
    foreach ($rows as $row) {
        if (!empty($row['model_slug'])) {
            $out['models'][$row['model_slug']] = isset($row['model']) ? $row['model'] : $row['model_slug'];
        }
        if (!empty($row['carrier']) && !in_array($row['carrier'], $out['carriers'], true)) {
            $out['carriers'][] = $row['carrier'];
        }
        if (!empty($row['capacity']) && !in_array($row['capacity'], $out['capacities'], true)) {
            $out['capacities'][] = $row['capacity'];
        }
    }

    // 機種スラッグ順（iphone17, iphone17e ... ）を素直な昇順に
    ksort($out['models']);
    usort($out['capacities'], function($a, $b) use ($cap_order) {
        return ($cap_order[$a] ?? 99) - ($cap_order[$b] ?? 99);
    });

    return $out;
}

/* ============================================================
 * キャリアレジストリ（表示名・色・アフィリエイトURL）
 * option 'm1616_carriers' = [ carrier_name => ['label'=>, 'color'=>, 'affiliate_url'=>], ... ]
 * ============================================================ */

function m1616_get_carriers() {
    $reg = get_option('m1616_carriers', []);
    return is_array($reg) ? $reg : [];
}

function m1616_get_carrier_meta($carrier) {
    $reg = m1616_get_carriers();
    $def = ['label' => $carrier, 'color' => '#1967d2', 'affiliate_url' => ''];
    if (isset($reg[$carrier]) && is_array($reg[$carrier])) {
        return array_merge($def, $reg[$carrier]);
    }
    return $def;
}

/**
 * 背景色に対して読みやすい文字色（明るい背景=濃色 / 暗い背景=白）を返す
 */
function m1616_text_on($hex) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) return '#ffffff';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    // 相対輝度（簡易）。明るければ濃いグレー文字、暗ければ白文字。
    $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return ($lum > 0.7) ? '#333333' : '#ffffff';
}

/**
 * そのキャリアのリンク先：アフィリURLがあればそれ、無ければ行のsource_url
 */
function m1616_carrier_link($carrier, $row = null) {
    $meta = m1616_get_carrier_meta($carrier);
    if (!empty($meta['affiliate_url'])) {
        return $meta['affiliate_url'];
    }
    return ($row && !empty($row['source_url'])) ? $row['source_url'] : '';
}

/**
 * ブランドカラー定義（money1616 比較ページ準拠）。配列順＝デフォルト列順。
 */
function m1616_brand_carriers() {
    return [
        'Apple'      => ['label' => 'Apple',          'color' => '#f5f5f5', 'affiliate_url' => ''],
        'ahamo'      => ['label' => 'ahamo',          'color' => '#4d4d4d', 'affiliate_url' => ''],
        'au'         => ['label' => 'au',             'color' => '#e65100', 'affiliate_url' => ''],
        'ソフトバンク' => ['label' => 'SoftBank',       'color' => '#868686', 'affiliate_url' => ''],
        '楽天モバイル' => ['label' => 'Rakuten Mobile', 'color' => '#d63384', 'affiliate_url' => ''],
        'UQモバイル'   => ['label' => 'UQ mobile',      'color' => '#0068b7', 'affiliate_url' => ''],
        'Y!mobile'    => ['label' => 'Y!mobile',       'color' => '#e4007f', 'affiliate_url' => ''],
    ];
}

/**
 * 初期キャリアデータ投入（既存があれば触らない）
 */
function m1616_seed_default_carriers() {
    if (get_option('m1616_carriers', null) === null) {
        add_option('m1616_carriers', m1616_brand_carriers(), '', false);
    }
    if (get_option('m1616_json_url', null) === null) {
        add_option('m1616_json_url', M1616_JSON_URL_DEFAULT, '', false);
    }
}

/**
 * アップグレード：未登録のブランドキャリアを追加し、旧デフォルト色のみブランド色へ更新。
 * ユーザーが手動設定した色・URLは保持する。列順はブランド順に整える。
 */
function m1616_maybe_upgrade() {
    if (get_option('m1616_db_version') === M1616_VERSION) {
        return;
    }
    m1616_seed_default_carriers();

    $brand = m1616_brand_carriers();
    $reg   = m1616_get_carriers();
    // 自動上書きしてよい色（過去に自動設定した色。ユーザーが手で変えた色は触らない）
    $old_defaults = [
        'ahamo'      => ['#1967d2'],
        '楽天モバイル' => ['#bf0000'],   // v2.1で自動設定した赤 → ブランド色(ピンク)へ戻す
        'ソフトバンク' => ['#888888'],
        'au'         => ['#e65100'],
    ];

    foreach ($brand as $key => $val) {
        if (!isset($reg[$key])) {
            $reg[$key] = $val;            // 未登録キャリアを追加
        } elseif (isset($old_defaults[$key]) && in_array(strtolower($reg[$key]['color']), array_map('strtolower', $old_defaults[$key]), true)) {
            $reg[$key]['color'] = $val['color']; // 自動設定色のみブランド色へ
        }
    }

    // 列順をブランド順に整える（ブランド外のキャリアは後ろに維持）
    $ordered = [];
    foreach (array_keys($brand) as $k) {
        if (isset($reg[$k])) { $ordered[$k] = $reg[$k]; unset($reg[$k]); }
    }
    foreach ($reg as $k => $v) { $ordered[$k] = $v; }

    update_option('m1616_carriers', $ordered, false);
    update_option('m1616_db_version', M1616_VERSION, false);
}
add_action('plugins_loaded', 'm1616_maybe_upgrade');

/* ============================================================
 * Cron：post_modified自動更新
 * ============================================================ */

function m1616_update_modified_dates_cron() {
    delete_transient('m1616_iphone_prices_v1');
    $data = m1616_get_iphone_prices(true);

    if (!$data || !isset($data['metadata']['last_updated_at'])) {
        error_log('[m1616] post_modified更新スキップ: データ取得失敗');
        return;
    }

    $data_date = date('Y-m-d', strtotime($data['metadata']['last_updated_at']));
    $today = date('Y-m-d');
    if ($data_date !== $today) {
        error_log('[m1616] post_modified更新スキップ: データ日付が今日ではない (data=' . $data_date . ')');
        return;
    }

    global $wpdb;
    // 旧[iphone_price 系 と 新[m1616_table の両方を含む記事を対象に
    $post_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_status = 'publish'
         AND post_type IN ('post', 'page')
         AND (post_content LIKE %s OR post_content LIKE %s)",
        '%' . $wpdb->esc_like('[iphone_price') . '%',
        '%' . $wpdb->esc_like('[m1616_table') . '%'
    ));

    if (empty($post_ids)) {
        error_log('[m1616] post_modified更新対象なし');
        return;
    }

    $now = current_time('mysql');
    $now_gmt = current_time('mysql', 1);
    $count = 0;
    foreach ($post_ids as $pid) {
        $r = $wpdb->update(
            $wpdb->posts,
            ['post_modified' => $now, 'post_modified_gmt' => $now_gmt],
            ['ID' => $pid],
            ['%s', '%s'],
            ['%d']
        );
        if ($r !== false) {
            clean_post_cache($pid);
            $count++;
        }
    }
    error_log('[m1616] post_modified更新完了: ' . $count . '件');
}
add_action('m1616_daily_modified_cron', 'm1616_update_modified_dates_cron');
