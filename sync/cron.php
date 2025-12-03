<?php
if (!defined('ABSPATH')) exit;

// ------------------------------------------------------------
// 🔧 BASIS-PFADE AUS HAUPTPLUGIN LADEN (auch bei WP-Cron nutzbar)
// ------------------------------------------------------------
if (!defined('E2T_DATA')) {
    $main_file = plugin_dir_path(__DIR__) . 'easy2transfer-sync.php';
    if (file_exists($main_file)) {
        require_once $main_file; // zentrale Konstanten laden
    } else {
        // Fallback, falls Hauptplugin nicht geladen ist (z. B. WP-Cron im CLI)
        $upload_dir = wp_upload_dir();
        define('E2T_UPLOADS_DIR', trailingslashit($upload_dir['basedir']) . 'easy2transfer-sync/');
        define('E2T_UPLOADS_URL', trailingslashit($upload_dir['baseurl']) . 'easy2transfer-sync/');
        define('E2T_DATA', E2T_UPLOADS_DIR);
        define('E2T_IMG', E2T_UPLOADS_DIR . 'img/');
    }
}

// API-Dateien sicher einbinden
require_once E2T_DIR . 'sync/api-core.php';
require_once E2T_DIR . 'sync/api-core-consent.php';

/**
 * Status-Datei schreiben
 */
function e2t_update_status($progress, $total, $message = '', $state = 'running', $extra = [])
{
    if (!is_dir(E2T_DATA)) mkdir(E2T_DATA, 0777, true);

    $data = [
        'state'     => $state,
        'progress'  => $progress ?? 0,
        'total'     => $total,
        'message'   => $message,
        'timestamp' => date('c')
    ];

    if (is_array($extra)) {
        $data = array_merge($data, $extra);
    }

    file_put_contents(E2T_DATA . 'status.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * 🕒 Standard-Sync (Full Dump)
 */
add_action('e2t_run_cron', function () {
    e2t_update_status(0, 0, 'Starte Synchronisation ...', 'running');
    try {
        $result = e2t_run_full_dump();
        if (isset($result['error'])) {
            e2t_update_status(0, 0, $result['error'], 'error');
        } else {
            e2t_update_status(
                100,
                $result['_meta']['members'] ?? 0,
                'Fertig (Alle Mitglieder).',
                'done'
            );
        }
    } catch (Throwable $e) {
        e2t_update_status(0, 0, $e->getMessage(), 'error');
    }
});

/**
 * 🕒 Consent-Sync (gefilterte Mitglieder mit Einwilligung)
 */
add_action('e2t_run_cron_consent', function () {
    e2t_update_status(0, 0, 'Starte Consent-Sync ...', 'running');
    try {
        $result = e2t_run_consent_dump();
        if (isset($result['error'])) {
            e2t_update_status(0, 0, $result['error'], 'error');
        } else {
            e2t_update_status(
                100,
                $result['_meta']['members_with_consent'] ?? 0,
                'Fertig (Consent-Mitglieder).',
                'done'
            );
        }
    } catch (Throwable $e) {
        e2t_update_status(0, 0, $e->getMessage(), 'error');
    }
});
