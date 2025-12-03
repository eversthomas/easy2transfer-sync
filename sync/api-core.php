<?php

/**
 * Easy2Transfer Voll-Dump (v2.8 – stabil + Cron-kompatibel + Status-Erweiterung)
 * 2025-10-15
 */

if (!defined('ABSPATH')) exit;

// ------------------------------------------------------------
// 🔧 BASIS-PFADE AUS HAUPTPLUGIN LADEN (auch bei WP-Cron nutzbar)
// ------------------------------------------------------------
if (!defined('E2T_DATA')) {
    // Falls Konstanten noch nicht gesetzt, Hauptdatei einmal laden
    $main_file = plugin_dir_path(__DIR__) . 'easy2transfer-sync.php';
    if (file_exists($main_file)) {
        require_once $main_file;
    } else {
        // Fallback (sollte nur in Spezialfällen greifen)
        $upload_dir = wp_upload_dir();
        define('E2T_UPLOADS_DIR', trailingslashit($upload_dir['basedir']) . 'easy2transfer-sync/');
        define('E2T_UPLOADS_URL', trailingslashit($upload_dir['baseurl']) . 'easy2transfer-sync/');
        define('E2T_DATA', E2T_UPLOADS_DIR);
        define('E2T_IMG', E2T_UPLOADS_DIR . 'img/');
    }
}

const E2T_API_VERSION = 'v2.0';
const E2T_API_BASES   = ['https://hexa.easyverein.com/api', 'https://easyverein.com/api'];
const E2T_TIMEOUT     = 45;

function e2t_log($msg)
{
    if (!is_dir(E2T_DATA)) mkdir(E2T_DATA, 0777, true);
    $logfile = E2T_DATA . 'sync.log';
    static $initialized = false;
    if (!$initialized) {
        @file_put_contents($logfile, "=== New Sync Run: " . date('c') . " ===\n");
        $initialized = true;
    }
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $msg\n", 3, $logfile);
}

/**
 * Fortschritt aktualisieren
 */
function e2t_progress($done, $total, $message = '', $extra = [])
{
    if (!function_exists('e2t_update_status')) return;
    $percent = $total > 0 ? round(($done / $total) * 100, 1) : 0;
    e2t_update_status($percent, $total, $message, 'running', $extra);
}

/**
 * Sicherer API-GET mit Drosselungs-Handling
 */
function e2t_api_safe_get(string $path, array $query, string &$token, ?string &$baseUsed = null, int $retries = 3): array
{
    $delay = 15;
    for ($i = 0; $i < $retries; $i++) {
        [$code, $data,,, $url] = e2t_api_get($path, $query, $token, $baseUsed);
        if (isset($data['detail']) && str_contains($data['detail'], 'gedrosselt')) {
            e2t_log("E2T [RATE] Gedrosselt bei $path – Warte $delay s...");
            sleep($delay);
            $delay *= 2;
            continue;
        }
        return [$code, $data, $url];
    }
    e2t_log("E2T [ERROR] Wiederholte Drosselung bei $path – Abbruch nach $retries Versuchen.");
    return [429, ['detail' => 'Drosselung nach mehreren Versuchen'], null];
}

/**
 * Kontaktfelder flach extrahieren
 */
function e2t_extract_flat_contact(array $contact): array
{
    $fields = ['firstName', 'familyName', 'name', 'email', 'companyEmail', 'privateEmail'];
    $flat = [];
    foreach ($fields as $f) {
        $flat[$f] = $contact[$f]
            ?? ($contact['contact'][$f] ?? null)
            ?? ($contact['data'][$f] ?? null)
            ?? ($contact['data']['contact'][$f] ?? null)
            ?? null;
    }
    return array_filter($flat, fn($v) => !is_null($v) && $v !== '');
}

/**
 * Hauptfunktion: Vollständiger Dump
 */
