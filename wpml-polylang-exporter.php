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

// Load main class
require_once plugin_dir_path(__FILE__) . 'includes/class-language-exporter.php';

add_action('plugins_loaded', ['Language_Exporter', 'init']);

class Language_Exporter {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_page']);
        add_action('admin_init', [__CLASS__, 'handle_wpml_export']);
        add_action('admin_init', [__CLASS__, 'handle_site_relations']);
    }

    public static function add_admin_page() {
        add_management_page(
            'Language Exporter', 'Language Exporter', 'manage_options', 'language-exporter', [__CLASS__, 'render_page']
        );
    }

    public static function render_page() {
        $tab = $_GET['tab'] ?? 'content';
        echo '<div class="wrap"><h1>Language Exporter</h1>';
        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="?page=language-exporter&tab=content" class="nav-tab ' . ($tab === 'content' ? 'nav-tab-active' : '') . '">Content Export</a>';
        echo '<a href="?page=language-exporter&tab=relations" class="nav-tab ' . ($tab === 'relations' ? 'nav-tab-active' : '') . '">Site Relations</a>';
        echo '</nav>';

        if ($tab === 'content') {
            self::render_content_export_tab();
        } else {
            self::render_site_relations_tab();
        }

        echo '</div>';
    }

    public static function render_content_export_tab() {
        $plugin = self::get_active_plugin();
        echo '<p><strong>Detected Plugin:</strong> ' . esc_html($plugin) . '</p>';

        if ($plugin === 'WPML') {
            self::render_wpml_form();
        } elseif ($plugin === 'Polylang') {
            self::render_polylang_form();
        } else {
            echo '<div class="notice notice-error"><p>No multilingual plugin detected.</p></div>';
        }
    }

    public static function get_active_plugin() {
        if (function_exists('icl_get_languages')) return 'WPML';
        if (function_exists('pll_get_languages')) return 'Polylang';
        return 'None';
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
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('site_relations_action', 'site_relations_nonce');
        echo '<p><input type="submit" name="export_site_relations" class="button" value="Download Site Relations JSON"></p>';
        echo '<p><label for="relations_json">Import Site Relations JSON:</label> <input type="file" name="relations_json" accept=".json"> <input type="submit" name="import_site_relations" class="button button-primary" value="Import Relations"></p>';
        echo '</form>';
    }

    public static function handle_site_relations() {
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['export_site_relations']) && check_admin_referer('site_relations_action', 'site_relations_nonce')) {
            $data = self::export_site_relations();
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="site-relations-export.json"');
            echo json_encode($data, JSON_PRETTY_PRINT);
            exit;
        }

        if (isset($_POST['import_site_relations']) && check_admin_referer('site_relations_action', 'site_relations_nonce')) {
            if (!empty($_FILES['relations_json']['tmp_name'])) {
                $json = file_get_contents($_FILES['relations_json']['tmp_name']);
                $data = json_decode($json, true);
                $results = self::import_site_relations($data);
                add_action('admin_notices', function () use ($results) {
                    foreach ($results['success'] as $msg) {
                        echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
                    }
                    foreach ($results['errors'] as $err) {
                        echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
                    }
                });
            }
        }
    }

    public static function export_site_relations() {
        $siteRelations = resolve(Inpsyde\MultilingualPress\API\SiteRelations::class);
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
        $siteRelations = resolve(Inpsyde\MultilingualPress\API\SiteRelations::class);
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
}
