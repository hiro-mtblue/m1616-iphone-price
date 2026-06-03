<?php
/**
 * 管理画面：メニュー / メタボックス / 設定 / データ確認
 */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * カラム表示順の正規キャリア順（レジストリ順 ＋ JSONにしか無いものを後ろに）
 */
function m1616_carrier_order() {
    $order = array_keys(m1616_get_carriers());
    $avail = m1616_available_options();
    foreach ($avail['carriers'] as $c) {
        if (!in_array($c, $order, true)) {
            $order[] = $c;
        }
    }
    return $order;
}

/* ============================================================
 * 左サイドバー メニュー
 * ============================================================ */

function m1616_admin_menu() {
    add_menu_page('iPhone価格', 'iPhone価格', 'manage_options', 'm1616_root', 'm1616_page_dashboard', 'dashicons-smartphone', 58);
    add_submenu_page('m1616_root', 'ダッシュボード', 'ダッシュボード', 'manage_options', 'm1616_root', 'm1616_page_dashboard');
    // CPT「価格テーブル」は show_in_menu=m1616_root で自動追加される
    add_submenu_page('m1616_root', '手動価格', '手動価格', 'manage_options', 'm1616_manual', 'm1616_page_manual');
    add_submenu_page('m1616_root', '設定', '設定', 'manage_options', 'm1616_settings', 'm1616_page_settings');
    add_submenu_page('m1616_root', 'データ確認', 'データ確認', 'manage_options', 'm1616_data', 'm1616_page_data');
}

/**
 * キャリア名→id用スラッグ（既知は固定。JSONのid命名と揃える）
 */
function m1616_carrier_slug($carrier) {
    $map = ['ahamo' => 'ahamo', '楽天モバイル' => 'rakuten', 'au' => 'au', 'ソフトバンク' => 'softbank', 'Apple' => 'apple', 'UQモバイル' => 'uq', 'Y!mobile' => 'ymobile'];
    if (isset($map[$carrier])) return $map[$carrier];
    $s = sanitize_title($carrier);
    return $s ?: 'carrier';
}
add_action('admin_menu', 'm1616_admin_menu');

/**
 * ダッシュボード：使い方＋テーブル一覧（ショートコード）
 */
function m1616_page_dashboard() {
    $tables = get_posts(['post_type' => 'm1616_table', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC']);
    ?>
    <div class="wrap">
        <h1>iPhone価格テーブル</h1>
        <p>記事に貼るショートコードは <code>[m1616_table id="スラッグ"]</code> です。下の一覧からコピーできます。</p>
        <p>
            <a href="<?php echo esc_url(admin_url('post-new.php?post_type=m1616_table')); ?>" class="button button-primary">＋ 新しい価格テーブルを作る</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=m1616_data')); ?>" class="button">データ確認</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=m1616_settings')); ?>" class="button">設定（キャリア・URL）</a>
        </p>
        <h2>作成済みテーブル</h2>
        <table class="widefat striped">
            <thead><tr><th>名前</th><th>ショートコード</th><th>編集</th></tr></thead>
            <tbody>
            <?php if (empty($tables)): ?>
                <tr><td colspan="3">まだテーブルがありません。「＋ 新しい価格テーブルを作る」から作成してください。</td></tr>
            <?php else: foreach ($tables as $t):
                $sc = '[m1616_table id="' . ($t->post_name ?: $t->ID) . '"]'; ?>
                <tr>
                    <td><?php echo esc_html($t->post_title ?: '(無題)'); ?></td>
                    <td><code class="m1616-sc"><?php echo esc_html($sc); ?></code>
                        <button type="button" class="button button-small m1616-copy" data-sc="<?php echo esc_attr($sc); ?>">コピー</button></td>
                    <td><a href="<?php echo esc_url(get_edit_post_link($t->ID)); ?>">編集</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <script>
    document.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('m1616-copy')) {
            navigator.clipboard.writeText(e.target.getAttribute('data-sc'));
            var o = e.target.textContent; e.target.textContent = 'コピー済';
            setTimeout(function(){ e.target.textContent = o; }, 1200);
        }
    });
    </script>
    <?php
}

