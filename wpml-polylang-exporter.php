<?php
/**
 * Plugin Name: WPML & Polylang WXR Exporter
 * Description: Exports WPML and Polylang posts per language for MultilingualPress migration.
 * Version: 1.0
 * Author: Femi
 * License: GPL2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// 1️⃣ Create Export Page in Admin
function language_export_add_page() {
    add_management_page(
        'Language Export',
        'Language Export',
        'manage_options',
        'language-export',
        'language_export_render_page'
    );
}
add_action('admin_menu', 'language_export_add_page');

// 2️⃣ Detect Active Plugin (WPML or Polylang)
function language_export_get_active_plugin() {
    if (function_exists('icl_get_languages')) {
        return 'WPML';
    } elseif (function_exists('pll_get_languages')) {
        return 'Polylang';
    }
    return 'None';
}

// 3️⃣ Render Export Page
function language_export_render_page() {
    $active_plugin = language_export_get_active_plugin();

    echo '<div class="wrap">';
    echo '<h1>Language Export</h1>';
    echo '<p><strong>Detected Multilingual Plugin:</strong> ' . esc_html($active_plugin) . '</p>';

    if ($active_plugin === 'WPML') {
        language_export_wpml_page();
    } elseif ($active_plugin === 'Polylang') {
        language_export_polylang_page();
    } else {
        echo '<div class="notice notice-error"><p>No supported multilingual plugin found.</p></div>';
    }

    echo '</div>';
}

// 4️⃣ WPML Export Page
function language_export_wpml_page() {
    $current_language = apply_filters('wpml_current_language', NULL);
    $woocommerce_active = class_exists('WooCommerce');

    ?>
    <form method="POST">
        <?php wp_nonce_field('wpml_export_nonce', 'wpml_export_nonce'); ?>
        <input type="hidden" name="export_language" value="<?php echo esc_attr($current_language); ?>">
        
        <label><strong>Select Post Types:</strong></label><br>
        <input type="checkbox" name="export_types[]" value="post" checked> Posts <br>
        <input type="checkbox" name="export_types[]" value="page" checked> Pages <br>
        <?php if ($woocommerce_active): ?>
            <input type="checkbox" name="export_types[]" value="product"> Products <br>
        <?php endif; ?>

        <br>
        <input type="submit" name="wpml_export_submit" class="button button-primary" value="Download WPML Export">
    </form>
    <?php
}

// 5️⃣ Handle WPML Export
function language_export_wpml_handle_export() {
    if (isset($_POST['wpml_export_submit'])) {
        if (!isset($_POST['wpml_export_nonce']) || !wp_verify_nonce($_POST['wpml_export_nonce'], 'wpml_export_nonce')) {
            die("Security check failed");
        }

        $selected_language = apply_filters('wpml_current_language', NULL);
        $selected_types = isset($_POST['export_types']) ? array_map('sanitize_text_field', $_POST['export_types']) : [];

        if (empty($selected_language) || empty($selected_types)) {
            wp_die("Error: No language or post types selected.");
        }

        global $wpdb;

        // Get only the posts in the correct WPML language
        $post_ids = $wpdb->get_col($wpdb->prepare("
            SELECT element_id FROM {$wpdb->prefix}icl_translations
            WHERE language_code = %s
        ", $selected_language));

        if (empty($post_ids)) {
            wp_die("No posts found for the selected language ($selected_language).");
        }

        $posts = get_posts([
            'post__in'   => $post_ids,
            'post_type'  => $selected_types,
            'post_status'=> 'publish',
            'numberposts'=> -1
        ]);

        if (empty($posts)) {
            wp_die("No posts found for the selected language ($selected_language) and post types.");
        }

        // Generate WXR Output
        header('Content-Type: application/rss+xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wpml-export-' . $selected_language . '.wxr"');

        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">';
        echo '<channel>';
        echo '<wp:wxr_version>1.2</wp:wxr_version>';

        foreach ($posts as $post) {
            echo '<item>';
            echo '<title>' . esc_xml($post->post_title) . '</title>';
            echo '<wp:post_id>' . esc_xml($post->ID) . '</wp:post_id>';
            echo '<wp:post_date>' . esc_xml($post->post_date) . '</wp:post_date>';
            echo '<wp:post_type>' . esc_xml($post->post_type) . '</wp:post_type>';
            echo '<content:encoded><![CDATA[' . $post->post_content . ']]></content:encoded>';
            echo '</item>';
        }

        echo '</channel>';
        echo '</rss>';
        exit;
    }
}
add_action('admin_init', 'language_export_wpml_handle_export');

// 6️⃣ Polylang Export Page
function language_export_polylang_page() {
    $languages = pll_get_languages(['fields' => 'slug']);

    echo '<form method="GET">';
    echo '<input type="hidden" name="page" value="language-export">';
    echo '<select name="lang">';
    foreach ($languages as $lang) {
        echo '<option value="' . esc_attr($lang) . '">' . esc_html(strtoupper($lang)) . '</option>';
    }
    echo '</select>';
    echo '<input type="submit" name="export_polylang" value="Export Polylang Data" class="button button-primary">';
    echo '</form>';

    if (isset($_GET['export_polylang']) && isset($_GET['lang'])) {
        language_export_polylang_handle_export($_GET['lang']);
    }
}

// 7️⃣ Handle Polylang Export
function language_export_polylang_handle_export($language_slug) {
    global $wpdb;

    $query = $wpdb->prepare("
        SELECT p.ID, p.post_title, p.post_content, p.post_type
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
        INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
        INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
        WHERE tt.taxonomy = 'language' AND t.slug = %s
    ", $language_slug);

    $posts = $wpdb->get_results($query);

    if (empty($posts)) {
        echo '<div class="notice notice-warning"><p>No posts found for this language.</p></div>';
        return;
    }

    $csv_filename = 'polylang_posts_' . sanitize_file_name($language_slug) . '.csv';

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $csv_filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Title', 'Content', 'Type']);

    foreach ($posts as $post) {
        fputcsv($output, [
            $post->ID,
            sanitize_text_field($post->post_title),
            wp_strip_all_tags($post->post_content),
            sanitize_text_field($post->post_type)
        ]);
    }

    fclose($output);
    exit;
}
