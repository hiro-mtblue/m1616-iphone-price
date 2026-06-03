<?php
/**
 * 描画＆ショートコード
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * プリセット（CPT）の設定をデフォルト込みで取得
 */
function m1616_get_preset($post_id) {
    $models     = get_post_meta($post_id, '_m1616_models', true);
    $carriers   = get_post_meta($post_id, '_m1616_carriers', true);
    $capacities = get_post_meta($post_id, '_m1616_capacities', true);

    return [
        'models'      => is_array($models) ? $models : [],
        'carriers'    => is_array($carriers) ? $carriers : [],
        'capacities'  => is_array($capacities) ? $capacities : [],
        'featured'    => (string) get_post_meta($post_id, '_m1616_featured', true),
        'show_cta'    => get_post_meta($post_id, '_m1616_show_cta', true) === '1',
        'show_stock'  => get_post_meta($post_id, '_m1616_show_stock', true) === '1',
        'cheapest'    => get_post_meta($post_id, '_m1616_cheapest', true) === '1',
    ];
}

/**
 * id（投稿IDまたはスラッグ）からプリセットの投稿IDを解決
 */
function m1616_resolve_table_id($id) {
    if (is_numeric($id)) {
        $p = get_post((int) $id);
        return ($p && $p->post_type === 'm1616_table') ? (int) $id : 0;
    }
    $page = get_page_by_path($id, OBJECT, 'm1616_table');
    return $page ? (int) $page->ID : 0;
}

/**
 * 在庫バッジHTML
 */
function m1616_stock_badge($status) {
    $status = (string) $status;
    if ($status === '' || $status === '在庫あり') return '';
    $color = ($status === '予約受付中') ? '#e65100' : '#888';
    return '<div style="margin-top:4px;"><span style="display:inline-block; font-size:11px; line-height:1; padding:3px 6px; border-radius:3px; background:' . esc_attr($color) . '; color:#fff;">' . esc_html($status) . '</span></div>';
}

/**
 * 最安バッジHTML
 */
function m1616_cheapest_badge() {
    return ' <span style="display:inline-block; font-size:11px; line-height:1; padding:3px 6px; border-radius:3px; background:#e53935; color:#fff; vertical-align:middle;">最安</span>';
}

/**
 * [m1616_table id="..."]
 */
function m1616_sc_table($atts) {
    $a = shortcode_atts(['id' => ''], $atts);
    $pid = m1616_resolve_table_id($a['id']);
    if (!$pid) {
        return '<p style="color:#999;">価格テーブルが見つかりません（id=' . esc_html($a['id']) . '）。</p>';
    }
    return m1616_render_tables(m1616_get_preset($pid));
}
add_shortcode('m1616_table', 'm1616_sc_table');

/**
 * プリセット配列から全機種ぶんのテーブルを描画（ショートコード・ブロック・プレビュー共用）
 */
