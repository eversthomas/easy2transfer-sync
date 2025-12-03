<?php

/**
 * Easy2Transfer Consent-Dump (v3.0 – mit Select-Options & Enhanced Debugging)
 * 2025-11-14
 * 
 * ✨ NEU IN V3.0:
 * - Unterstützung für Select-Option Custom Fields
 * - Automatisches Auflösen von selectedOptions URLs
 * - Umfassendes Debugging-System
 * - Besseres Error-Handling
 */

if (!defined('ABSPATH')) exit;

// ------------------------------------------------------------
// 🔧 BASIS-PFADE AUS HAUPTPLUGIN LADEN (auch bei WP-Cron nutzbar)
// ------------------------------------------------------------
if (!defined('E2T_DATA')) {
    $main_file = plugin_dir_path(__DIR__) . 'easy2transfer-sync.php';
    if (file_exists($main_file)) {
        require_once $main_file; // zentrale Konstanten laden
    } else {
        // Fallback, falls Hauptplugin nicht geladen ist (z. B. bei Cron)
        $upload_dir = wp_upload_dir();
        define('E2T_UPLOADS_DIR', trailingslashit($upload_dir['basedir']) . 'easy2transfer-sync/');
        define('E2T_UPLOADS_URL', trailingslashit($upload_dir['baseurl']) . 'easy2transfer-sync/');
        define('E2T_DATA', E2T_UPLOADS_DIR);
        define('E2T_IMG', E2T_UPLOADS_DIR . 'img/');
    }
}

// ------------------------------------------------------------
// ⚙️ KONSTANTEN
// ------------------------------------------------------------
const E2T_CONSENT_API_VERSION = 'v2.0';
const E2T_CONSENT_API_BASES   = ['https://hexa.easyverein.com/api', 'https://easyverein.com/api'];
const E2T_CONSENT_TIMEOUT     = 45;
const E2T_CONSENT_FIELD_ID_DEFAULT = 282018660;

/**
 * Gibt die konfigurierte Consent-Feld-ID zurück
 * Falls nicht gesetzt, wird der Standardwert verwendet
 */
function e2t_get_consent_field_id(): int {
    return (int) get_option('e2t_consent_field_id', E2T_CONSENT_FIELD_ID_DEFAULT);
}

// 🎯 ZIEL-CUSTOM-FIELDS: Welche Custom Fields sollen EXPLIZIT extrahiert werden?
// (Rohdaten ALLER Felder sind weiterhin in member_cf / contact_cf enthalten)
const E2T_TARGET_CUSTOM_FIELDS = [
    50359304,   // Online Angebote? (W)
    50359307,   // Zielgruppen (W)
    50697325,   // Webseite 1 (W)
    50697329,   // Webseite 2 (Wo)
    50697357,   // Leistungsangebote (W)
    50699020,   // Qualifikationshinweis
    50699073,   // Verbandsmitgliedschaften (Wo)
    50698940,   // Netzwerkinteressen
    50698968,   // Finanzierungshinweis (W)
    50799935,   // Weitere Infos zu mir
    51060631,   // Begleitung Klienten
    54800224,   // Anmeldung Plattform
    54809776,   // Datum Anmeldung
    190947959,  // Meine Daten sind korrekt (2024)!
    204293845,  // Daten sind aktuell 2025
    271260978,  // AG-Zugehörigkeit
    282018660,  // Sichtbarkeit Transfer-Webseite (Consent-Feld)
    312976233,  // Methoden / Interventionen (W)
    312570636,  // Check für Web
    313634622,  // Mein Motto (Wo)
    313635546,  // Reserve 1
    313635579,  // Reserve 2
    313635591,  // Reserve 3
    313635633,  // Reserve 4
    313635648,  // Reserve 5
    313635669,  // Reserve 6
    313635690,  // Reserve 7
    313635753,  // Reserve 8
];

// 🐛 DEBUG-MODUS (auf false setzen für Produktion)
const E2T_DEBUG_MODE = false; // Für Strato-Produktion auf false setzen
const E2T_DEBUG_VERBOSE = false; // Für Strato-Produktion auf false setzen

// ------------------------------------------------------------
// 🪵 LOGGING & PROGRESS
// ------------------------------------------------------------
function e2t_consent_log($msg, $level = 'INFO')
{
    if (!is_dir(E2T_DATA)) mkdir(E2T_DATA, 0777, true);
    $logfile = E2T_DATA . 'sync.log';
    static $initialized = false;
    if (!$initialized) {
        @file_put_contents($logfile, "=== New Consent Sync Run: " . date('c') . " ===\n");
        $initialized = true;
    }
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] [$level] [CONSENT] $msg\n";
    error_log($formatted, 3, $logfile);
    
    // In Debug-Modus auch in separate Debug-Log
    if (E2T_DEBUG_MODE && $level === 'DEBUG') {
        $debugfile = E2T_DATA . 'debug.log';
        error_log($formatted, 3, $debugfile);
    }
}

function e2t_consent_progress($done, $total, $message = '', $extra = [])
{
    if (!function_exists('e2t_update_status')) return;
    $percent = $total > 0 ? round(($done / $total) * 100, 1) : 0;
    e2t_update_status($percent, $total, $message, 'running', $extra);
}

// ------------------------------------------------------------
// 🐛 DEBUG-FUNKTIONEN
// ------------------------------------------------------------

/**
 * Loggt detaillierte Informationen über ein Custom Field
 */
function e2t_debug_custom_field(array $cf, string $context = ''): void
{
    if (!E2T_DEBUG_VERBOSE) return;
    
    $debugMsg = "Custom Field Debug" . ($context ? " ($context)" : "") . ":\n";
    $debugMsg .= "  - ID: " . ($cf['id'] ?? 'N/A') . "\n";
    $debugMsg .= "  - CustomField: " . json_encode($cf['customField'] ?? 'N/A') . "\n";
    $debugMsg .= "  - Value: " . json_encode($cf['value'] ?? 'N/A') . "\n";
    $debugMsg .= "  - SelectedOptions: " . json_encode($cf['selectedOptions'] ?? 'N/A') . "\n";
    $debugMsg .= "  - LastChanged: " . ($cf['_lastChanged'] ?? 'N/A');
    
    e2t_consent_log($debugMsg, 'DEBUG');
}

