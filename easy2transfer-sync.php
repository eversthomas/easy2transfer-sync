<?php

/**
 * Plugin Name: Easy2Transfer Sync
 * Description: Exportiert Mitglieder- und Kontaktdaten aus EasyVerein (API v2.0) und schreibt sie lokal in /.
 * Version: 2.9-stable
 * Author: Thomas Evers / ChatGPT Helper
 * 
 * ⚠️ WICHTIG: Diese Version ist für Strato-Hosting optimiert und getestet.
 * Vor Refactoring: Vollständige Sicherung erstellen und Tests durchführen.
 */

if (!defined('ABSPATH')) exit;

/**
 * ------------------------------------------------------------
 *  🔧 BASIS-KONSTANTEN (einheitlich für alle Module)
 * ------------------------------------------------------------
 */
if (!defined('E2T_DIR'))  define('E2T_DIR', plugin_dir_path(__FILE__));
if (!defined('E2T_PATH')) define('E2T_PATH', plugin_dir_path(__FILE__));
if (!defined('E2T_URL'))  define('E2T_URL', plugin_dir_url(__FILE__));

$upload_dir = wp_upload_dir();

// 📁 Uploads-Verzeichnisse & URLs
if (!defined('E2T_UPLOADS_DIR')) define('E2T_UPLOADS_DIR', trailingslashit($upload_dir['basedir']) . 'easy2transfer-sync/');
if (!defined('E2T_UPLOADS_URL')) define('E2T_UPLOADS_URL', trailingslashit($upload_dir['baseurl']) . 'easy2transfer-sync/');

// 📄 Daten- und Bildpfade
if (!defined('E2T_DATA')) define('E2T_DATA', E2T_UPLOADS_DIR);
if (!defined('E2T_IMG'))  define('E2T_IMG', trailingslashit(E2T_UPLOADS_DIR) . 'img/');

// 🔒 Sicherstellen, dass Ordner existieren
if (!file_exists(E2T_DATA)) wp_mkdir_p(E2T_DATA);
if (!file_exists(E2T_IMG))  wp_mkdir_p(E2T_IMG);

/**
 * ------------------------------------------------------------
 *  🔩 CORE-FUNKTIONEN & CRON
 * ------------------------------------------------------------
 */
require_once E2T_DIR . 'sync/api-core.php';
require_once E2T_DIR . 'sync/api-core-consent.php';
require_once E2T_DIR . 'sync/cron.php';

require_once E2T_DIR . 'admin/calendar-handler.php';

/**
 * ------------------------------------------------------------
 *  🌐 FRONTEND-RENDERING
 * ------------------------------------------------------------
 */
require_once E2T_DIR . 'frontend/renderer.php';
require_once E2T_DIR . 'frontend/map-render.php';
require_once E2T_DIR . 'frontend/shortcode.php';
require_once E2T_DIR . 'frontend/ajax-endpoints.php';

require_once E2T_DIR . 'frontend/calendar-render.php';
// Kalender-Modul
require_once E2T_DIR . 'admin/calendar-handler.php';
require_once E2T_DIR . 'frontend/calendar-render.php';

/**
 * ------------------------------------------------------------
 *  🧩 ADMIN-INTERFACE (Tabs, Sync, Felder, Kalender)
 * ------------------------------------------------------------
 *  UI wird über Callback geladen, um Header-Warnungen zu vermeiden.
 */
add_action('admin_menu', function () {
    add_menu_page(
        'Easy2Transfer Sync',
        'Easy2Transfer Sync',
        'manage_options',
        'easy2transfer-sync',
        'e2t_admin_page',
        'dashicons-update-alt',
        80
    );
});

/**
 * ------------------------------------------------------------
 *  🧠 FELDERVERWALTUNG (CustomField-Konfiguration)
 * ------------------------------------------------------------
 */
require_once E2T_DIR . 'admin/fields-handler.php';

/**
 * ------------------------------------------------------------
 *  🎨 ADMIN-ASSETS: Styles & Scripts
 * ------------------------------------------------------------
 */