function m1616_render_tables($preset) {
    if (empty($preset['models']) || empty($preset['carriers']) || empty($preset['capacities'])) {
        return '<p style="color:#999;">機種・キャリア・容量が未設定です（編集画面で選択してください）。</p>';
    }
    if (empty(m1616_all_prices())) {
        return '<p class="m1616-price-na" style="color:#999;">価格データを取得中です。少し時間をおいてから再度ご確認ください。</p>';
    }

    // 機種の表示順は available の順に合わせる
    $model_labels = m1616_available_options()['models'];
    $ordered_models = array_values(array_filter(array_keys($model_labels), function($slug) use ($preset) {
        return in_array($slug, $preset['models'], true);
    }));
    if (empty($ordered_models)) {
        $ordered_models = $preset['models'];
    }

    $html = '<div class="m1616-tables" style="margin:24px 0;">';
    foreach ($ordered_models as $slug) {
        $label = isset($model_labels[$slug]) ? $model_labels[$slug] : $slug;
        $html .= m1616_render_model_table($slug, $label, $preset);
    }

    $data = m1616_get_iphone_prices();
    if ($data && isset($data['metadata']['last_updated_at'])) {
        $updated = date_i18n('Y年n月j日', strtotime($data['metadata']['last_updated_at']));
        $html .= '<p style="font-size:12px; color:#666; margin:8px 0 0; text-align:right;">最終更新：' . esc_html($updated) . '時点・各キャリア公式サイトより自動取得</p>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * 1機種ぶんのテーブル（行＝容量／列＝キャリア）
 */
function m1616_render_model_table($model_slug, $model_label, $preset) {
    $carriers   = $preset['carriers'];
    $capacities = $preset['capacities'];
    $featured   = $preset['featured'];

    $ncar = max(1, count($carriers));
    $car_w = round(86 / $ncar, 3); // 容量列14% + キャリア列を均等配分

    $h  = '<div style="overflow-x:auto; margin:0 0 20px;">';
    $h .= '<div style="font-weight:bold; font-size:17px; margin:0 0 8px;">' . esc_html($model_label) . '</div>';
    $h .= '<table style="border-collapse:collapse; table-layout:fixed; width:100%; min-width:' . (90 + $ncar * 120) . 'px; border-top:2px solid #333; border-bottom:2px solid #333;">';

    // 列幅を固定（容量14%＋各キャリア均等）
    $h .= '<colgroup><col style="width:14%;">';
    foreach ($carriers as $c) {
        $h .= '<col style="width:' . $car_w . '%;">';
    }
    $h .= '</colgroup>';

    // ヘッダ（各キャリアをブランドカラーで色分け。テーマCSSに勝つため!important）
    $h .= '<thead><tr>';
    $h .= '<th style="padding:10px 8px; text-align:center; font-weight:bold; background:#f5f5f5 !important; color:#666 !important; border:1px solid #eee; word-break:break-word;">容量</th>';
    foreach ($carriers as $c) {
        $meta = m1616_get_carrier_meta($c);
        $txt  = m1616_text_on($meta['color']);
        $h .= '<th style="padding:10px 8px; text-align:center; font-weight:bold; background:' . esc_attr($meta['color']) . ' !important; color:' . $txt . ' !important; border:1px solid rgba(0,0,0,0.08); word-break:break-word; white-space:normal;">' . esc_html($meta['label']) . '</th>';
    }
    $h .= '</tr></thead><tbody>';

    // ボディ
    foreach ($capacities as $cap) {
        // この容量行の最安monthlyを求める
        $min_monthly = null;
        $cells = [];
        foreach ($carriers as $c) {
            $row = m1616_find_price($model_slug, $c, $cap);
            $cells[$c] = $row;
            if ($row && isset($row['monthly_payment']) && is_numeric($row['monthly_payment'])) {
                $mp = (int) $row['monthly_payment'];
                if ($min_monthly === null || $mp < $min_monthly) {
                    $min_monthly = $mp;
                }
            }
        }

        $h .= '<tr style="border-bottom:1px solid #eee;">';
        $h .= '<td style="padding:10px 8px; text-align:center; vertical-align:middle; word-break:break-word;"><strong>' . esc_html($cap) . '</strong></td>';
        foreach ($carriers as $c) {
            $row = $cells[$c];
            $is_featured = ($c === $featured && $featured !== '');
            $cell_style = 'padding:10px 8px; text-align:center; vertical-align:top; font-size:13px; line-height:1.5; word-break:break-word;';
            if ($is_featured) {
                $cell_style .= ' background:#f4f8ff !important; font-weight:bold;';
            }

            if (!$row) {
                $h .= '<td style="' . $cell_style . ' color:#999;">－</td>';
                continue;
            }

            $link = m1616_carrier_link($c, $row);
            $monthly = isset($row['monthly_payment_label']) ? $row['monthly_payment_label'] : '';
            $is_cheapest = $preset['cheapest'] && $min_monthly !== null
                && isset($row['monthly_payment']) && (int) $row['monthly_payment'] === $min_monthly;

            $inner = '';
            if ($link) {
                $inner .= '<a href="' . esc_url($link) . '" target="_blank" rel="noopener nofollow sponsored" style="color:#1967d2; text-decoration:none; font-weight:bold;">' . esc_html($monthly) . '</a>';
            } else {
                $inner .= '<span style="font-weight:bold;">' . esc_html($monthly) . '</span>';
            }
            if ($is_cheapest) {
                $inner .= m1616_cheapest_badge();
            }
            // 一括総額（あれば）
            $total_label = '';
            if (!empty($row['total_price_label'])) {
                $total_label = $row['total_price_label'];
            } elseif (!empty($row['total_price']) && is_numeric($row['total_price'])) {
                $total_label = '一括' . number_format((int) $row['total_price']) . '円';
            }
            if ($total_label !== '') {
                $inner .= '<div style="font-size:12px; color:#444; font-weight:normal; margin-top:4px;">' . esc_html($total_label) . '</div>';
            }
            // 実質（返却前提）
            if (!empty($row['total_paid_label'])) {
                $inner .= '<div style="font-size:12px; color:#666; font-weight:normal; margin-top:2px;">' . esc_html($row['total_paid_label']) . '</div>';
            }
            if ($preset['show_stock']) {
                $inner .= m1616_stock_badge(isset($row['stock_status']) ? $row['stock_status'] : '');
            }

            $h .= '<td style="' . $cell_style . '">' . $inner . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table>';

    // CTAボタン行（キャリアごと）
    if ($preset['show_cta']) {
        $h .= '<div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:12px;">';
        foreach ($carriers as $c) {
            $meta = m1616_get_carrier_meta($c);
            $link = !empty($meta['affiliate_url']) ? $meta['affiliate_url'] : '';
            if (!$link) {
                // アフィリ未設定ならこの機種のいずれかのsource_urlを使う
                foreach ($capacities as $cap) {
                    $row = m1616_find_price($model_slug, $c, $cap);
                    if ($row && !empty($row['source_url'])) { $link = $row['source_url']; break; }
                }
            }
            if (!$link) continue;
            $btxt = m1616_text_on($meta['color']);
            $h .= '<a href="' . esc_url($link) . '" target="_blank" rel="noopener nofollow sponsored" style="flex:1; min-width:160px; text-align:center; padding:12px 14px; background:' . esc_attr($meta['color']) . '; color:' . $btxt . '; text-decoration:none; font-weight:bold; border-radius:8px;">' . esc_html($meta['label']) . 'の最新を見る ＞</a>';
        }
        $h .= '</div>';
    }

    $h .= '</div>';
    return $h;
}

/* ============================================================
 * 後方互換：旧ショートコード
 * ============================================================ */

function m1616_sc_iphone_price($atts) {
    $a = shortcode_atts([
        'model' => '17', 'carrier' => 'ahamo', 'capacity' => '256GB', 'type' => 'monthly',
    ], $atts);
    $row = m1616_find_price($a['model'], $a['carrier'], $a['capacity']);
    if (!$row) return '<span class="m1616-price-na" style="color:#999;">価格取得中</span>';

    switch ($a['type']) {
        case 'total':    $out = $row['total_paid_label']; break;
        case 'residual': $out = $row['residual_label']; break;
        case 'url':      $out = $row['source_url']; break;
        case 'monthly':
        default:         $out = $row['monthly_payment_label'];
    }
    return '<span class="m1616-price">' . esc_html($out) . '</span>';
}
add_shortcode('iphone_price', 'm1616_sc_iphone_price');

function m1616_sc_iphone_price_table($atts) {
    $a = shortcode_atts(['model' => '17', 'carriers' => 'ahamo,楽天モバイル'], $atts);
    $data = m1616_get_iphone_prices();
    if (!$data || !isset($data['prices'])) {
        return '<p class="m1616-price-na" style="color:#999;">価格データを取得中です。</p>';
    }
    $preset = [
        'models'     => [(strpos($a['model'], 'iphone') === 0) ? $a['model'] : ('iphone' . strtolower($a['model']))],
        'carriers'   => array_map('trim', explode(',', $a['carriers'])),
        'capacities' => m1616_available_options()['capacities'],
        'featured'   => '',
        'show_cta'   => false,
        'show_stock' => false,
        'cheapest'   => false,
    ];
    $slug = $preset['models'][0];
    $labels = m1616_available_options()['models'];
    $label = isset($labels[$slug]) ? $labels[$slug] : $slug;
    $h = '<div class="m1616-tables" style="margin:24px 0;">' . m1616_render_model_table($slug, $label, $preset);
    if (isset($data['metadata']['last_updated_at'])) {
        $updated = date_i18n('Y年n月j日', strtotime($data['metadata']['last_updated_at']));
        $h .= '<p style="font-size:12px; color:#666; margin:8px 0 0; text-align:right;">最終更新：' . esc_html($updated) . '時点・各キャリア公式サイトより自動取得</p>';
    }
    $h .= '</div>';
    return $h;
}
add_shortcode('iphone_price_table', 'm1616_sc_iphone_price_table');

function m1616_sc_iphone_price_updated_at($atts) {
    $a = shortcode_atts(['format' => 'Y年n月j日'], $atts);
    $data = m1616_get_iphone_prices();
    if (!$data || !isset($data['metadata']['last_updated_at'])) {
        return '<span class="m1616-updated">本日</span>';
    }
    return '<span class="m1616-updated">' . esc_html(date_i18n($a['format'], strtotime($data['metadata']['last_updated_at']))) . '</span>';
}
add_shortcode('iphone_price_updated_at', 'm1616_sc_iphone_price_updated_at');