/**
 * Erstellt einen Snapshot aller Custom Fields für ein Mitglied
 */
function e2t_debug_snapshot_custom_fields(int $memberId, array $memberCF, array $contactCF): void
{
    if (!E2T_DEBUG_MODE) return;
    
    $snapshotFile = E2T_DATA . 'cf_snapshot_' . $memberId . '.json';
    $snapshot = [
        'member_id' => $memberId,
        'timestamp' => date('c'),
        'member_custom_fields' => $memberCF,
        'contact_custom_fields' => $contactCF
    ];
    
    file_put_contents($snapshotFile, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    e2t_consent_log("Debug-Snapshot erstellt: $snapshotFile", 'DEBUG');
}

/**
 * Loggt API-Request Details
 */
function e2t_debug_api_request(string $url, int $code, ?array $data = null): void
{
    if (!E2T_DEBUG_VERBOSE) return;
    
    $debugMsg = "API Request:\n";
    $debugMsg .= "  URL: $url\n";
    $debugMsg .= "  Status: $code\n";
    if ($data) {
        $debugMsg .= "  Response Keys: " . implode(', ', array_keys($data));
    }
    
    e2t_consent_log($debugMsg, 'DEBUG');
}

// ------------------------------------------------------------
// 🎯 SELECT OPTIONS AUFLÖSEN
// ------------------------------------------------------------

/**
 * Lädt eine Select-Option und cached sie
 *
 * FIX (2025-11-14):
 * EasyVerein erlaubt bei Select-Optionen KEINE GraphQL-artigen Queries (query={id,label,value}).
 * Jeder Request mit Query → führt zu Status 400.
 * 
 * Daher müssen Select-Option-Requests IMMER OHNE QUERY ausgeführt werden.
 */
function e2t_consent_resolve_select_option(string $optionUrl, string $token, ?string &$baseUsed = null): ?array
{
    static $cache = [];
    static $stats = ['hits' => 0, 'misses' => 0];

    // ----------------------------------------------
    // CACHE CHECK
    // ----------------------------------------------
    if (isset($cache[$optionUrl])) {
        $stats['hits']++;
        if (E2T_DEBUG_VERBOSE && ($stats['hits'] % 10 === 0)) {
            e2t_consent_log("Option-Cache: {$stats['hits']} Hits, {$stats['misses']} Misses", 'DEBUG');
        }
        return $cache[$optionUrl];
    }
    $stats['misses']++;

    // ----------------------------------------------
    // URL PARSEN
    // ----------------------------------------------
    $parsed = parse_url($optionUrl);
    if (!$parsed || !isset($parsed['path'])) {
        e2t_consent_log("Ungültige Option-URL (PARSE FEHLER): $optionUrl", 'ERROR');
        return null;
    }

    // Beispiel: /api/v2.0/custom-field/12345/select-options/777
    $path = preg_replace('~^/api/v2\.0/~', '', $parsed['path']);

    if (empty($path)) {
        e2t_consent_log("Select-Option Pfad konnte nicht extrahiert werden: $optionUrl", 'ERROR');
        return null;
    }

    // ----------------------------------------------
    // API REQUEST — OHNE QUERY!
    // ----------------------------------------------
    try {
        // FIX: Query MUSS LEER SEIN!
        [$code, $data, $url] = e2t_consent_api_safe_get(
            $path,
            [],             // <-- FIX: Query entfernt!
            $token,
            $baseUsed
        );

        e2t_debug_api_request($url, $code, $data);

        if ($code !== 200) {
            e2t_consent_log(
                "Select-Option Request fehlgeschlagen (Status $code): $optionUrl", 
                'WARN'
            );
            return null;
        }

        if (!is_array($data)) {
            e2t_consent_log("Ungültige Select-Option Antwort (kein Array): $optionUrl", 'ERROR');
            return null;
        }

        // ----------------------------------------------
        // OPTION EXTRAHIEREN
        // ----------------------------------------------
        $option = [
            'id'    => $data['id'] ?? null,
            'label' => $data['label'] ?? null,
            'value' => $data['value'] ?? null,
            'url'   => $optionUrl
        ];

        // ----------------------------------------------
        // CACHE SPEICHERN
        // ----------------------------------------------
        $cache[$optionUrl] = $option;

        // ----------------------------------------------
        // DEBUG
        // ----------------------------------------------
        $labelOut = $option['label'] ?? $option['value'] ?? '(leer)';
        e2t_consent_log(
            "Select-Option aufgelöst: {$labelOut} (ID: {$option['id']}) → $optionUrl",
            'INFO'
        );

        return $option;

    } catch (Exception $e) {
        e2t_consent_log(
            "EXCEPTION beim Laden einer Select-Option ($optionUrl): " . $e->getMessage(),
            'ERROR'
        );
        return null;
    }
}

/**
 * Extrahiert Custom Field Werte inklusive Select Options
 * 
 * @param array $customFields Array von Custom Field Objekten
 * @param array $targetFieldIds IDs der zu extrahierenden Felder
 * @param string $token API Token für Select-Option Auflösung
 * @param string|null $baseUsed Verwendete Base-URL
 * @param string $context Kontext für Logging (z.B. "member" oder "contact")
 * @return array Assoziatives Array [field_id => extracted_data]
 */
function e2t_consent_extract_custom_fields_with_options(
    array $customFields, 
    array $targetFieldIds, 
    string $token, 
    ?string &$baseUsed = null,
    string $context = 'unknown'
): array
{
    $extracted = [];
    $foundFields = [];
    
    e2t_consent_log("Extrahiere Custom Fields ($context): " . count($customFields) . " Felder, Suche nach IDs: " . (empty($targetFieldIds) ? 'ALLE' : implode(', ', $targetFieldIds)));
    
    foreach ($customFields as $cfIndex => $cf) {
        // Debug: Zeige jedes Custom Field im Verbose-Modus
        if (E2T_DEBUG_VERBOSE) {
            e2t_debug_custom_field($cf, "$context #$cfIndex");
        }
        
        // ------------------------------------------------
        // Custom Field ID extrahieren (ROBUST wie im Analyse-Skript)
        // ------------------------------------------------
        $fieldId = null;
        
        if (isset($cf['customField']) && is_string($cf['customField'])) {
            // z.B. "https://easyverein.com/api/v2.0/custom-field/50359304"
            $fieldId = (int) basename($cf['customField']);
        }
        
        if (!$fieldId) {
            if (E2T_DEBUG_VERBOSE) {
                e2t_consent_log("Konnte Field-ID nicht extrahieren aus: " . json_encode($cf['customField'] ?? 'N/A'), 'DEBUG');
            }
            continue;
        }
        
        $foundFields[] = $fieldId;
        
        // ------------------------------------------------
        // FILTER: Wenn targetFieldIds LEER ist → ALLE Felder extrahieren.
        // Wenn NICHT leer → nur die angegebenen IDs.
        // ------------------------------------------------
        if (!empty($targetFieldIds) && !in_array($fieldId, $targetFieldIds, true)) {
            continue;
        }
        
        e2t_consent_log("Ziel-Feld gefunden: $fieldId ($context)");
        
        // Basis-Struktur
        $result = [
            'field_id' => $fieldId,
            'record_id' => $cf['id'] ?? null,
            'type' => 'unknown',
            'raw_value' => null,
            'display_value' => null,
            'options' => [],
            'last_changed' => $cf['_lastChanged'] ?? null,
            'context' => $context
        ];
        
        // FALL 1: Auswahlfeld mit selectedOptions
        if (isset($cf['selectedOptions']) && is_array($cf['selectedOptions']) && !empty($cf['selectedOptions'])) {
            $result['type'] = 'select';
            $result['raw_value'] = $cf['selectedOptions'];
            
            e2t_consent_log("Feld $fieldId ist Select-Field mit " . count($cf['selectedOptions']) . " Optionen");
            
            $resolvedOptions = [];
            foreach ($cf['selectedOptions'] as $idx => $optionUrl) {
                if (is_string($optionUrl)) {
                    e2t_consent_log("Löse Option auf: $optionUrl");
                    $option = e2t_consent_resolve_select_option($optionUrl, $token, $baseUsed);
                    if ($option) {
                        $resolvedOptions[] = $option;
                    } else {
                        e2t_consent_log("Option konnte nicht aufgelöst werden: $optionUrl", 'WARN');
                    }
                    
                    // Kleine Pause zwischen Option-Aufrufen
                    usleep(100000); // 0.1 Sekunden
                }
            }
            
            $result['options'] = $resolvedOptions;
            
            // Display-Wert zusammensetzen
            $labels = array_filter(array_map(fn($opt) => $opt['label'] ?? $opt['value'] ?? null, $resolvedOptions));
            $result['display_value'] = !empty($labels) ? implode(', ', $labels) : '(leer)';
            
            e2t_consent_log("✓ Field $fieldId ($context, Select): " . $result['display_value']);
        }
        // FALL 2: Einfaches value Feld (Freitext, Checkbox, etc.)
        elseif (isset($cf['value'])) {
            $value = $cf['value'];
            
            if (is_bool($value)) {
                $result['type'] = 'boolean';
                $result['raw_value'] = $value;
                $result['display_value'] = $value ? 'Ja' : 'Nein';
            } elseif (is_array($value)) {
                $result['type'] = 'array';
                $result['raw_value'] = $value;
                $result['display_value'] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $result['type'] = 'text';
                $result['raw_value'] = $value;
                $result['display_value'] = (string)$value;
            }
            
            e2t_consent_log("✓ Field $fieldId ($context, {$result['type']}): " . $result['display_value']);
        }
        // FALL 3: selectedOptions existiert aber ist leer
        elseif (isset($cf['selectedOptions'])) {
            $result['type'] = 'select';
            $result['display_value'] = '(keine Auswahl)';
            e2t_consent_log("Field $fieldId ($context, Select): leer");
        }
        // FALL 4: Feld existiert aber ist komplett leer
        else {
            $result['type'] = 'empty';
            $result['display_value'] = '';
            e2t_consent_log("Field $fieldId ($context): komplett leer");
        }
        
        $extracted[$fieldId] = $result;
    }
    
    // Prüfe ob alle gesuchten Felder gefunden wurden
    $missingFields = [];
    if (!empty($targetFieldIds)) {
        $missingFields = array_diff($targetFieldIds, array_keys($extracted));
    }
    if (!empty($missingFields)) {
        e2t_consent_log("⚠️ Fehlende Felder im $context: " . implode(', ', $missingFields), 'WARN');
        e2t_consent_log("Gefundene Field-IDs im $context: " . implode(', ', array_unique($foundFields)), 'DEBUG');
    }
    
    return $extracted;
}

// ------------------------------------------------------------
// 🔒 SICHERER API-GET
// ------------------------------------------------------------
function e2t_consent_api_safe_get(string $path, array $query, string &$token, ?string &$baseUsed = null, int $retries = 3): array
{
    $delay = 15;
    for ($i = 0; $i < $retries; $i++) {
        [$code, $data,,, $url] = e2t_consent_api_get($path, $query, $token, $baseUsed);
        
        e2t_debug_api_request($url, $code, $data);
        
        if (isset($data['detail']) && str_contains($data['detail'], 'gedrosselt')) {
            e2t_consent_log("Rate-Limit erreicht bei $path – Warte $delay s...", 'WARN');
            sleep($delay);
            $delay *= 2;
            continue;
        }
        return [$code, $data, $url];
    }
    e2t_consent_log("Wiederholte Drosselung bei $path – Abbruch nach $retries Versuchen.", 'ERROR');
    return [429, ['detail' => 'Drosselung nach mehreren Versuchen'], null];
}

// ------------------------------------------------------------
// 📋 KONTAKTFELDER FLACH EXTRAHIEREN
// ------------------------------------------------------------
function e2t_consent_extract_flat_contact(array $contact): array
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

// ------------------------------------------------------------
// 🧩 HAUPTFUNKTION: CONSENT-DUMP
// ------------------------------------------------------------
function e2t_run_consent_dump(int $offset = 0, int $limit = 200): array
{
    $token = get_option('e2t_api_token', '');
    if (!$token) return ['error' => 'Kein Token'];

    // Hole Batch-Größe aus Option (Fallback auf Parameter)
    $batch_size = (int) get_option('e2t_batch_size', $limit);
    if ($batch_size < 50) $batch_size = $limit; // Sicherheitscheck
    if ($batch_size > 500) $batch_size = 500; // Max-Limit
    
    $limit = $batch_size; // Verwende konfigurierte Batch-Größe

    $meta = ['started' => date('c'), 'offset' => $offset, 'limit' => $limit];
    $baseUsed = null;
    
    // Statistiken für Debugging
    $stats = [
        'members_checked' => 0,
        'members_with_consent' => 0,
        'members_without_consent' => 0,
        'custom_fields_found' => 0,
        'select_options_resolved' => 0,
        'errors' => []
    ];

    try {
        set_time_limit(0);
        e2t_consent_log("========================================");
        e2t_consent_log("Consent-Dump gestartet – Offset $offset, Limit $limit");
        e2t_consent_log("Debug-Modus: " . (E2T_DEBUG_MODE ? 'AN' : 'AUS'));
        e2t_consent_log("Target Custom Fields: " . (empty(E2T_TARGET_CUSTOM_FIELDS) ? 'ALLE' : implode(', ', E2T_TARGET_CUSTOM_FIELDS)));
        e2t_consent_log("========================================");

        // === PHASE 1: IDs einsammeln ===
        $allMembers = [];
        $page = 1;
        $hasNext = true;

        e2t_consent_log("PHASE 1: Sammle Mitglieder-IDs...");

        while ($hasNext && count($allMembers) < 5000) {
            [$code, $data, $url] = e2t_consent_api_safe_get('member', [
                'limit' => 100,
                'page' => $page,
                'showCount' => 'true',
                'query' => '{id, contactDetails{id}, customFields{id,value}}'
            ], $token, $baseUsed);

            if ($code !== 200 || !is_array($data)) {
                $error = "Fehler bei Seite $page: Status $code";
                e2t_consent_log($error, 'ERROR');
                throw new Exception($error);
            }

            $list = e2t_consent_norm_list($data);
            foreach ($list as $row) {
                if (!empty($row['id'])) $allMembers[] = (int)$row['id'];
            }

            $hasNext = isset($data['next']) && !empty($data['next']);
            e2t_consent_log("Seite $page: " . count($list) . " Mitglieder, Total: " . count($allMembers));
            $page++;
            usleep(200000);
        }

        $total = count($allMembers);
        $start = $offset;
        $end = min($offset + $limit, $total);
        $meta['range'] = [$start, $end];
        $meta['total_members'] = $total;
        
        // Speichere Gesamtanzahl für Progress-Tracking
        update_option('e2t_total_members', $total);
        
        // Berechne geschätzte Durchläufe
        $estimated_parts = ceil($total / $limit);
        $current_part = floor($offset / $limit) + 1;
        
        e2t_consent_log("✓ IDs gesammelt: $total gesamt, verarbeite $start–$end (Durchlauf $current_part von ~$estimated_parts)");
        e2t_consent_progress($start, $total, "IDs gesammelt – Durchlauf $current_part/$estimated_parts ($start–$end von $total)");

        // === PHASE 2: Details abrufen + Consent filtern ===
        $filtered = [];
        
        e2t_consent_log("PHASE 2: Lade Mitglieder-Details und filtere nach Consent...");

        for ($i = $start; $i < $end; $i++) {
            // Prüfe ob noch Zeit vorhanden ist (Strato: 15 Minuten Limit)
            // Nach 13 Minuten stoppen, um Zeit für Speichern zu haben
            $elapsed = time() - strtotime($meta['started']);
            if ($elapsed > 780) { // 13 Minuten = 780 Sekunden
                e2t_consent_log("⚠️ Zeitlimit erreicht (13 Minuten) – stoppe bei Mitglied $i", 'WARN');
                e2t_update_status($i, $total, "Zeitlimit erreicht bei Mitglied " . ($i + 1), 'paused');
                // Speichere aktuellen Stand
                update_option('e2t_last_processed', [
                    'member_index' => $i,
                    'member_id' => $allMembers[$i] ?? null,
                    'part' => floor($offset / $limit) + 1,
                    'offset' => $offset,
                    'timestamp' => time(),
                    'reason' => 'timeout'
                ]);
                // Speichere bisherige Daten als Part
                if (!empty($filtered)) {
                    $partNum = intval(floor($offset / $limit)) + 1;
                    $file = E2T_DATA . "members_consent_part{$partNum}.json";
                    $payload = [
                        '_meta' => array_merge($meta, [
                            'finished' => date('c'),
                            'members_total' => $total,
                            'members_checked' => $stats['members_checked'],
                            'members_with_consent' => count($filtered),
                            'incomplete' => true,
                            'stopped_at' => $i
                        ]),
                        'data' => $filtered,
                    ];
                    file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    e2t_consent_log("⚠️ Teil $partNum unvollständig gespeichert (Zeitlimit)");
                }
                return [
                    'ok' => false,
                    'error' => 'Zeitlimit erreicht',
                    'stopped_at' => $i,
                    'processed' => count($filtered),
                    'part' => floor($offset / $limit) + 1
                ];
            }
            
            $mid = $allMembers[$i];
            $stats['members_checked']++;
            
            // Berechne Gesamtfortschritt über alle Durchläufe
            $current_part = floor($offset / $limit) + 1;
            $total_parts = ceil($total / $limit);
            $overall_progress = ($i + 1) / $total * 100;
            
            // Speichere aktuellen Stand für Resume-Funktionalität (alle 10 Mitglieder)
            if ($i % 10 === 0) {
                update_option('e2t_last_processed', [
                    'member_index' => $i,
                    'member_id' => $mid,
                    'part' => $current_part,
                    'offset' => $offset,
                    'timestamp' => time()
                ]);
            }
            
            e2t_consent_progress($overall_progress, $total, "Durchlauf $current_part/$total_parts – Mitglied " . ($i + 1) . " / $total", [
                'current_member' => $mid,
                'part' => $current_part,
                'total_parts' => $total_parts,
                'elapsed_seconds' => $elapsed
            ]);
            e2t_consent_log("--- Verarbeite Mitglied $mid (" . ($i + 1) . "/$total, Durchlauf $current_part/$total_parts, {$elapsed}s) ---");

            try {
                [$s1, $d1] = e2t_consent_api_safe_get("member/$mid", ['query' => '{*}'], $token, $baseUsed);
                [$s2, $d2] = e2t_consent_api_safe_get("member/$mid/custom-fields", ['limit' => 400, 'query' => '{*}'], $token, $baseUsed);

                $member_cf = e2t_consent_norm_list($d2 ?? []);
                $has_consent = false;
                $consent_field_id = e2t_get_consent_field_id();

                e2t_consent_log("Prüfe Consent-Feld " . $consent_field_id . "...");

                foreach ($member_cf as $cf) {
                    if (isset($cf['customField']) && str_contains($cf['customField'], (string)$consent_field_id)) {
                        e2t_consent_log("Consent-Feld gefunden");
                        
                        // Unterstützt beide: value='true' UND selectedOptions
                        if (isset($cf['value']) && strtolower(trim($cf['value'])) === 'true') {
                            $has_consent = true;
                            e2t_consent_log("✓ Consent gegeben (via value)");
                            break;
                        }
                        // Falls Consent als Select-Field implementiert ist
                        if (isset($cf['selectedOptions']) && !empty($cf['selectedOptions'])) {
                            $has_consent = true;
                            e2t_consent_log("✓ Consent gegeben (via selectedOptions)");
                            break;
                        }
                    }
                }

                if (!$has_consent) {
                    $stats['members_without_consent']++;
                    e2t_consent_log("Kein Consent – überspringe Mitglied $mid");
                    continue;
                }
                
                $stats['members_with_consent']++;
                e2t_consent_log("✓ Mitglied $mid hat Consent – extrahiere Daten");

                // Extrahiere die spezifischen Custom Fields MIT Select-Options
                $extracted_member_cf = e2t_consent_extract_custom_fields_with_options(
                    $member_cf, 
                    E2T_TARGET_CUSTOM_FIELDS, 
                    $token, 
                    $baseUsed,
                    'member'
                );
                
                if (!empty($extracted_member_cf)) {
                    $stats['custom_fields_found'] += count($extracted_member_cf);
                }

                // ------------------------------------------------------------
                // 📞 Kontakt-Details abrufen
                // ------------------------------------------------------------
                $cid = null;
                if (!empty($d1['contactDetails'])) {
                    if (is_array($d1['contactDetails']) && isset($d1['contactDetails']['id'])) {
                        $cid = $d1['contactDetails']['id'];
                    } elseif (is_string($d1['contactDetails']) && preg_match('~/contact-details/(\d+)~', $d1['contactDetails'], $m)) {
                        $cid = $m[1];
                    }
                }

                $contact = $contactCF = [];
                $extracted_contact_cf = [];
                
                if ($cid) {
                    e2t_consent_log("Lade Contact-Details $cid...");
                    e2t_consent_progress($i + 1, $total, "Abruf contact-details/$cid", ['current_action' => "contact-details/$cid"]);
                    
                    [$s3, $d3] = e2t_consent_api_safe_get("contact-details/$cid", ['query' => '{*}'], $token, $baseUsed);
                    [$s4, $d4] = e2t_consent_api_safe_get("contact-details/$cid/custom-fields", ['limit' => 100, 'query' => '{*}'], $token, $baseUsed);
                    
                    $contact = $d3 ?? [];
                    $contactCF = e2t_consent_norm_list($d4 ?? []);
                    
                    // Extrahiere auch Contact Custom Fields (falls später benötigt)
                    $extracted_contact_cf = e2t_consent_extract_custom_fields_with_options(
                        $contactCF, 
                        E2T_TARGET_CUSTOM_FIELDS, 
                        $token, 
                        $baseUsed,
                        'contact'
                    );
                    
                    if (!empty($extracted_contact_cf)) {
                        $stats['custom_fields_found'] += count($extracted_contact_cf);
                    }
                    
                    $flat = e2t_consent_extract_flat_contact($contact);
                    if (!is_array($d1['contactDetails'])) $d1['contactDetails'] = ['url' => (string)$d1['contactDetails']];
                    $d1['contactDetails'] = array_merge($d1['contactDetails'], $flat);
                }
                
                // Debug-Snapshot erstellen (nur im Debug-Modus)
                if (E2T_DEBUG_MODE && ($i - $start) < 3) { // Nur erste 3 Mitglieder
                    e2t_debug_snapshot_custom_fields($mid, $member_cf, $contactCF);
                }

                // ------------------------------------------------------------
                // 🖼️ Profilbild herunterladen
                // WICHTIG: Bilder werden IMMER neu geholt (nicht gecacht)
                // Dies stellt sicher, dass Profilbilder aktuell sind
                // ------------------------------------------------------------
                if (!empty($d1['_profilePicture'])) {
                    $img_url  = $d1['_profilePicture'];
                    $img_id   = $mid;
                    
                    e2t_consent_log("Lade Profilbild für Mitglied $mid ...");
                    $response = wp_remote_get($img_url, [
                        'timeout' => 30,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'Accept' => 'image/*'
                        ]
                    ]);
                    
                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $body = wp_remote_retrieve_body($response);
                        $headers = wp_remote_retrieve_headers($response);
                        $content_type = $headers['content-type'] ?? 'image/jpeg';
                        $ext = match (true) {
                            str_contains($content_type, 'png')  => 'png',
                            str_contains($content_type, 'webp') => 'webp',
                            str_contains($content_type, 'gif')  => 'gif',
                            str_contains($content_type, 'jpeg'),
                            str_contains($content_type, 'jpg')  => 'jpg',
                            default => 'jpg',
                        };
                        
                        // Lösche alte Bilder (auch wenn gleiches Format, um sicherzustellen dass es aktuell ist)
                        foreach (glob(E2T_IMG . $img_id . '.*') as $old_file) @unlink($old_file);
                        
                        $img_path = E2T_IMG . $img_id . '.' . $ext;
                        file_put_contents($img_path, $body);
                        e2t_consent_log("✓ Profilbild gespeichert: $img_path");
                        $d1['_profilePicture'] = E2T_UPLOADS_URL . 'img/' . $img_id . '.' . $ext;
                    } else {
                        e2t_consent_log("Profilbild konnte nicht geladen werden für $mid", 'WARN');
                    }
                }

                // Mitglied-Datensatz speichern
                $filtered[] = [
                    'id' => $mid,
                    'member' => $d1,
                    'member_cf' => $member_cf,
                    'member_cf_extracted' => $extracted_member_cf,
                    'contact' => $contact,
                    'contact_cf' => $contactCF,
                    'contact_cf_extracted' => $extracted_contact_cf
                ];
                
                e2t_consent_log("✓ Mitglied $mid vollständig verarbeitet");
                usleep(150000);
                
            } catch (Exception $e) {
                $error = "Fehler bei Mitglied $mid: " . $e->getMessage();
                $trace = $e->getTraceAsString();
                e2t_consent_log($error, 'ERROR');
                e2t_consent_log("Stack Trace: " . substr($trace, 0, 500), 'ERROR');
                $stats['errors'][] = $error;
                
                // Bei kritischen Fehlern (z.B. Memory) stoppen
                if (strpos($e->getMessage(), 'memory') !== false || 
                    strpos($e->getMessage(), 'timeout') !== false ||
                    strpos($e->getMessage(), 'Maximum execution time') !== false) {
                    e2t_consent_log("KRITISCHER FEHLER – stoppe Sync bei Mitglied $mid", 'ERROR');
                    e2t_update_status($i, $total, "Kritischer Fehler: " . $e->getMessage(), 'error');
                    return [
                        'ok' => false,
                        'error' => $e->getMessage(),
                        'stopped_at' => $i,
                        'processed' => count($filtered),
                        'part' => floor($offset / $limit) + 1
                    ];
                }
                
                // Fehler loggen aber weiter machen
                continue;
            }
        }

        // === PHASE 3: JSON schreiben ===
        $partNum = intval(floor($offset / $limit)) + 1;
        $file = E2T_DATA . "members_consent_part{$partNum}.json";

        e2t_consent_log("PHASE 3: Schreibe JSON-Datei Teil $partNum...");

        $total_parts = ceil($total / $limit);
        
        $payload = [
            '_meta' => array_merge($meta, [
                'finished' => date('c'),
                'members_total' => $total,
                'members_checked' => $stats['members_checked'],
                'members_with_consent' => count($filtered),
                'members_without_consent' => $stats['members_without_consent'],
                'custom_fields_extracted' => $stats['custom_fields_found'],
                'base_used' => $baseUsed,
                'part' => $partNum,
                'total_parts' => $total_parts,
                'batch_size' => $limit,
                'debug_mode' => E2T_DEBUG_MODE,
                'target_fields' => E2T_TARGET_CUSTOM_FIELDS,
                'errors_count' => count($stats['errors']),
                'errors' => E2T_DEBUG_MODE ? $stats['errors'] : []
            ]),
            'data' => $filtered,
        ];

        file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        e2t_consent_log("✓ Teil $partNum geschrieben: $file (" . count($filtered) . " Mitglieder)");

        // === AUTOMATISCHE ZUSAMMENFÜHRUNG am Ende ===
        if ($end >= $total) {
            e2t_consent_log("PHASE 4: Führe alle Teile zusammen...");
            $merge_result = e2t_consent_merge_parts();
            if ($merge_result['success']) {
                e2t_consent_log("✓ Alle Teile zusammengeführt → {$merge_result['file']} ({$merge_result['count']} Mitglieder)");
            } else {
                e2t_consent_log("⚠️ Zusammenführung fehlgeschlagen: " . $merge_result['error'], 'ERROR');
            }
        }

        // === FINAL STATISTICS ===
        e2t_consent_log("========================================");
        e2t_consent_log("SYNC ABGESCHLOSSEN - STATISTIK:");
        e2t_consent_log("  Geprüfte Mitglieder: {$stats['members_checked']}");
        e2t_consent_log("  Mit Consent: {$stats['members_with_consent']}");
        e2t_consent_log("  Ohne Consent: {$stats['members_without_consent']}");
        e2t_consent_log("  Custom Fields extrahiert: {$stats['custom_fields_found']}");
        e2t_consent_log("  Fehler: " . count($stats['errors']));
        e2t_consent_log("  Datei: $file");
        e2t_consent_log("========================================");

        e2t_consent_progress($end, $total, "Teil $partNum abgeschlossen", ['state' => 'done', 'stats' => $stats]);
        
        // WICHTIG: Füge members_total zu stats hinzu für die Berechnung weiterer Durchläufe
        $stats['members_total'] = $total;
        $stats['offset'] = $offset;
        $stats['limit'] = $limit;
        $stats['end'] = $end;
        
        return [
            'ok' => true, 
            'file' => $file, 
            'part' => $partNum,
            'stats' => $stats,
            'members_total' => $total, // Explizit für einfacheren Zugriff
            'needs_more' => ($end < $total) // Direkte Info ob weitere Durchläufe nötig sind
        ];

    } catch (Throwable $e) {
        e2t_consent_log("KRITISCHER FEHLER: " . $e->getMessage(), 'ERROR');
        e2t_consent_log("Stack Trace: " . $e->getTraceAsString(), 'ERROR');
        e2t_update_status(0, 0, $e->getMessage(), 'error');
        return [
            'error' => $e->getMessage(),
            'stats' => $stats
        ];
    }
}