add_action('admin_enqueue_scripts', function ($hook) {

    // Nur auf der Plugin-Seite laden
    if (strpos($hook, 'easy2transfer-sync') === false) {
        return;
    }

    /**
     * 🧩 Basis-CSS für das Admin-UI
     */
    wp_enqueue_style(
        'e2t-admin-style',
        E2T_URL . 'admin/assets/admin.css',
        [],
        '1.0'
    );

    /**
     * 🧩 Neue Sidebar-CSS
     */
    wp_enqueue_style(
        'e2t-sidebar-style',
        E2T_URL . 'admin/assets/E2t-sidebar.css',
        [],
        time() // verhindert Cache während der Entwicklung
    );

    /**
     * 🧩 Haupt-JS (Admin Tabs, UI etc.)
     */
    wp_enqueue_script(
        'e2t-admin-script',
        E2T_URL . 'admin/assets/ui.js',
        ['jquery'],
        '1.0',
        true
    );

    /**
     * 🧩 Sortable + Felderverwaltung
     */
    wp_enqueue_script(
        'e2t-sortable',
        E2T_URL . 'admin/vendor/Sortable.min.js',
        [],
        '1.15',
        true
    );

    /**
     * 🧩 Neue Sidebar-Felderverwaltung (Sidebar, Filter, Suche)
     */
    wp_enqueue_script(
        'e2t-fields-sidebar',
        E2T_URL . 'admin/assets/ui-felder-sidebar.js',
        ['jquery', 'e2t-sortable'],
        time(), // während Entwicklung Cache verhindern
        true
    );

    wp_localize_script('e2t-fields-sidebar', 'e2t_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('e2t_felder_nonce')
    ]);


    /**
     * 🔑 AJAX-Variablen bereitstellen
     */
    wp_localize_script('e2t-fields-script', 'e2t_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('e2t_felder_nonce')
    ]);
});

/**
 * ------------------------------------------------------------
 *  🧾 ADMIN CALLBACK
 * ------------------------------------------------------------
 */
function e2t_admin_page()
{
    if (!current_user_can('manage_options')) return;

    // Token speichern
    if (isset($_POST['e2t_token'])) {
        update_option('e2t_api_token', sanitize_text_field($_POST['e2t_token']));
        echo '<div class="updated"><p>Token gespeichert.</p></div>';
    }

    // Consent-Feld-ID speichern
    if (isset($_POST['e2t_consent_field_id'])) {
        $consent_id = intval($_POST['e2t_consent_field_id']);
        if ($consent_id > 0) {
            update_option('e2t_consent_field_id', $consent_id);
            echo '<div class="updated"><p>Consent-Feld-ID gespeichert.</p></div>';
        }
    }

    // Batch-Größe speichern
    if (isset($_POST['e2t_batch_size'])) {
        $batch_size = intval($_POST['e2t_batch_size']);
        if ($batch_size >= 50 && $batch_size <= 500) {
            update_option('e2t_batch_size', $batch_size);
            echo '<div class="updated"><p>Batch-Größe gespeichert.</p></div>';
        }
    }

    // Automatische Fortsetzung speichern
    if (isset($_POST['e2t_auto_continue'])) {
        update_option('e2t_auto_continue', true);
    } else {
        update_option('e2t_auto_continue', false);
    }

    // Admin-UI laden
    require_once E2T_DIR . 'admin/ui-main.php';
}

/**
 * ------------------------------------------------------------
 *  🔁 AJAX: SYNC STARTEN (FULL & CONSENT)
 * ------------------------------------------------------------
 */
add_action('wp_ajax_e2t_start', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['error' => 'Keine Berechtigung']);
    wp_schedule_single_event(time() + 2, 'e2t_run_cron');
    e2t_update_status(0, 0, 'Warte auf WP-Cron ...', 'scheduled');

    if (function_exists('spawn_cron')) {
        spawn_cron();
    } else {
        wp_remote_post(site_url('wp-cron.php'));
    }

    wp_send_json_success(['msg' => 'Sync geplant']);
});