/* ============================================================
 * メタボックス（CPT編集画面）
 * ============================================================ */

function m1616_add_metabox() {
    add_meta_box('m1616_table_settings', 'テーブルの内容', 'm1616_render_metabox', 'm1616_table', 'normal', 'high');
    add_meta_box('m1616_table_shortcode', 'ショートコード', 'm1616_render_shortcode_box', 'm1616_table', 'side', 'high');
}
add_action('add_meta_boxes', 'm1616_add_metabox');

function m1616_render_shortcode_box($post) {
    $sc = '[m1616_table id="' . ($post->post_name ?: $post->ID) . '"]';
    echo '<p>この記事に貼ると表示されます：</p>';
    echo '<input type="text" readonly value="' . esc_attr($sc) . '" style="width:100%;" onclick="this.select();">';
    echo '<p class="description">スラッグは右の「URLスラッグ」と連動します（未設定なら投稿ID）。</p>';
}

function m1616_render_metabox($post) {
    wp_nonce_field('m1616_save_meta', 'm1616_meta_nonce');
    $preset = m1616_get_preset($post->ID);
    $avail  = m1616_available_options();
    $order  = m1616_carrier_order();

    if (empty($avail['models']) && empty($avail['carriers'])) {
        echo '<div class="notice notice-warning inline"><p>JSONから価格データを取得できませんでした。「データ確認」ページとDriveの公開設定をご確認ください。下の選択肢は空の可能性があります。</p></div>';
    }
    ?>
    <style>
    .m1616-grid{display:flex;flex-wrap:wrap;gap:24px;}
    .m1616-grid fieldset{border:1px solid #ddd;padding:10px 14px;border-radius:6px;min-width:200px;}
    .m1616-grid legend{font-weight:bold;padding:0 6px;}
    .m1616-grid label{display:block;margin:4px 0;}
    </style>
    <div class="m1616-grid">
        <fieldset>
            <legend>機種</legend>
            <?php foreach ($avail['models'] as $slug => $label): ?>
                <label><input type="checkbox" name="m1616_models[]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $preset['models'], true)); ?>> <?php echo esc_html($label); ?></label>
            <?php endforeach; ?>
        </fieldset>

        <fieldset>
            <legend>キャリア（列）</legend>
            <?php foreach ($order as $c):
                $meta = m1616_get_carrier_meta($c); ?>
                <label><input type="checkbox" name="m1616_carriers[]" value="<?php echo esc_attr($c); ?>" <?php checked(in_array($c, $preset['carriers'], true)); ?>> <?php echo esc_html($meta['label']); ?></label>
            <?php endforeach; ?>
            <p class="description" style="margin-top:8px;">列の並び順は「設定」のキャリア登録順になります。</p>
        </fieldset>

        <fieldset>
            <legend>容量（行）</legend>
            <?php foreach ($avail['capacities'] as $cap): ?>
                <label><input type="checkbox" name="m1616_capacities[]" value="<?php echo esc_attr($cap); ?>" <?php checked(in_array($cap, $preset['capacities'], true)); ?>> <?php echo esc_html($cap); ?></label>
            <?php endforeach; ?>
        </fieldset>

        <fieldset>
            <legend>強調・オプション</legend>
            <label>推しキャリア（強調表示）：
                <select name="m1616_featured">
                    <option value="">なし</option>
                    <?php foreach ($order as $c):
                        $meta = m1616_get_carrier_meta($c); ?>
                        <option value="<?php echo esc_attr($c); ?>" <?php selected($preset['featured'], $c); ?>><?php echo esc_html($meta['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><input type="checkbox" name="m1616_cheapest" value="1" <?php checked($preset['cheapest']); ?>> 各容量の最安に「最安」バッジを付ける</label>
            <label><input type="checkbox" name="m1616_show_stock" value="1" <?php checked($preset['show_stock']); ?>> 在庫切れ・予約受付中を表示</label>
            <label><input type="checkbox" name="m1616_show_cta" value="1" <?php checked($preset['show_cta']); ?>> 表の下にCTAボタン（キャリア公式へ）を表示</label>
        </fieldset>
    </div>

    <h3 style="margin:18px 0 6px;">プレビュー（リアルタイム）</h3>
    <p class="description" style="margin-bottom:8px;">上のチェックを変えると下に即時反映されます。保存しなくても見た目を確認できます。</p>
    <div id="m1616-preview" style="border:1px solid #e2e2e2; border-radius:6px; padding:14px; background:#fff; min-height:60px;">
        <span style="color:#999;">読み込み中…</span>
    </div>

    <script>
    (function(){
        var box = document.getElementById('m1616_table_settings');
        var prev = document.getElementById('m1616-preview');
        if (!box || !prev) return;
        var nonce = '<?php echo esc_js(wp_create_nonce('m1616_preview')); ?>';
        var t = null;
        function collect(name){
            return Array.prototype.slice.call(box.querySelectorAll('input[name="'+name+'"]:checked')).map(function(el){return el.value;});
        }
        function render(){
            var data = new URLSearchParams();
            data.append('action','m1616_preview');
            data.append('nonce', nonce);
            collect('m1616_models[]').forEach(function(v){data.append('models[]', v);});
            collect('m1616_carriers[]').forEach(function(v){data.append('carriers[]', v);});
            collect('m1616_capacities[]').forEach(function(v){data.append('capacities[]', v);});
            var feat = box.querySelector('select[name="m1616_featured"]');
            data.append('featured', feat ? feat.value : '');
            data.append('cheapest', box.querySelector('input[name="m1616_cheapest"]').checked ? '1':'0');
            data.append('show_stock', box.querySelector('input[name="m1616_show_stock"]').checked ? '1':'0');
            data.append('show_cta', box.querySelector('input[name="m1616_show_cta"]').checked ? '1':'0');
            fetch(ajaxurl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:data.toString()})
                .then(function(r){return r.text();})
                .then(function(html){ prev.innerHTML = html || '<span style="color:#999;">表示する内容がありません。</span>'; })
                .catch(function(){ prev.innerHTML = '<span style="color:#c00;">プレビューの取得に失敗しました。</span>'; });
        }
        function schedule(){ clearTimeout(t); t = setTimeout(render, 300); }
        box.addEventListener('change', schedule);
        render();
    })();
    </script>
    <?php
}

/**
 * Ajax：編集画面のリアルタイムプレビュー
 */
function m1616_ajax_preview() {
    check_ajax_referer('m1616_preview', 'nonce');
    if (!current_user_can('edit_posts')) {
        wp_die('', '', 403);
    }
    $checked_carriers = isset($_POST['carriers']) ? array_map('sanitize_text_field', (array) $_POST['carriers']) : [];
    $ordered = [];
    foreach (m1616_carrier_order() as $c) {
        if (in_array($c, $checked_carriers, true)) $ordered[] = $c;
    }
    $preset = [
        'models'     => isset($_POST['models']) ? array_map('sanitize_text_field', (array) $_POST['models']) : [],
        'carriers'   => $ordered,
        'capacities' => isset($_POST['capacities']) ? array_map('sanitize_text_field', (array) $_POST['capacities']) : [],
        'featured'   => isset($_POST['featured']) ? sanitize_text_field($_POST['featured']) : '',
        'cheapest'   => isset($_POST['cheapest']) && $_POST['cheapest'] === '1',
        'show_stock' => isset($_POST['show_stock']) && $_POST['show_stock'] === '1',
        'show_cta'   => isset($_POST['show_cta']) && $_POST['show_cta'] === '1',
    ];
    echo m1616_render_tables($preset);
    wp_die();
}
add_action('wp_ajax_m1616_preview', 'm1616_ajax_preview');

function m1616_save_meta($post_id) {
    if (!isset($_POST['m1616_meta_nonce']) || !wp_verify_nonce($_POST['m1616_meta_nonce'], 'm1616_save_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (get_post_type($post_id) !== 'm1616_table') return;
    if (!current_user_can('edit_post', $post_id)) return;

    // 機種・容量：送信値をそのまま（サニタイズ）
    $models = isset($_POST['m1616_models']) ? array_map('sanitize_text_field', (array) $_POST['m1616_models']) : [];
    $caps   = isset($_POST['m1616_capacities']) ? array_map('sanitize_text_field', (array) $_POST['m1616_capacities']) : [];

    // キャリア：正規順（設定順）に並べ替えて保存
    $checked = isset($_POST['m1616_carriers']) ? array_map('sanitize_text_field', (array) $_POST['m1616_carriers']) : [];
    $ordered = [];
    foreach (m1616_carrier_order() as $c) {
        if (in_array($c, $checked, true)) $ordered[] = $c;
    }

    update_post_meta($post_id, '_m1616_models', $models);
    update_post_meta($post_id, '_m1616_carriers', $ordered);
    update_post_meta($post_id, '_m1616_capacities', $caps);
    update_post_meta($post_id, '_m1616_featured', isset($_POST['m1616_featured']) ? sanitize_text_field($_POST['m1616_featured']) : '');
    update_post_meta($post_id, '_m1616_cheapest', isset($_POST['m1616_cheapest']) ? '1' : '0');
    update_post_meta($post_id, '_m1616_show_stock', isset($_POST['m1616_show_stock']) ? '1' : '0');
    update_post_meta($post_id, '_m1616_show_cta', isset($_POST['m1616_show_cta']) ? '1' : '0');
}
add_action('save_post', 'm1616_save_meta');

/**
 * CPT一覧にショートコード列を追加
 */
function m1616_table_columns($cols) {
    $new = [];
    foreach ($cols as $k => $v) {
        $new[$k] = $v;
        if ($k === 'title') $new['m1616_sc'] = 'ショートコード';
    }
    return $new;
}
add_filter('manage_m1616_table_posts_columns', 'm1616_table_columns');

function m1616_table_column_content($col, $post_id) {
    if ($col === 'm1616_sc') {
        $post = get_post($post_id);
        $sc = '[m1616_table id="' . ($post->post_name ?: $post_id) . '"]';
        echo '<code>' . esc_html($sc) . '</code>';
    }
}
add_action('manage_m1616_table_posts_custom_column', 'm1616_table_column_content', 10, 2);

/* ============================================================
 * 設定ページ（JSON URL・キャッシュ・キャリアレジストリ）
 * ============================================================ */

function m1616_page_settings() {
    $carriers = m1616_get_carriers();
    $json_url = get_option('m1616_json_url', M1616_JSON_URL_DEFAULT);
    $saved = isset($_GET['m1616_saved']) ? sanitize_text_field($_GET['m1616_saved']) : '';
    // 表示用に空行を2つ足す
    $rows = $carriers;
    $rows['__new1'] = ['label' => '', 'color' => '#1967d2', 'affiliate_url' => ''];
    $rows['__new2'] = ['label' => '', 'color' => '#1967d2', 'affiliate_url' => ''];
    ?>
    <div class="wrap">
        <h1>iPhone価格 設定</h1>
        <?php if ($saved === '1'): ?><div class="notice notice-success is-dismissible"><p>保存しました。</p></div><?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="m1616_save_settings">
            <?php wp_nonce_field('m1616_settings', 'm1616_settings_nonce'); ?>

            <h2>データソース</h2>
            <table class="form-table">
                <tr>
                    <th><label for="m1616_json_url">JSON 公開URL</label></th>
                    <td><input type="url" id="m1616_json_url" name="m1616_json_url" value="<?php echo esc_attr($json_url); ?>" class="regular-text" style="width:520px;">
                        <p class="description">Google Driveの公開ダウンロードURL。<code>wp-config.php</code> で定数 <code>M1616_JSON_URL</code> を定義している場合はそちらが優先されます。</p></td>
                </tr>
            </table>

            <h2>キャリア登録（表示名・色・アフィリエイトURL）</h2>
            <p class="description">「キャリア名」はJSONの <code>carrier</code> と完全一致させてください（例：ahamo / 楽天モバイル / au）。アフィリエイトURLを入れると、そのキャリアのセルとCTAボタンのリンク先になります（空ならJSONの公式URL）。並び順がテーブルの列順になります。</p>
            <table class="widefat" style="max-width:1000px; margin-top:8px;">
                <thead><tr><th>キャリア名（JSONと一致）</th><th>表示名</th><th>色</th><th>アフィリエイトURL</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $key => $c):
                    $is_new = (strpos($key, '__new') === 0);
                    $keyval = $is_new ? '' : $key; ?>
                    <tr>
                        <td><input type="text" name="carrier_key[]" value="<?php echo esc_attr($keyval); ?>" placeholder="ahamo" style="width:100%;"></td>
                        <td><input type="text" name="carrier_label[]" value="<?php echo esc_attr($c['label']); ?>" style="width:100%;"></td>
                        <td><input type="text" name="carrier_color[]" value="<?php echo esc_attr($c['color']); ?>" class="m1616-color" style="width:100px;"></td>
                        <td><input type="url" name="carrier_aff[]" value="<?php echo esc_attr($c['affiliate_url']); ?>" placeholder="https://..." style="width:100%;"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">行を増やしたいときは、空行に入力して保存すると次回さらに空行が出ます。キャリア名を空にした行は削除されます。</p>

            <?php submit_button('保存'); ?>
        </form>
    </div>
    <?php
}

function m1616_handle_save_settings() {
    if (!current_user_can('manage_options') || !isset($_POST['m1616_settings_nonce']) || !wp_verify_nonce($_POST['m1616_settings_nonce'], 'm1616_settings')) {
        wp_die('権限がありません');
    }

    update_option('m1616_json_url', isset($_POST['m1616_json_url']) ? esc_url_raw($_POST['m1616_json_url']) : '');

    $keys   = isset($_POST['carrier_key']) ? (array) $_POST['carrier_key'] : [];
    $labels = isset($_POST['carrier_label']) ? (array) $_POST['carrier_label'] : [];
    $colors = isset($_POST['carrier_color']) ? (array) $_POST['carrier_color'] : [];
    $affs   = isset($_POST['carrier_aff']) ? (array) $_POST['carrier_aff'] : [];

    $reg = [];
    foreach ($keys as $i => $k) {
        $k = sanitize_text_field($k);
        if ($k === '') continue;

        $label = isset($labels[$i]) ? sanitize_text_field($labels[$i]) : '';
        if ($label === '') $label = $k;

        $color = isset($colors[$i]) ? sanitize_hex_color($colors[$i]) : '';
        if (!$color) $color = '#1967d2';

        $reg[$k] = [
            'label'         => $label,
            'color'         => $color,
            'affiliate_url' => isset($affs[$i]) ? esc_url_raw($affs[$i]) : '',
        ];
    }
    update_option('m1616_carriers', $reg, false);

    // キャッシュをクリアして次回最新を取得
    delete_transient('m1616_iphone_prices_v1');

    wp_safe_redirect(add_query_arg('m1616_saved', '1', admin_url('admin.php?page=m1616_settings')));
    exit;
}
add_action('admin_post_m1616_save_settings', 'm1616_handle_save_settings');

/* ============================================================
 * データ確認ページ
 * ============================================================ */

function m1616_page_data() {
    if (isset($_GET['m1616_refresh']) && check_admin_referer('m1616_refresh_data')) {
        delete_transient('m1616_iphone_prices_v1');
        m1616_get_iphone_prices(true);
        echo '<div class="notice notice-success is-dismissible"><p>キャッシュを削除して再取得しました。</p></div>';
    }
    $data  = m1616_get_iphone_prices();
    $avail = m1616_available_options();
    $reg_keys = array_keys(m1616_get_carriers());
    $refresh_url = wp_nonce_url(admin_url('admin.php?page=m1616_data&m1616_refresh=1'), 'm1616_refresh_data');
    ?>
    <div class="wrap">
        <h1>データ確認</h1>
        <p><a href="<?php echo esc_url($refresh_url); ?>" class="button button-primary">🔄 キャッシュ削除して再取得</a></p>

        <?php if (!$data || !isset($data['prices'])): ?>
            <div class="notice notice-error"><p>JSONを取得できませんでした。設定のJSON URLと、Drive側の公開設定（リンクを知っている全員＝閲覧者）を確認してください。</p></div>
        <?php else: ?>
            <table class="form-table">
                <tr><th>JSON URL</th><td><code><?php echo esc_html(m1616_json_url()); ?></code></td></tr>
                <tr><th>最終更新(last_updated_at)</th><td><?php echo esc_html($data['metadata']['last_updated_at'] ?? '(なし)'); ?></td></tr>
                <tr><th>データ件数</th><td><?php echo count($data['prices']); ?> 件</td></tr>
                <tr><th>検出した機種</th><td><?php echo esc_html(implode(' / ', array_values($avail['models']))); ?></td></tr>
                <tr><th>検出したキャリア</th><td><?php echo esc_html(implode(' / ', $avail['carriers'])); ?></td></tr>
                <tr><th>検出した容量</th><td><?php echo esc_html(implode(' / ', $avail['capacities'])); ?></td></tr>
            </table>

            <?php
            $missing = array_diff($avail['carriers'], $reg_keys);
            if (!empty($missing)): ?>
                <div class="notice notice-warning inline"><p>JSONにあるが「設定」未登録のキャリア：<strong><?php echo esc_html(implode(', ', $missing)); ?></strong>。設定ページで色・表示名・アフィリURLを登録すると、より見栄え良く表示できます。</p></div>
            <?php endif; ?>

            <h2>価格データ（プレビュー）</h2>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>キャリア</th><th>機種</th><th>容量</th><th>月額</th><th>実質</th><th>在庫</th></tr></thead>
                <tbody>
                <?php foreach ($data['prices'] as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r['id'] ?? ''); ?></td>
                        <td><?php echo esc_html($r['carrier'] ?? ''); ?></td>
                        <td><?php echo esc_html($r['model'] ?? ''); ?></td>
                        <td><?php echo esc_html($r['capacity'] ?? ''); ?></td>
                        <td><?php echo esc_html($r['monthly_payment_label'] ?? ''); ?></td>
                        <td><?php echo esc_html($r['total_paid_label'] ?? ''); ?></td>
                        <td><?php echo esc_html($r['stock_status'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/* ============================================================
 * 設定ページでカラーピッカーを有効化
 * ============================================================ */

function m1616_admin_assets($hook) {
    if (strpos($hook, 'm1616_settings') !== false) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script('wp-color-picker', 'jQuery(function($){$(".m1616-color").wpColorPicker();});');
    }
}
add_action('admin_enqueue_scripts', 'm1616_admin_assets');

/* ============================================================
 * 管理バー：手動再取得
 * ============================================================ */

function m1616_add_admin_bar_refresh($bar) {
    if (!current_user_can('manage_options')) return;
    $url = wp_nonce_url(admin_url('admin-post.php?action=m1616_refresh_prices'), 'm1616_refresh');
    $bar->add_node(['id' => 'm1616_refresh_prices', 'title' => '🔄 iPhone価格を再取得', 'href' => $url]);
}
add_action('admin_bar_menu', 'm1616_add_admin_bar_refresh', 100);

function m1616_handle_refresh_prices() {
    if (!current_user_can('manage_options') || !check_admin_referer('m1616_refresh')) {
        wp_die('権限がありません');
    }
    delete_transient('m1616_iphone_prices_v1');
    $data = m1616_get_iphone_prices(true);
    wp_safe_redirect(add_query_arg('m1616_refreshed', $data ? '1' : '0', wp_get_referer() ?: home_url()));
    exit;
}
add_action('admin_post_m1616_refresh_prices', 'm1616_handle_refresh_prices');

/* ============================================================
 * 手動価格ページ（自動取得できないキャリアの補完）
 * ============================================================ */

function m1616_page_manual() {
    $manual = m1616_get_manual_prices();
    $avail  = m1616_available_options();
    $order  = m1616_carrier_order();
    $saved  = isset($_GET['m1616_saved']) ? sanitize_text_field($_GET['m1616_saved']) : '';

    $stock_opts = ['在庫あり', '在庫切れ', '予約受付中'];

    // 表示行＝既存＋空行2
    $rows = $manual;
    $rows[] = []; $rows[] = [];
    ?>
    <div class="wrap">
        <h1>手動価格の入力</h1>
        <p>自動取得できないキャリア（au・SoftBank など）の価格を、ここで手入力できます。入力した行は自動JSONとマージされ、テーブルに表示されます（同じキャリア×機種×容量があれば手入力が優先）。</p>
        <?php if ($saved === '1'): ?><div class="notice notice-success is-dismissible"><p>保存しました。</p></div><?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="m1616_save_manual">
            <?php wp_nonce_field('m1616_manual', 'm1616_manual_nonce'); ?>

            <table class="widefat" style="margin-top:10px;">
                <thead><tr>
                    <th>キャリア</th><th>機種</th><th>容量</th>
                    <th>月額(数値)</th><th>月額表示</th>
                    <th>一括総額(数値)</th><th>一括総額表示</th>
                    <th>実質ラベル(任意)</th><th>在庫</th><th>リンクURL</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $rc = isset($r['carrier']) ? $r['carrier'] : '';
                    $rm = isset($r['model_slug']) ? $r['model_slug'] : '';
                    $rcap = isset($r['capacity']) ? $r['capacity'] : '';
                    $rmp = isset($r['monthly_payment']) ? $r['monthly_payment'] : '';
                    $rmpl = isset($r['monthly_payment_label']) ? $r['monthly_payment_label'] : '';
                    $rtp = isset($r['total_price']) ? $r['total_price'] : '';
                    $rtpl = isset($r['total_price_label']) ? $r['total_price_label'] : '';
                    $rpaid = isset($r['total_paid_label']) ? $r['total_paid_label'] : '';
                    $rstock = isset($r['stock_status']) ? $r['stock_status'] : '在庫あり';
                    $rurl = isset($r['source_url']) ? $r['source_url'] : '';
                    ?>
                    <tr>
                        <td>
                            <select name="mp_carrier[]">
                                <option value="">—</option>
                                <?php foreach ($order as $c): $meta = m1616_get_carrier_meta($c); ?>
                                    <option value="<?php echo esc_attr($c); ?>" <?php selected($rc, $c); ?>><?php echo esc_html($meta['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="mp_model[]">
                                <option value="">—</option>
                                <?php foreach ($avail['models'] as $slug => $label): ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($rm, $slug); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="mp_capacity[]">
                                <option value="">—</option>
                                <?php foreach ($avail['capacities'] as $cap): ?>
                                    <option value="<?php echo esc_attr($cap); ?>" <?php selected($rcap, $cap); ?>><?php echo esc_html($cap); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" name="mp_monthly[]" value="<?php echo esc_attr($rmp); ?>" style="width:90px;"></td>
                        <td><input type="text" name="mp_monthly_label[]" value="<?php echo esc_attr($rmpl); ?>" placeholder="2,278円/月" style="width:110px;"></td>
                        <td><input type="number" name="mp_total[]" value="<?php echo esc_attr($rtp); ?>" style="width:100px;"></td>
                        <td><input type="text" name="mp_total_label[]" value="<?php echo esc_attr($rtpl); ?>" placeholder="一括214,900円" style="width:120px;"></td>
                        <td><input type="text" name="mp_paid_label[]" value="<?php echo esc_attr($rpaid); ?>" placeholder="実質〜円" style="width:130px;"></td>
                        <td>
                            <select name="mp_stock[]">
                                <?php foreach ($stock_opts as $so): ?>
                                    <option value="<?php echo esc_attr($so); ?>" <?php selected($rstock, $so); ?>><?php echo esc_html($so); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="url" name="mp_url[]" value="<?php echo esc_attr($rurl); ?>" placeholder="https://..." style="width:160px;"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">キャリア・機種・容量がすべて選ばれた行だけ保存されます。行を削除したいときは、その行のキャリアを「—」に戻して保存してください。数値だけ入れて表示欄を空にすると、自動で「◯◯円/月」「一括◯◯円」に整形します。</p>
            <?php submit_button('保存'); ?>
        </form>
    </div>
    <?php
}

function m1616_handle_save_manual() {
    if (!current_user_can('manage_options') || !isset($_POST['m1616_manual_nonce']) || !wp_verify_nonce($_POST['m1616_manual_nonce'], 'm1616_manual')) {
        wp_die('権限がありません');
    }

    $carriers = isset($_POST['mp_carrier']) ? (array) $_POST['mp_carrier'] : [];
    $models   = isset($_POST['mp_model']) ? (array) $_POST['mp_model'] : [];
    $caps     = isset($_POST['mp_capacity']) ? (array) $_POST['mp_capacity'] : [];
    $months   = isset($_POST['mp_monthly']) ? (array) $_POST['mp_monthly'] : [];
    $month_l  = isset($_POST['mp_monthly_label']) ? (array) $_POST['mp_monthly_label'] : [];
    $totals   = isset($_POST['mp_total']) ? (array) $_POST['mp_total'] : [];
    $total_l  = isset($_POST['mp_total_label']) ? (array) $_POST['mp_total_label'] : [];
    $paid_l   = isset($_POST['mp_paid_label']) ? (array) $_POST['mp_paid_label'] : [];
    $stocks   = isset($_POST['mp_stock']) ? (array) $_POST['mp_stock'] : [];
    $urls     = isset($_POST['mp_url']) ? (array) $_POST['mp_url'] : [];

    $out = [];
    $models_label = m1616_available_options()['models'];
    foreach ($carriers as $i => $c) {
        $c   = sanitize_text_field($c);
        $m   = isset($models[$i]) ? sanitize_text_field($models[$i]) : '';
        $cap = isset($caps[$i]) ? sanitize_text_field($caps[$i]) : '';
        if ($c === '' || $m === '' || $cap === '') continue;

        $mp  = isset($months[$i]) && $months[$i] !== '' ? (int) $months[$i] : null;
        $mpl = isset($month_l[$i]) ? sanitize_text_field($month_l[$i]) : '';
        if ($mpl === '' && $mp !== null) $mpl = number_format($mp) . '円/月';

        $tp  = isset($totals[$i]) && $totals[$i] !== '' ? (int) $totals[$i] : null;
        $tpl = isset($total_l[$i]) ? sanitize_text_field($total_l[$i]) : '';
        if ($tpl === '' && $tp !== null) $tpl = '一括' . number_format($tp) . '円';

        $cap_slug = strtolower(str_replace(['GB', 'TB', ' '], ['gb', 'tb', ''], $cap));
        $id = m1616_carrier_slug($c) . '_' . $m . '_' . $cap_slug;

        $row = [
            'id'                    => $id,
            'carrier'               => $c,
            'model_slug'            => $m,
            'model'                 => isset($models_label[$m]) ? $models_label[$m] : $m,
            'capacity'              => $cap,
            'monthly_payment_label' => $mpl,
            'total_price_label'     => $tpl,
            'total_paid_label'      => isset($paid_l[$i]) ? sanitize_text_field($paid_l[$i]) : '',
            'stock_status'          => isset($stocks[$i]) ? sanitize_text_field($stocks[$i]) : '在庫あり',
            'source_url'            => isset($urls[$i]) ? esc_url_raw($urls[$i]) : '',
            'return_required'       => false,
        ];
        if ($mp !== null) $row['monthly_payment'] = $mp;
        if ($tp !== null) $row['total_price'] = $tp;

        $out[] = $row;
    }

    update_option('m1616_manual_prices', $out, false);
    wp_safe_redirect(add_query_arg('m1616_saved', '1', admin_url('admin.php?page=m1616_manual')));
    exit;
}
add_action('admin_post_m1616_save_manual', 'm1616_handle_save_manual');
