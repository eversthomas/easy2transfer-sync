<?php
if (!defined('ABSPATH')) exit;

/**
 * 📅 KALENDER-HANDLER (Mehrkalender)
 */

add_action('admin_post_e2t_save_calendars', function () {
    if (!current_user_can('manage_options')) return;
    check_admin_referer('e2t_save_calendars', 'e2t_calendars_nonce');

    $input = $_POST['e2t_calendars'] ?? [];
    $clean = [];

    foreach ($input as $row) {
    // id auf etwas "Key-taugliches" normalisieren
        $id = sanitize_key($row['id']); // besser als sanitize_title hier

        $clean[$id] = [
            'id'   => $id,
            'name' => sanitize_text_field($row['name']),
            'url'  => esc_url_raw($row['url']),
            'max'  => max(10, intval($row['max'])),
        ];
    }

    update_option('e2t_calendars', $clean);
    wp_safe_redirect(add_query_arg(['page' => 'easy2transfer-sync', 'tab' => 'kalender', 'e2t_saved' => 1], admin_url('admin.php')));
    exit;
});