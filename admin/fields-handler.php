<?php
if (!defined('ABSPATH')) exit;

/**
 * Fields Handler for easy2transfer-sync (2025)
 *
 * - Scannt members_consent.json
 * - Führt Auto-Felder + Config (fields-config.json) zusammen
 * - Liefert Felder für das Backend-UI (Ajax e2t_get_fields)
 * - Speichert Config inkl.:
 *   - label
 *   - area (above/below/unused)
 *   - order
 *   - show_label
 *   - filterable
 *   - inline_group
 *   - favorite
 *   - ignored
 */

/* ============================================================
 * BASISPFAD / KONSTANTE
 * ============================================================ */

// Wenn das Plugin an anderer Stelle E2T_DATA schon definiert, weiterverwenden:
if (!defined('E2T_DATA')) {
    define(
        'E2T_DATA',
        trailingslashit(WP_CONTENT_DIR . '/uploads/easy2transfer-sync/')
    );
}

/* ============================================================
 * JSON HELFER
 * ============================================================ */

function e2t_load_json($file) {
    $path = E2T_DATA . $file;
    if (!file_exists($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function e2t_save_json($file, $data) {
    $path = E2T_DATA . $file;

    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        } else {
            mkdir($dir, 0775, true);
        }
    }

    file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
}

/* ============================================================
 * CONFIG LADEN (mit Migration Array -> Objekt)
 * ============================================================ */

function e2t_load_fields_config() {
    $cfg = e2t_load_json('fields-config.json');
    if (!$cfg) {
        return [];
    }

    // Alte Struktur? (Array von Objekten mit id)
    if (isset($cfg[0]) && is_array($cfg[0]) && isset($cfg[0]['id'])) {
        $migrated = [];
        foreach ($cfg as $item) {
            if (!isset($item['id'])) continue;
            $id = (string)$item['id'];
            $migrated[$id] = $item;
        }
        e2t_save_json('fields-config.json', $migrated);
        return $migrated;
    }

    // Neue Struktur: Objekt (assoziatives Array)
    return $cfg;
}

/* ============================================================
 * ID NORMALISIERUNG
 * ============================================================ */

function e2t_norm_id($id) {
    return (string)$id;
}

/* ============================================================
 * FELDER EXTRAHIEREN AUS members_consent.json
 * ============================================================ */

function e2t_extract_all_fields() {
    $json = e2t_load_json('members_consent.json');
    if (!$json) {
        return ['fields' => []];
    }

    if (!isset($json['data']) || !is_array($json['data'])) {
        if (is_array($json) && isset($json[0]) && is_array($json[0])) {
            $records = $json;
        } else {
            return ['fields' => []];
        }
    } else {
        $records = $json['data'];
    }

    $fields = [];

    foreach ($records as $r) {

        /** ----------------------------
         * 1) MEMBER-FELDER
         * ---------------------------- */
        if (isset($r['member']) && is_array($r['member'])) {
            foreach ($r['member'] as $key => $val) {
                $id = 'member.' . $key;
                if (!isset($fields[$id])) {
                    $fields[$id] = [
                        'id'      => $id,
                        'type'    => 'member',
                        'example' => $val,
                    ];
                }
            }
        }

        /** ----------------------------
         * 2) MEMBER_CF (roh)
         * ---------------------------- */
        if (isset($r['member_cf']) && is_array($r['member_cf'])) {
            foreach ($r['member_cf'] as $cf) {
                if (!isset($cf['customField'])) continue;

                $url  = $cf['customField'];
                $path = parse_url($url, PHP_URL_PATH);
                $fid  = $path ? basename($path) : null;
                if (!$fid) continue;

                $id = 'cfraw.' . e2t_norm_id($fid);

                if (!isset($fields[$id])) {
                    $fields[$id] = [
                        'id'      => $id,
                        'type'    => 'cfraw',
                        'example' => isset($cf['value']) ? $cf['value'] : null,
                    ];
                }
            }
        }

        /** ----------------------------
         * 3) MEMBER_CF_EXTRACTED
         * ---------------------------- */
        if (isset($r['member_cf_extracted']) && is_array($r['member_cf_extracted'])) {
            foreach ($r['member_cf_extracted'] as $fid => $cf) {
                $id = 'cf.' . e2t_norm_id($fid);

                $example = null;
                if (isset($cf['display_value']) && $cf['display_value'] !== '') {
                    $example = $cf['display_value'];
                } elseif (isset($cf['value'])) {
                    $example = $cf['value'];
                }

                if (!isset($fields[$id])) {
                    $fields[$id] = [
                        'id'      => $id,
                        'type'    => 'cf',
                        'example' => $example,
                    ];
                }
            }
        }

        /** ----------------------------
         * 4) CONTACT-FELDER
         * ---------------------------- */
        if (isset($r['contact']) && is_array($r['contact'])) {
            foreach ($r['contact'] as $key => $val) {
                $id = 'contact.' . $key;
                if (!isset($fields[$id])) {
                    $fields[$id] = [
                        'id'      => $id,
                        'type'    => 'contact',
                        'example' => $val,
                    ];
                }
            }
        }

        /** ----------------------------
         * 5) CONTACT_CF (roh)
         * ---------------------------- */
        if (isset($r['contact_cf']) && is_array($r['contact_cf'])) {
            foreach ($r['contact_cf'] as $cf) {
                if (!isset($cf['customField'])) continue;

                $url  = $cf['customField'];
                $path = parse_url($url, PHP_URL_PATH);
                $fid  = $path ? basename($path) : null;
                if (!$fid) continue;

                $id = 'contactcfraw.' . e2t_norm_id($fid);

                if (!isset($fields[$id])) {
                    $fields[$id] = [
                        'id'      => $id,
                        'type'    => 'contactcfraw',
                        'example' => isset($cf['value']) ? $cf['value'] : null,
                    ];
                }
            }
        }

        /** ----------------------------
         * 6) CONTACT_CF_EXTRACTED
         * ---------------------------- */
        if (isset($r['contact_cf_extracted']) && is_array($r['contact_cf_extracted'])) {
            foreach ($r['contact_cf_extracted'] as $fid => $cf) {
                $id = 'contactcf.' . e2t_norm_id($fid);

                $example = null;
                if (isset($cf['display_value']) && $cf['display_value'] !== '') {
                    $example = $cf['display_value'];
                } elseif (isset($cf['value'])) {
                    $example = $cf['value'];
                }

                if (!isset($fields[$id])) {
                    $fields[$id] = [
                        'id'      => $id,
                        'type'    => 'contactcf',
                        'example' => $example,
                    ];
                }
            }
        }

        /** ----------------------------
         * 7) CONSENTS (Altstruktur)
         * ---------------------------- */
        if (isset($r['consents']) && is_array($r['consents'])) {
            foreach ($r['consents'] as $c) {
                if (!isset($c['id'])) continue;

                $cid = e2t_norm_id($c['id']);
                $id  = 'consent.' . $cid;
                $val = isset($c['value']) ? $c['value'] : null;

                if (!isset($fields[$id])) {
                    $fields[$id] = [
                        'id'      => $id,
                        'type'    => 'consent',
                        'example' => $val,
                    ];
                }
            }
        }
    }

    return ['fields' => $fields];
}

/* ============================================================
 * FELDER + CONFIG MERGEN
 * ============================================================ */

function e2t_merge_fields($auto_fields, $config) {
    $merged = [];

    foreach ($auto_fields as $id => $auto) {

        $defaults = [
            'id'           => $id,
            'label'        => $id,         // Backend überschreibt
            'show'         => false,
            'order'        => 999,
            'area'         => 'unused',
            'show_label'   => true,
            'filterable'   => false,
            'inline_group' => '',
            'example'      => isset($auto['example']) ? $auto['example'] : null,
            'type'         => isset($auto['type']) ? $auto['type'] : 'unknown',
            'favorite'     => false,
            'ignored'      => false,
        ];

        if (isset($config[$id]) && is_array($config[$id])) {
            // Config überschreibt Anzeige-Infos, favorite/ignored etc.
            $merged[$id] = array_merge($defaults, $config[$id]);

            // Beispiel & Typ kommen immer aus Auto-SCAN
            $merged[$id]['example'] = $defaults['example'];
            $merged[$id]['type']    = $defaults['type'];
        } else {
            $merged[$id] = $defaults;
        }
    }

    // Sortierung: erst nach area, dann nach order, dann nach label
    uasort($merged, function ($a, $b) {
        if ($a['area'] !== $b['area']) {
            return strcmp($a['area'], $b['area']);
        }
        if ($a['order'] !== $b['order']) {
            return $a['order'] <=> $b['order'];
        }
        return strcmp($a['label'], $b['label']);
    });

    return $merged;
}

/* ============================================================
 * AJAX: FELDER LADEN (BACKEND)
 * ============================================================ */

add_action('wp_ajax_e2t_get_fields', function () {

    check_ajax_referer('e2t_felder_nonce', 'nonce');

    $config = e2t_load_fields_config();
    $scan   = e2t_extract_all_fields();

    if (empty($scan['fields'])) {
        wp_send_json_error(['message' => 'Keine Felder gefunden. Prüfe members_consent.json.']);
    }

    $merged = e2t_merge_fields($scan['fields'], $config);

    wp_send_json_success([
        'fields' => array_values($merged),
    ]);
});

/* ============================================================
 * AJAX: FELDER SPEICHERN (BACKEND)
 * ============================================================ */

add_action('wp_ajax_e2t_save_fields', function () {

    check_ajax_referer('e2t_felder_nonce', 'nonce');

    if (!isset($_POST['fields'])) {
        wp_send_json_error(['message' => 'Keine Felder übermittelt.']);
    }

    $raw = $_POST['fields'];

    // Unterstützung für JSON-String oder Array
    if (is_string($raw)) {
        $decoded    = json_decode(stripslashes($raw), true);
        $fields_raw = is_array($decoded) ? $decoded : [];
    } else {
        $fields_raw = is_array($raw) ? $raw : [];
    }

    if (empty($fields_raw)) {
        wp_send_json_error(['message' => 'Leere Feldliste übermittelt.']);
    }

    $new_config = [];

    foreach ($fields_raw as $item) {
        if (!is_array($item) || !isset($item['id'])) {
            continue;
        }

        $id = e2t_norm_id($item['id']);

        $new_config[$id] = [
            'id'           => $id,
            'label'        => isset($item['label']) && $item['label'] !== ''
                                ? sanitize_text_field($item['label'])
                                : $id,
            'show'         => !empty($item['show']),
            'order'        => isset($item['order']) ? intval($item['order']) : 999,
            'area'         => isset($item['area']) ? sanitize_text_field($item['area']) : 'unused',
            'show_label'   => isset($item['show_label']) ? (bool)$item['show_label'] : true,
            'filterable'   => !empty($item['filterable']),
            'inline_group' => isset($item['inline_group']) ? sanitize_text_field($item['inline_group']) : '',
            'favorite'     => !empty($item['favorite']),
            'ignored'      => !empty($item['ignored']),
        ];
    }

    e2t_save_json('fields-config.json', $new_config);

    wp_send_json_success(['message' => 'Felder gespeichert.']);
});
