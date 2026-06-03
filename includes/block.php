<?php
/**
 * Gutenbergブロック：価格テーブルをドロップダウンで選んで挿入（動的ブロック）
 */
if (!defined('ABSPATH')) {
    exit;
}

function m1616_register_block() {
    if (!function_exists('register_block_type')) {
        return; // クラシック環境では何もしない（ショートコードは使える）
    }

    wp_register_script(
        'm1616-block',
        M1616_PLUGIN_URL . 'assets/editor.js',
        ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render'],
        M1616_VERSION,
        true
    );

    // プリセット一覧をエディタへ渡す
    $tables = get_posts(['post_type' => 'm1616_table', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC']);
    $opts = [];
    foreach ($tables as $t) {
        $opts[] = [
            'label' => ($t->post_title ?: '(無題)'),
            'value' => ($t->post_name ?: (string) $t->ID),
        ];
    }
    wp_localize_script('m1616-block', 'm1616BlockData', ['tables' => $opts]);

    register_block_type('m1616/price-table', [
        'editor_script'   => 'm1616-block',
        'render_callback' => 'm1616_block_render',
        'attributes'      => [
            'tableId' => ['type' => 'string', 'default' => ''],
        ],
    ]);
}
add_action('init', 'm1616_register_block');

function m1616_block_render($attrs) {
    $id = isset($attrs['tableId']) ? sanitize_text_field($attrs['tableId']) : '';
    if ($id === '') {
        return '<p style="color:#999;">価格テーブルが選択されていません。</p>';
    }
    return do_shortcode('[m1616_table id="' . esc_attr($id) . '"]');
}