// ------------------------------------------------------------
// 🔗 API BASISFUNKTIONEN
// ------------------------------------------------------------
function e2t_consent_api_get(string $path, array $query, string &$token, ?string &$baseUsed = null): array
{
    foreach (E2T_CONSENT_API_BASES as $base) {
        $url = rtrim($base, '/') . '/' . E2T_CONSENT_API_VERSION . '/' . ltrim($path, '/');
        if ($query) $url .= '?' . http_build_query($query);
        
        $args = [
            'timeout' => E2T_CONSENT_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json'
            ]
        ];
        
        $res = wp_remote_get($url, $args);
        
        if (is_wp_error($res)) {
            e2t_consent_log("WP Error bei $url: " . $res->get_error_message(), 'ERROR');
            continue;
        }
        
        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $baseUsed = $base;
        $json = json_decode($body, true);
        
        if ($json === null && $body !== '') {
            e2t_consent_log("JSON Parse Error bei $url", 'ERROR');
            e2t_consent_log("Response Body: " . substr($body, 0, 500), 'DEBUG');
        }
        
        return [$code, $json, $body, null, $url];
    }
    
    $error = 'Keine Basis-URL erreichbar';
    e2t_consent_log($error, 'ERROR');
    throw new Exception($error);
}

// ------------------------------------------------------------
// 🧮 NORMALISIERE LISTEN
// ------------------------------------------------------------
function e2t_consent_norm_list($payload): array
{
    if (!is_array($payload)) {
        if (E2T_DEBUG_VERBOSE) {
            e2t_consent_log("norm_list: Payload ist kein Array: " . gettype($payload), 'DEBUG');
        }
        return [];
    }
    
    $keys = array_keys($payload);
    
    // Bereits numerisches Array
    if ($keys === range(0, count($payload) - 1)) {
        return $payload;
    }
    
    // Suche nach bekannten List-Keys
    foreach (['results', 'data', 'items', 'list', 'rows'] as $k) {
        if (!empty($payload[$k]) && is_array($payload[$k])) {
            if (E2T_DEBUG_VERBOSE) {
                e2t_consent_log("norm_list: Liste gefunden unter Key '$k'", 'DEBUG');
            }
            return e2t_consent_norm_list($payload[$k]);
        }
    }
    
    if (E2T_DEBUG_VERBOSE) {
        e2t_consent_log("norm_list: Keine Liste gefunden, verfügbare Keys: " . implode(', ', $keys), 'DEBUG');
    }
    
    return [];
}

