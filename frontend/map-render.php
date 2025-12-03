<?php
if (!defined('ABSPATH')) exit;

/**
 * Rendering der Mitglieder-Karte mit Leaflet.js
 */

// Sicherstelle, dass die Konstanten geladen sind
if (!defined('E2T_DATA')) {
    $main_file = plugin_dir_path(__DIR__) . 'easy2transfer-sync.php';
    if (file_exists($main_file)) {
        require_once $main_file;
    } else {
        $upload_dir = wp_upload_dir();
        define('E2T_UPLOADS_DIR', trailingslashit($upload_dir['basedir']) . 'easy2transfer-sync/');
        define('E2T_UPLOADS_URL', trailingslashit($upload_dir['baseurl']) . 'easy2transfer-sync/');
        define('E2T_DATA', E2T_UPLOADS_DIR);
        define('E2T_IMG', E2T_UPLOADS_DIR . 'img/');
    }
}

/**
 * Rendert die Mitglieder-Karte
 */
function e2t_render_members_map()
{
    $members_file = E2T_DATA . 'members_consent.json';
    $config_file = E2T_DATA . 'fields-config.json';

    if (!file_exists($members_file) || !file_exists($config_file)) {
        return '<p>❌ Benötigte Daten wurden nicht gefunden.</p>';
    }

    $members_data = json_decode(file_get_contents($members_file), true);
    $config_data = json_decode(file_get_contents($config_file), true);

    if (!isset($members_data['data']) || !is_array($members_data['data'])) {
        return '<p>Keine Mitgliederdaten gefunden.</p>';
    }

    $members = $members_data['data'];
    $config = is_array($config_data) ? $config_data : [];

    // Sortiere nach order
    usort($config, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

    // Hilfsfunktion: Feldwert abrufen (identisch mit Kacheln-Renderer)
    $get_value = function ($member, $fid) {
        if (str_starts_with($fid, 'member.')) {
            $key = substr($fid, 7);
            if (isset($member['contact'][$key])) {
                return $member['contact'][$key];
            }
            if (isset($member['member'][$key])) {
                return $member['member'][$key];
            }
            return '';
        }

        if (str_starts_with($fid, 'cf.')) {
            $cfid = substr($fid, 3);
            if (isset($member['member_cf_extracted'][$cfid])) {
                $cf = $member['member_cf_extracted'][$cfid];
                return $cf['display_value'] ?? $cf['value'] ?? '';
            }
            return '';
        }

        if (str_starts_with($fid, 'cfraw.')) {
            $cfid = substr($fid, 6);
            if (!empty($member['member_cf'])) {
                foreach ($member['member_cf'] as $cf) {
                    if (str_contains($cf['customField'], $cfid)) {
                        return $cf['value'] ?? '';
                    }
                }
            }
            return '';
        }

        if (str_starts_with($fid, 'contact.')) {
            $key = substr($fid, 8);
            return $member['contact'][$key] ?? '';
        }

        if (str_starts_with($fid, 'contactcf.')) {
            $cfid = substr($fid, 10);
            if (isset($member['contact_cf_extracted'][$cfid])) {
                $cf = $member['contact_cf_extracted'][$cfid];
                return $cf['display_value'] ?? $cf['value'] ?? '';
            }
            return '';
        }

        if (str_starts_with($fid, 'contactcfraw.')) {
            $cfid = substr($fid, 13);
            if (!empty($member['contact_cf'])) {
                foreach ($member['contact_cf'] as $cf) {
                    if (str_contains($cf['customField'], $cfid)) {
                        return $cf['value'] ?? '';
                    }
                }
            }
            return '';
        }

        if (str_starts_with($fid, 'consent.')) {
            $cid = substr($fid, 8);
            if (isset($member['consents'])) {
                foreach ($member['consents'] as $c) {
                    if ((string)$c['id'] === $cid) {
                        return $c['value'] ?? '';
                    }
                }
            }
            return '';
        }

        return '';
    };

    // Filterable Felder sammeln
    $filter_fields = array_filter($config, fn($f) => !empty($f['filterable']));

    // Map-Einstellungen laden
    $map_enabled = (bool) get_option('e2t_map_enabled', 1);
    $map_style = get_option('e2t_map_style', 'light');
    $map_zoom = (int) get_option('e2t_map_zoom', 6);
    $map_center_lat = floatval(get_option('e2t_map_center_lat', 51.1657));
    $map_center_lng = floatval(get_option('e2t_map_center_lng', 10.4515));

    if (!$map_enabled) {
        return '<p>❌ Die Mitglieder-Karte ist nicht aktiviert.</p>';
    }

    // Marker-Daten für JavaScript vorbereiten
    $markers = [];
    foreach ($members as $member) {
        if (empty($member['contact']['geoPositionCoords']) || 
            empty($member['contact']['geoPositionCoords']['lat']) || 
            empty($member['contact']['geoPositionCoords']['lng'])) {
            continue; // Skip members without coordinates
        }

        $coords = $member['contact']['geoPositionCoords'];
        $name = trim(($member['contact']['firstName'] ?? '') . ' ' . ($member['contact']['familyName'] ?? ''));
        $city = $member['contact']['city'] ?? 'N/A';
        $img_path = E2T_IMG . $member['id'] . '.png';
        $img_url = file_exists($img_path) ? E2T_UPLOADS_URL . 'img/' . $member['id'] . '.png' : '';

        // Filter-Werte sammeln
        $filter_values = [];
        foreach ($filter_fields as $field) {
            $fid = $field['id'];
            $value = $get_value($member, $fid);
            
            // JSON-Array dekodieren
            if (is_string($value) && str_starts_with(trim($value), '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }

            $filter_values[$fid] = is_array($value) ? $value : [$value];
        }

        $markers[] = [
            'id' => $member['id'],
            'lat' => floatval($coords['lat']),
            'lng' => floatval($coords['lng']),
            'name' => esc_html($name),
            'city' => esc_html($city),
            'image' => esc_url($img_url),
            'filters' => $filter_values
        ];
    }

    ob_start();
    ?>
    <div class="e2t-map-container">
        <!-- Filterleiste (gleich wie bei Kacheln) -->
        <?php if (!empty($filter_fields)): ?>
            <div class="e2t-filterbar e2t-map-filterbar">
                <?php foreach ($filter_fields as $field): ?>
                    <div class="e2t-filter">
                        <label><?php echo esc_html($field['label']); ?></label>
                        
                        <?php
                        // PLZ-Felder als Textfeld (nicht Dropdown)
                        $is_plz_field = str_contains($field['id'], 'Zip') || str_contains($field['id'], 'zip') || 
                                       str_contains($field['label'], 'PLZ') || str_contains($field['label'], 'Postleitzahl');
                        ?>

                        <?php if ($is_plz_field): ?>
                            <input 
                                type="text" 
                                data-field="<?php echo esc_attr($field['id']); ?>" 
                                class="e2t-map-filter e2t-map-filter-text"
                                placeholder="PLZ eingeben..."
                            >
                        <?php else: ?>
                            <select data-field="<?php echo esc_attr($field['id']); ?>" class="e2t-map-filter e2t-map-filter-select">
                                <option value="">Alle</option>
                            </select>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <button id="e2t-map-reset" class="e2t-btn-reset">Zurücksetzen</button>
            </div>
        <?php endif; ?>

        <!-- Karte -->
        <div id="e2t-members-map" class="e2t-map" style="height: 600px; border-radius: 8px; margin-top: 20px;"></div>

        <!-- Marker-Details-Popup Template -->
        <div id="e2t-marker-popup-template" style="display: none;">
            <div class="e2t-marker-popup">
                <img class="e2t-popup-image" src="" alt="Profilbild" style="display: none;">
                <div class="e2t-popup-content">
                    <h3 class="e2t-popup-name"></h3>
                    <p class="e2t-popup-city"></p>
                    <div class="e2t-popup-details"></div>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="e2t-map-data">
        <?php echo json_encode([
            'markers' => $markers,
            'filterFields' => array_values(array_map(fn($f) => [
                'id' => $f['id'],
                'label' => $f['label'] ?? ''
            ], $filter_fields)),
            'mapSettings' => [
                'center' => [$map_center_lat, $map_center_lng],
                'zoom' => $map_zoom,
                'style' => $map_style
            ],
            'uploadsUrl' => E2T_UPLOADS_URL
        ]); ?>
    </script>

    <?php
    return ob_get_clean();
}