function e2t_run_full_dump(): array
{
    $token = get_option('e2t_api_token', '');
    if (!$token) return ['error' => 'Kein Token'];

    $meta = ['started' => date('c')];
    $baseUsed = null;

    try {
        set_time_limit(0);
        e2t_log("E2T [START] Voll-Dump gestartet");

        // === PHASE 1: IDs ===
        $allMembers = [];
        $page = 1;
        $hasNext = true;
        while ($hasNext && count($allMembers) < 5000) {
            [$code, $data, $url] = e2t_api_safe_get('member', [
                'limit' => 100,
                'page' => $page,
                'showCount' => 'true',
                'query' => '{id, contactDetails{id}, customFields{id,value}}'
            ], $token, $baseUsed);
            e2t_log("E2T [LIST] Page $page $code");
            if ($code !== 200 || !is_array($data)) throw new Exception("Fehler bei Seite $page");
            $list = e2t_norm_list($data);
            foreach ($list as $row) if (!empty($row['id'])) $allMembers[] = (int)$row['id'];
            $hasNext = isset($data['next']) && !empty($data['next']);
            $page++;
            usleep(300000);
        }

        $meta['total_ids'] = count($allMembers);
        e2t_progress(0, $meta['total_ids'], 'IDs gesammelt');

        // === PHASE 2: Detailabrufe ===
        $dump = [];
        $total = count($allMembers);
        $done = 0;
        foreach ($allMembers as $mid) {
            $done++;
            e2t_progress($done, $total, "Mitglied $done / $total", ['current_member' => $mid]);

            [$s1, $d1] = e2t_api_safe_get("member/$mid", ['query' => '{*}'], $token, $baseUsed);
            [$s2, $d2] = e2t_api_safe_get("member/$mid/custom-fields", ['limit' => 400, 'query' => '{*}'], $token, $baseUsed);

            $cid = null;
            if (!empty($d1['contactDetails'])) {
                if (is_array($d1['contactDetails']) && isset($d1['contactDetails']['id'])) $cid = $d1['contactDetails']['id'];
                elseif (is_string($d1['contactDetails']) && preg_match('~/contact-details/(\d+)~', $d1['contactDetails'], $m)) $cid = $m[1];
            }

            $contact = $contactCF = [];
            if ($cid) {
                e2t_progress($done, $total, "Abruf contact-details/$cid", ['current_action' => "contact-details/$cid"]);
                [$s3, $d3] = e2t_api_safe_get("contact-details/$cid", ['query' => '{*}'], $token, $baseUsed);
                [$s4, $d4] = e2t_api_safe_get("contact-details/$cid/custom-fields", ['limit' => 100, 'query' => '{*}'], $token, $baseUsed);
                $contact = $d3 ?? [];
                $contactCF = e2t_norm_list($d4 ?? []);
                $flat = e2t_extract_flat_contact($contact);
                if (!is_array($d1['contactDetails'])) $d1['contactDetails'] = ['url' => (string)$d1['contactDetails']];
                $d1['contactDetails'] = array_merge($d1['contactDetails'], $flat);
            }

            $dump[] = [
                'id' => $mid,
                'contact_id' => $cid,
                'member' => $d1,
                'member_cf' => e2t_norm_list($d2 ?? []),
                'contact' => $contact,
                'contact_cf' => $contactCF
            ];
            usleep(200000);
        }

        // === PHASE 3: JSON ===
        $file = E2T_DATA . 'members_full.json';
        $payload = [
            '_meta' => array_merge($meta, [
                'finished' => date('c'),
                'members' => count($dump),
                'base_used' => $baseUsed,
            ]),
            'data' => $dump,
        ];
        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE));
        e2t_log("E2T [DONE] Dump geschrieben: $file");
        e2t_progress($total, $total, 'Fertig. JSON geschrieben.', ['state' => 'done']);
        return ['ok' => true, 'file' => $file, '_meta' => $meta];
    } catch (Throwable $e) {
        e2t_log("E2T [ERROR] " . $e->getMessage());
        e2t_update_status(0, 0, $e->getMessage(), 'error');
        return ['error' => $e->getMessage()];
    }
}

/** API Basisfunktionen **/
function e2t_api_get(string $path, array $query, string &$token, ?string &$baseUsed = null): array
{
    foreach (E2T_API_BASES as $base) {
        $url = rtrim($base, '/') . '/' . E2T_API_VERSION . '/' . ltrim($path, '/');
        if ($query) $url .= '?' . http_build_query($query);
        $args = ['timeout' => E2T_TIMEOUT, 'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json'
        ]];
        $res = wp_remote_get($url, $args);
        if (is_wp_error($res)) continue;
        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $baseUsed = $base;
        $json = json_decode($body, true);
        return [$code, $json, $body, null, $url];
    }
    throw new Exception('Keine Basis-URL erreichbar');
}

function e2t_norm_list($payload): array
{
    if (!is_array($payload)) return [];
    $keys = array_keys($payload);
    if ($keys === range(0, count($payload) - 1)) return $payload;
    foreach (['results', 'data', 'items', 'list', 'rows'] as $k)
        if (!empty($payload[$k]) && is_array($payload[$k])) return e2t_norm_list($payload[$k]);
    return [];
}