// ------------------------------------------------------------
// 🛠️ ZUSÄTZLICHE DEBUG-HILFSFUNKTIONEN
// ------------------------------------------------------------

/**
 * Erstellt einen Gesundheitscheck-Report
 */
function e2t_consent_health_check(): array
{
    $report = [
        'timestamp' => date('c'),
        'directories' => [
            'E2T_DATA' => [
                'path' => E2T_DATA,
                'exists' => is_dir(E2T_DATA),
                'writable' => is_writable(E2T_DATA)
            ],
            'E2T_IMG' => [
                'path' => E2T_IMG,
                'exists' => is_dir(E2T_IMG),
                'writable' => is_writable(E2T_IMG)
            ]
        ],
        'files' => [],
        'config' => [
            'debug_mode' => E2T_DEBUG_MODE,
            'debug_verbose' => E2T_DEBUG_VERBOSE,
            'target_fields' => E2T_TARGET_CUSTOM_FIELDS,
            'consent_field' => e2t_get_consent_field_id(),
            'api_bases' => E2T_CONSENT_API_BASES
        ],
        'token' => [
            'configured' => !empty(get_option('e2t_api_token', ''))
        ]
    ];
    
    // Prüfe vorhandene JSON-Dateien
    foreach (glob(E2T_DATA . 'members_consent*.json') as $file) {
        $report['files'][basename($file)] = [
            'size' => filesize($file),
            'modified' => date('c', filemtime($file))
        ];
    }
    
    return $report;
}

