<?php
/**
 * GitHub リリースからプラグインを自動更新する軽量アップデーター（外部ライブラリ不使用）
 * GitHubの「最新リリース(tag)」のバージョンが現在より新しければ、WP管理画面に更新通知を出し、
 * 「更新」ボタンでGitHubのzipから1クリック更新できる。
 */
if (!defined('ABSPATH')) {
    exit;
}

class M1616_GitHub_Updater {
    private $plugin_file; // 絶対パス
    private $basename;    // 例: m1616-iphone-price/m1616-iphone-price.php
    private $slug;        // 例: m1616-iphone-price
    private $user;
    private $repo;
    private $data;
    private $cache_key = 'm1616_gh_release';

    public function __construct($plugin_file, $user, $repo) {
        $this->plugin_file = $plugin_file;
        $this->basename    = plugin_basename($plugin_file);
        $this->slug        = dirname($this->basename);
        $this->user        = $user;
        $this->repo        = $repo;

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $this->data = get_plugin_data($plugin_file, false, false);

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_dir'], 10, 4);
        // 更新後はリリースキャッシュを消す
        add_action('upgrader_process_complete', [$this, 'clear_cache'], 10, 0);
    }

    public function clear_cache() {
        delete_transient($this->cache_key);
    }

    private function get_release() {
        if (empty($this->user) || empty($this->repo)) {
            return false;
        }
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }
        $url = 'https://api.github.com/repos/' . rawurlencode($this->user) . '/' . rawurlencode($this->repo) . '/releases/latest';
        $res = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress-' . $this->slug,
            ],
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            return false;
        }
        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            return false;
        }
        set_transient($this->cache_key, $body, 6 * HOUR_IN_SECONDS);
        return $body;
    }

    private function ver($rel) {
        return ltrim((string) $rel['tag_name'], 'vV');
    }

    private function zip_url($rel) {
        // アップロード済みの .zip アセットがあれば優先、無ければGitHub自動生成のソースzip
        if (!empty($rel['assets']) && is_array($rel['assets'])) {
            foreach ($rel['assets'] as $a) {
                if (!empty($a['browser_download_url']) && substr($a['name'], -4) === '.zip') {
                    return $a['browser_download_url'];
                }
            }
        }
        return !empty($rel['zipball_url']) ? $rel['zipball_url'] : '';
    }

    public function check_update($transient) {
        if (empty($transient) || empty($transient->checked)) {
            return $transient;
        }
        $rel = $this->get_release();
        if (!$rel) {
            return $transient;
        }
        $new = $this->ver($rel);
        $cur = isset($this->data['Version']) ? $this->data['Version'] : '0';
        if (version_compare($new, $cur, '>')) {
            $transient->response[$this->basename] = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->basename,
                'new_version' => $new,
                'url'         => 'https://github.com/' . $this->user . '/' . $this->repo,
                'package'     => $this->zip_url($rel),
            ];
        } else {
            // 最新の場合は no_update に入れておく（WPの整合性のため）
            $transient->no_update[$this->basename] = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->basename,
                'new_version' => $cur,
                'url'         => 'https://github.com/' . $this->user . '/' . $this->repo,
                'package'     => '',
            ];
        }
        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }
        $rel = $this->get_release();
        if (!$rel) {
            return $result;
        }
        return (object) [
            'name'          => isset($this->data['Name']) ? $this->data['Name'] : $this->slug,
            'slug'          => $this->slug,
            'version'       => $this->ver($rel),
            'author'        => isset($this->data['Author']) ? $this->data['Author'] : '',
            'homepage'      => 'https://github.com/' . $this->user . '/' . $this->repo,
            'download_link' => $this->zip_url($rel),
            'sections'      => [
                'changelog' => nl2br(esc_html(isset($rel['body']) ? $rel['body'] : '')),
            ],
        ];
    }

    /**
     * GitHubのzipは「user-repo-hash/」に展開されるため、プラグインのスラッグ名に改名する
     */
    public function fix_dir($source, $remote_source, $upgrader, $hook_extra = null) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }
        global $wp_filesystem;
        if (!$wp_filesystem) {
            return $source;
        }
        $desired = trailingslashit($remote_source) . $this->slug;
        if (untrailingslashit($source) === untrailingslashit($desired)) {
            return $source;
        }
        if ($wp_filesystem->move(untrailingslashit($source), untrailingslashit($desired), true)) {
            return trailingslashit($desired);
        }
        return $source;
    }
}