add_action('wp_ajax_e2t_start_consent', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['error' => 'Keine Berechtigung']);

    // Konfiguration auslesen
    $batch_size = (int) get_option('e2t_batch_size', 200);
    $auto_continue = (bool) get_option('e2t_auto_continue', false);
    
    // Gesamtanzahl der Mitglieder aus letztem Sync (falls vorhanden)
    $total_members = (int) get_option('e2t_total_members', 0);
    
    // Aktuellen Durchlauf auslesen
    $part = get_option('e2t_current_part', 1);
    $offset = ($part - 1) * $batch_size;
    
    // Berechne geschätzte Anzahl der Durchläufe
    $estimated_parts = $total_members > 0 ? ceil($total_members / $batch_size) : 0;

    e2t_update_status(0, 0, "Starte Durchlauf $part" . ($estimated_parts > 0 ? " von ~$estimated_parts" : "") . " ...", 'running');
    $result = e2t_run_consent_dump($offset, $batch_size);

    if (isset($result['ok'])) {
        // Speichere Gesamtanzahl für nächste Berechnungen
        // Prüfe sowohl result['members_total'] als auch result['stats']['members_total']
        $actual_total = 0;
        if (isset($result['members_total']) && $result['members_total'] > 0) {
            $actual_total = $result['members_total'];
        } elseif (isset($result['stats']['members_total']) && $result['stats']['members_total'] > 0) {
            $actual_total = $result['stats']['members_total'];
        }
        
        if ($actual_total > 0) {
            update_option('e2t_total_members', $actual_total);
            $total_members = $actual_total;
        }
        
        $msg = "✅ Durchlauf $part abgeschlossen";
        $next = $part + 1;
        $total_parts = $actual_total > 0 ? ceil($actual_total / $batch_size) : 0;
        
        // Prüfe ob noch weitere Durchläufe nötig sind
        // Verwende die direkte Info aus dem Result oder berechne es
        if (isset($result['needs_more'])) {
            $needs_more = $result['needs_more'];
        } else {
            // Fallback-Berechnung
            $needs_more = $actual_total > 0 && ($offset + $batch_size) < $actual_total;
        }
        
        // Debug-Logging
        error_log("E2T Debug: actual_total=$actual_total, offset=$offset, batch_size=$batch_size, needs_more=" . ($needs_more ? 'true' : 'false'));
        
        if ($needs_more) {
            update_option('e2t_current_part', $next);
            
            if ($auto_continue) {
                // Automatische Fortsetzung nach 2 Sekunden
                $msg .= " – Starte automatisch Durchlauf $next in 2 Sekunden...";
                e2t_update_status(100, 100, $msg, 'done');
                wp_send_json_success([
                    'msg' => $msg,
                    'auto_continue' => true,
                    'next_part' => $next,
                    'total_parts' => $total_parts,
                    'actual_total' => $actual_total,
                    'offset' => $offset,
                    'batch_size' => $batch_size
                ]);
            } else {
                $msg .= " – Bitte Sync erneut starten für Durchlauf $next" . ($total_parts > 0 ? " von $total_parts" : "");
                e2t_update_status(100, 100, $msg, 'done');
                wp_send_json_success(['msg' => $msg, 'next_part' => $next, 'total_parts' => $total_parts]);
            }
        } else {
            // Alle Durchläufe abgeschlossen
            delete_option('e2t_current_part');
            delete_option('e2t_total_members');
            $msg .= " – Alle Durchläufe abgeschlossen!";
            e2t_update_status(100, 100, $msg, 'done');
            wp_send_json_success(['msg' => $msg, 'completed' => true]);
        }
    } else {
        // Fehler: Speichere aktuellen Stand für Resume
        update_option('e2t_last_error', [
            'part' => $part,
            'offset' => $offset,
            'error' => $result['error'] ?? 'Unbekannter Fehler',
            'timestamp' => time()
        ]);
        wp_send_json_error(['error' => $result['error'] ?? 'Unbekannter Fehler']);
    }
});

/**
 * ------------------------------------------------------------
 *  🔗 AJAX: PARTS ZUSAMMENFÜHREN (Manuell)
 * ------------------------------------------------------------
 */
add_action('wp_ajax_e2t_merge_parts', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(['error' => 'Keine Berechtigung']);
    
    if (!function_exists('e2t_consent_merge_parts')) {
        require_once E2T_DIR . 'sync/api-core-consent.php';
    }
    
    $result = e2t_consent_merge_parts();
    
    if ($result['success']) {
        wp_send_json_success([
            'msg' => "✅ Zusammenführung erfolgreich: {$result['count']} Mitglieder aus {$result['parts']} Teilen",
            'file' => $result['file'],
            'count' => $result['count']
        ]);
    } else {
        wp_send_json_error(['error' => $result['error'] ?? 'Unbekannter Fehler']);
    }
});


/**
 * ------------------------------------------------------------
 *  📊 AJAX: STATUS ABRUFEN
 * ------------------------------------------------------------
 */
add_action('wp_ajax_e2t_status', function () {
    $file = E2T_DATA . 'status.json';
    if (!file_exists($file)) {
        wp_send_json_success(['state' => 'idle', 'progress' => 0, 'message' => 'Kein Status.']);
    }
    $data = json_decode(file_get_contents($file), true);
    wp_send_json_success($data ?: ['state' => 'unknown', 'progress' => 0]);
});