/**
 * Schreibt Health-Check Report
 */
function e2t_consent_write_health_check(): string
{
    $report = e2t_consent_health_check();
    $file = E2T_DATA . 'health_check.json';
    file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    e2t_consent_log("Health-Check Report erstellt: $file");
    return $file;
}

/**
 * Analysiert Custom Fields in einem bestehenden JSON
 */
function e2t_consent_analyze_existing_json(string $jsonFile): array
{
    if (!file_exists($jsonFile)) {
        return ['error' => 'Datei nicht gefunden'];
    }
    
    $data = json_decode(file_get_contents($jsonFile), true);
    if (!$data || !isset($data['data'])) {
        return ['error' => 'Ungültige JSON-Struktur'];
    }
    
    $analysis = [
        'total_members' => count($data['data']),
        'fields_found' => [],
        'field_types' => [],
        'sample_values' => []
    ];
    
    foreach ($data['data'] as $member) {
        // Analysiere Member Custom Fields
        if (isset($member['member_cf_extracted'])) {
            foreach ($member['member_cf_extracted'] as $fieldId => $fieldData) {
                if (!isset($analysis['fields_found'][$fieldId])) {
                    $analysis['fields_found'][$fieldId] = 0;
                    $analysis['field_types'][$fieldId] = [];
                    $analysis['sample_values'][$fieldId] = [];
                }
                $analysis['fields_found'][$fieldId]++;
                $analysis['field_types'][$fieldId][$fieldData['type']] = 
                    ($analysis['field_types'][$fieldId][$fieldData['type']] ?? 0) + 1;
                
                if (count($analysis['sample_values'][$fieldId]) < 5) {
                    $analysis['sample_values'][$fieldId][] = $fieldData['display_value'];
                }
            }
        }
        
        // Analysiere Contact Custom Fields
        if (isset($member['contact_cf_extracted'])) {
            foreach ($member['contact_cf_extracted'] as $fieldId => $fieldData) {
                $key = "contact_$fieldId";
                if (!isset($analysis['fields_found'][$key])) {
                    $analysis['fields_found'][$key] = 0;
                    $analysis['field_types'][$key] = [];
                    $analysis['sample_values'][$key] = [];
                }
                $analysis['fields_found'][$key]++;
                $analysis['field_types'][$key][$fieldData['type']] = 
                    ($analysis['field_types'][$key][$fieldData['type']] ?? 0) + 1;
                
                if (count($analysis['sample_values'][$key]) < 5) {
                    $analysis['sample_values'][$key][] = $fieldData['display_value'];
                }
            }
        }
    }
    
    return $analysis;
}

