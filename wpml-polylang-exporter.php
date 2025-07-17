<?php
/**
 * Plugin Name: WPML & Polylang Exporter for MultilingualPress
 * Description: Export WPML, Polylang, or MultilingualPress content and site relations for migration.
 * Version: 1.1
 * Author: Femi
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('muplugins_loaded', function () {
    if (!class_exists('Inpsyde\\MultilingualPress\\Core\\ServiceProvider')) {
        error_log('MLP ServiceProvider not found');
        return;
    }

    // Add this line that's missing:  
    Language_Exporter::init(); 
   

    if (is_network_admin()) {
        add_filter(
            \Inpsyde\MultilingualPress\Core\ServiceProvider::ACTION_BUILD_TABS,
            ['Language_Exporter', 'add_mlp_settings_tabs']
        );
    }
});




class Language_Exporter {
    public static function init() {
        add_action('admin_init', [__CLASS__, 'handle_wpml_export']);
        add_action(
            \Inpsyde\MultilingualPress\Core\Admin\PluginSettingsUpdater::ACTION_UPDATE_PLUGIN_SETTINGS,
            [__CLASS__, 'handle_site_relations']
        );
            }

    public static function get_active_plugin() {
        if (function_exists('icl_get_languages')) return 'WPML';
        if (function_exists('pll_get_languages')) return 'Polylang';
        return 'None';
    }

    public static function render_content_export_tab() {
        $plugin = self::get_active_plugin();
        echo '<div class="wrap"><h2>Content Export</h2>';
        echo '<p><strong>Detected Plugin:</strong> ' . esc_html($plugin) . '</p>';

        if ($plugin === 'WPML') {
            self::render_wpml_form();
        } elseif ($plugin === 'Polylang') {
            self::render_polylang_form();
        } else {
            echo '<div class="notice notice-error"><p>No multilingual plugin detected.</p></div>';
        }
        echo '</div>';
    }

    public static function render_wpml_form() {
        $lang = apply_filters('wpml_current_language', NULL);
        $woo = class_exists('WooCommerce');
        echo '<form method="POST">';
        wp_nonce_field('wpml_export_nonce', 'wpml_export_nonce');
        echo '<input type="hidden" name="export_language" value="' . esc_attr($lang) . '">';
        echo '<label><strong>Post Types:</strong></label><br>';
        echo '<input type="checkbox" name="export_types[]" value="post" checked> Posts <br>';
        echo '<input type="checkbox" name="export_types[]" value="page" checked> Pages <br>';
        if ($woo) echo '<input type="checkbox" name="export_types[]" value="product"> Products <br>';
        echo '<br><input type="submit" name="wpml_export_submit" class="button button-primary" value="Download WPML Export">';
        echo '</form>';
    }

    public static function handle_wpml_export() {
        if (!isset($_POST['wpml_export_submit'])) return;
        if (!wp_verify_nonce($_POST['wpml_export_nonce'], 'wpml_export_nonce')) wp_die("Security check failed");

        $lang = apply_filters('wpml_current_language', NULL);
        $types = isset($_POST['export_types']) ? array_map('sanitize_text_field', $_POST['export_types']) : [];

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT element_id FROM {$wpdb->prefix}icl_translations WHERE language_code = %s", $lang));
        if (empty($ids)) wp_die("No posts found for language $lang.");

        $posts = get_posts([
            'post__in' => $ids,
            'post_type' => $types,
            'post_status' => 'publish',
            'numberposts' => -1
        ]);

        header('Content-Type: application/rss+xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wpml-export-' . $lang . '.wxr"');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel><wp:wxr_version>1.2</wp:wxr_version>';

        foreach ($posts as $post) {
            echo '<item>';
            echo '<title>' . esc_xml($post->post_title) . '</title>';
            echo '<wp:post_id>' . esc_xml($post->ID) . '</wp:post_id>';
            echo '<wp:post_date>' . esc_xml($post->post_date) . '</wp:post_date>';
            echo '<wp:post_type>' . esc_xml($post->post_type) . '</wp:post_type>';
            echo '<content:encoded><![CDATA[' . $post->post_content . ']]></content:encoded>';
            echo '</item>';
        }

        echo '</channel></rss>';
        exit;
    }

    public static function render_polylang_form() {
        $langs = pll_get_languages(['fields' => 'slug']);
        echo '<form method="GET">';
        echo '<input type="hidden" name="page" value="language-exporter">';
        echo '<select name="lang">';
        foreach ($langs as $slug) {
            echo '<option value="' . esc_attr($slug) . '">' . esc_html(strtoupper($slug)) . '</option>';
        }
        echo '</select>';
        echo '<input type="submit" name="export_polylang" value="Export Polylang CSV" class="button button-primary">';
        echo '</form>';

        if (isset($_GET['export_polylang']) && isset($_GET['lang'])) {
            self::handle_polylang_export($_GET['lang']);
        }
    }

    public static function handle_polylang_export($lang) {
        global $wpdb;
        $query = $wpdb->prepare("SELECT p.ID, p.post_title, p.post_content, p.post_type FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id WHERE tt.taxonomy = 'language' AND t.slug = %s", $lang);
        $posts = $wpdb->get_results($query);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="polylang-posts-' . sanitize_file_name($lang) . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'Title', 'Content', 'Type']);
        foreach ($posts as $post) {
            fputcsv($output, [$post->ID, sanitize_text_field($post->post_title), wp_strip_all_tags($post->post_content), sanitize_text_field($post->post_type)]);
        }
        fclose($output);
        exit;
    }

    public static function render_site_relations_tab() {
        echo '<div class="wrap"><h2>Site Relations Export / Import</h2>';
    
        // ✅ Display import results from transient
        if ($results = get_transient('mlp_import_results')) {
            delete_transient('mlp_import_results');
            foreach ($results['success'] as $msg) {
                echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
            }
            foreach ($results['errors'] as $err) {
                echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
            }
        }
    
        // ✅ This button submits through MLP's main form
        echo '<p><input type="submit" name="export_site_relations" class="button" value="Download Site Relations JSON"></p>';
    
        echo '<h3>Import Site Relations</h3>';
        echo '<p><label for="import_json">Paste JSON content:</label></p>';
        echo '<textarea name="import_json" rows="10" cols="80" placeholder="Paste your exported JSON here..."></textarea>';
        echo '<input type="hidden" name="import_site_relations_json" value="1">';
    
        echo '</div>';
    }
    

    public static function handle_site_relations() {
        if (!current_user_can('manage_options')) return;
    
        // ✅ No nonce needed — MLP already verifies the form
    
        // ✅ Handle Export
        if (isset($_POST['export_site_relations'])) {
            $data = self::export_site_relations();
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="site-relations-export.json"');
            echo json_encode($data, JSON_PRETTY_PRINT);
            exit;
        }
    
        // ✅ Handle Import
        if (isset($_POST['import_site_relations_json']) && !empty($_POST['import_json'])) {
            $json = stripslashes_deep($_POST['import_json']);
            $data = json_decode($json, true);
    
            if ($data === null) {
                set_transient('mlp_import_results', [
                    'success' => [],
                    'errors' => ['Invalid JSON format.']
                ], 30);
            } else {
                $results = self::import_site_relations($data);
                set_transient('mlp_import_results', $results, 30);
            }
        }
    }
    
    

    public static function export_site_relations() {
        $siteRelations = \Inpsyde\MultilingualPress\resolve(\Inpsyde\MultilingualPress\Framework\Api\SiteRelations::class);
        $allRelations = $siteRelations->allRelations();
        $export = [
            'version' => '1.0',
            'exported_at' => current_time('mysql'),
            'relations' => []
        ];
        foreach ($allRelations as $siteId => $related) {
            $site = get_site($siteId);
            if ($site) {
                $export['relations'][$siteId] = [
                    'site_info' => [
                        'url' => $site->siteurl,
                        'name' => $site->blogname
                    ],
                    'related_sites' => $related
                ];
            }
        }
        return $export;
    }

    public static function import_site_relations(array $data) {
        $siteRelations = \Inpsyde\MultilingualPress\resolve(\Inpsyde\MultilingualPress\Framework\Api\SiteRelations::class);
        $results = ['success' => [], 'errors' => []];
        if (!isset($data['relations'])) {
            $results['errors'][] = 'Invalid format: missing "relations" key';
            return $results;
        }
        foreach ($data['relations'] as $siteId => $rel) {
            $siteId = (int) $siteId;
            if (!get_site($siteId)) {
                $results['errors'][] = "Site {$siteId} does not exist";
                continue;
            }
            $valid = [];
            foreach ($rel['related_sites'] ?? [] as $relatedId) {
                if (get_site($relatedId)) {
                    $valid[] = $relatedId;
                } else {
                    $results['errors'][] = "Related site {$relatedId} does not exist";
                }
            }
            try {
                $inserted = $siteRelations->insertRelations($siteId, $valid);
                $results['success'][] = "Site {$siteId}: {$inserted} relations created";
            } catch (Exception $e) {
                $results['errors'][] = "Site {$siteId}: " . $e->getMessage();
            }
        }
        return $results;
    }

    public static function add_mlp_settings_tabs(array $tabs): array {
        $tabs['language-content-export'] = new \Inpsyde\MultilingualPress\Framework\Admin\SettingsPageTab(
            new \Inpsyde\MultilingualPress\Framework\Admin\SettingsPageTabData(
                'language-content-export',
                __('Language Content Export', 'language-exporter'),
                'language-content-export'
            ),
            new class implements \Inpsyde\MultilingualPress\Framework\Admin\SettingsPageView {
                public function render(): void {
                    Language_Exporter::render_content_export_tab();
                }
            }
        );

        $tabs['site-relations-export'] = new \Inpsyde\MultilingualPress\Framework\Admin\SettingsPageTab(
            new \Inpsyde\MultilingualPress\Framework\Admin\SettingsPageTabData(
                'site-relations-export',
                __('Site Relations', 'language-exporter'),
                'site-relations-export'
            ),
            new class implements \Inpsyde\MultilingualPress\Framework\Admin\SettingsPageView {
                public function render(): void {
                    Language_Exporter::render_site_relations_tab();
                }
            }
        );

        return $tabs;
    }
}