// ------------------------------------------------------------
// 📊 DEBUG-COMMAND FÜR TESTING
// ------------------------------------------------------------

/**
 * Führt alle Part-Dateien zu members_consent.json zusammen
 * Kann auch manuell aufgerufen werden
 */
function e2t_consent_merge_parts(): array
{
    try {
        $merged = [];
        $mergedMeta = [
            'merged_at' => date('c'),
            'parts_count' => 0,
            'total_members' => 0
        ];
        
        // Suche alle Part-Dateien (bis zu 20 Parts)
        for ($p = 1; $p <= 20; $p++) {
            $path = E2T_DATA . "members_consent_part{$p}.json";
            if (!file_exists($path)) {
                if ($p === 1) {
                    return [
                        'success' => false,
                        'error' => 'Keine Parts zum Zusammenführen gefunden'
                    ];
                }
                continue;
            }
            
            $data = json_decode(file_get_contents($path), true);
            if (!empty($data['data']) && is_array($data['data'])) {
                $merged = array_merge($merged, $data['data']);
                $mergedMeta['parts_count']++;
                e2t_consent_log("Part $p hinzugefügt: " . count($data['data']) . " Mitglieder");
            }
        }

        if (empty($merged)) {
            return [
                'success' => false,
                'error' => 'Keine Daten zum Zusammenführen gefunden'
            ];
        }

        $mergedMeta['total_members'] = count($merged);
        
        $mergedFile = E2T_DATA . 'members_consent.json';
        $mergedPayload = [
            '_meta' => $mergedMeta,
            'data' => $merged
        ];
        
        $result = file_put_contents($mergedFile, json_encode($mergedPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Konnte Datei nicht schreiben: ' . $mergedFile
            ];
        }
        
        return [
            'success' => true,
            'file' => $mergedFile,
            'count' => $mergedMeta['total_members'],
            'parts' => $mergedMeta['parts_count']
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Test-Funktion: Analysiert ein einzelnes Mitglied im Detail
 * Verwendung: e2t_consent_debug_single_member(123456)
 */
function e2t_consent_debug_single_member(int $memberId): array
{
    $token = get_option('e2t_api_token', '');
    if (!$token) return ['error' => 'Kein Token'];
    
    $baseUsed = null;
    $result = [
        'member_id' => $memberId,
        'timestamp' => date('c'),
        'steps' => []
    ];
    
    try {
        // Schritt 1: Member laden
        e2t_consent_log("DEBUG: Lade Mitglied $memberId");
        [$s1, $d1] = e2t_consent_api_safe_get("member/$memberId", ['query' => '{*}'], $token, $baseUsed);
        $result['steps']['member_load'] = ['status' => $s1, 'has_data' => !empty($d1)];
        
        // Schritt 2: Member Custom Fields laden
        e2t_consent_log("DEBUG: Lade Member Custom Fields");
        [$s2, $d2] = e2t_consent_api_safe_get("member/$memberId/custom-fields", ['limit' => 400, 'query' => '{*}'], $token, $baseUsed);
        $member_cf = e2t_consent_norm_list($d2 ?? []);
        $result['steps']['member_cf_load'] = [
            'status' => $s2, 
            'count' => count($member_cf),
            'raw_data' => $member_cf
        ];
        
        // Schritt 3: Extrahiere Custom Fields
        e2t_consent_log("DEBUG: Extrahiere Custom Fields");
        $extracted = e2t_consent_extract_custom_fields_with_options(
            $member_cf, 
            E2T_TARGET_CUSTOM_FIELDS, 
            $token, 
            $baseUsed,
            'debug'
        );
        $result['steps']['extraction'] = $extracted;
        
        // Schritt 4: Consent prüfen
        $has_consent = false;
        $consent_field_id = e2t_get_consent_field_id();
        foreach ($member_cf as $cf) {
            if (isset($cf['customField']) && str_contains($cf['customField'], (string)$consent_field_id)) {
                if ((isset($cf['value']) && strtolower(trim($cf['value'])) === 'true') ||
                    (isset($cf['selectedOptions']) && !empty($cf['selectedOptions']))) {
                    $has_consent = true;
                    break;
                }
            }
        }
        $result['steps']['consent_check'] = ['has_consent' => $has_consent];
        
        // Speichere Debug-Report
        $reportFile = E2T_DATA . "debug_member_{$memberId}.json";
        file_put_contents($reportFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        e2t_consent_log("DEBUG: Report gespeichert: $reportFile");
        
        return $result;
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
        e2t_consent_log("DEBUG ERROR: " . $e->getMessage(), 'ERROR');
        return $result;
    }
}
