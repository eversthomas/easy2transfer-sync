<?php
if (!defined('ABSPATH')) exit;

/**
 * Map-Konfiguration im Admin Backend
 * Hier können die Map-Einstellungen konfiguriert werden
 */

// Speichern der Map-Einstellungen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['e2t_map_nonce'])) {
    if (!wp_verify_nonce($_POST['e2t_map_nonce'], 'e2t_map_settings')) {
        wp_die('Sicherheitsüberprüfung fehlgeschlagen.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Keine Berechtigung.');
    }

    update_option('e2t_map_enabled', isset($_POST['e2t_map_enabled']) ? 1 : 0);
    update_option('e2t_map_style', sanitize_text_field($_POST['e2t_map_style'] ?? 'light'));
    update_option('e2t_map_zoom', (int)($_POST['e2t_map_zoom'] ?? 6));
    update_option('e2t_map_center_lat', floatval($_POST['e2t_map_center_lat'] ?? 51.1657));
    update_option('e2t_map_center_lng', floatval($_POST['e2t_map_center_lng'] ?? 10.4515));

    echo '<div class="notice notice-success is-dismissible"><p>✅ Map-Einstellungen gespeichert!</p></div>';
}

$map_enabled = (bool) get_option('e2t_map_enabled', 1);
$map_style = get_option('e2t_map_style', 'light');
$map_zoom = (int) get_option('e2t_map_zoom', 6);
$map_center_lat = floatval(get_option('e2t_map_center_lat', 51.1657));
$map_center_lng = floatval(get_option('e2t_map_center_lng', 10.4515));
?>

<div class="wrap e2t-admin-container">
    <h2>🗺️ Mitglieder-Karte Einstellungen</h2>

    <form method="POST" class="e2t-settings-form">
        <?php wp_nonce_field('e2t_map_settings', 'e2t_map_nonce'); ?>

        <table class="form-table">
            <tbody>
                <!-- Karte aktivieren/deaktivieren -->
                <tr>
                    <th scope="row"><label for="e2t_map_enabled">Karte aktivieren</label></th>
                    <td>
                        <input 
                            type="checkbox" 
                            id="e2t_map_enabled" 
                            name="e2t_map_enabled" 
                            value="1"
                            <?php checked($map_enabled); ?>
                        >
                        <p class="description">
                            Wenn aktiviert, können Benutzer die Mitgliederkarte im Frontend anzeigen.
                            Der Shortcode wird um den Parameter <code>view="map"</code> erweitert.
                        </p>
                    </td>
                </tr>

                <!-- Kartenstil -->
                <tr>
                    <th scope="row"><label for="e2t_map_style">Kartenstil</label></th>
                    <td>
                        <select id="e2t_map_style" name="e2t_map_style">
                            <option value="light" <?php selected($map_style, 'light'); ?>>Hell (Light)</option>
                            <option value="dark" <?php selected($map_style, 'dark'); ?>>Dunkel (Dark)</option>
                        </select>
                        <p class="description">
                            Wähle den Kartenstil, der zu deinem Theme passt.
                        </p>
                    </td>
                </tr>

                <!-- Standard Zoom-Level -->
                <tr>
                    <th scope="row"><label for="e2t_map_zoom">Standard Zoom-Level</label></th>
                    <td>
                        <input 
                            type="number" 
                            id="e2t_map_zoom" 
                            name="e2t_map_zoom" 
                            value="<?php echo esc_attr($map_zoom); ?>"
                            min="1"
                            max="18"
                        >
                        <p class="description">
                            1 = Welt, 6 = Deutschland, 12 = Stadt, 18 = Straße
                        </p>
                    </td>
                </tr>

                <!-- Mittelpunkt Latitude -->
                <tr>
                    <th scope="row"><label for="e2t_map_center_lat">Mittelpunkt Latitude (Nord-Süd)</label></th>
                    <td>
                        <input 
                            type="number" 
                            id="e2t_map_center_lat" 
                            name="e2t_map_center_lat" 
                            value="<?php echo esc_attr($map_center_lat); ?>"
                            step="0.0001"
                            placeholder="51.1657"
                        >
                        <p class="description">
                            Standard: 51.1657 (Deutschlands Mittelpunkt)
                        </p>
                    </td>
                </tr>

                <!-- Mittelpunkt Longitude -->
                <tr>
                    <th scope="row"><label for="e2t_map_center_lng">Mittelpunkt Longitude (Ost-West)</label></th>
                    <td>
                        <input 
                            type="number" 
                            id="e2t_map_center_lng" 
                            name="e2t_map_center_lng" 
                            value="<?php echo esc_attr($map_center_lng); ?>"
                            step="0.0001"
                            placeholder="10.4515"
                        >
                        <p class="description">
                            Standard: 10.4515 (Deutschlands Mittelpunkt)
                        </p>
                    </td>
                </tr>

                <!-- Info zu Filterfeldern -->
                <tr>
                    <th scope="row"><strong>Filter & Feldverwaltung</strong></th>
                    <td>
                        <p class="description">
                            Die Filter für die Karte basieren auf den Einstellungen im Tab <strong>"Felder"</strong>.
                            Jedes Feld mit <code>✓ Filterbar</code> wird automatisch in der Karten-Filterleiste angezeigt.
                        </p>
                        <p class="description" style="margin-top: 10px;">
                            <strong>Empfohlene Filterfelder:</strong>
                            <ul style="margin: 5px 0 0 20px;">
                                <li>Stadt (contact.city)</li>
                                <li>Methoden (customField 50697357)</li>
                            </ul>
                        </p>
                    </td>
                </tr>

                <!-- Info zur Nutzung -->
                <tr>
                    <th scope="row"><strong>Shortcode-Nutzung</strong></th>
                    <td>
                        <p class="description">
                            Verwende den Shortcode <code>[e2t_members]</code> mit verschiedenen Optionen:
                        </p>
                        <pre style="background: #f1f1f1; padding: 10px; border-radius: 3px; font-size: 12px;">
[e2t_members]                  → Nur Mitgliederkacheln
[e2t_members view="map"]       → Nur Karte
[e2t_members view="toggle"]    → Toggle zwischen Kacheln & Karte</pre>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary">💾 Einstellungen speichern</button>
        </p>
    </form>

    <hr style="margin-top: 30px;">
    <p style="color: #666; font-size: 12px;">
        💡 <strong>Tipp:</strong> Die Karte wird automatisch auf neue Daten aktualisiert, wenn du einen neuen Sync durchführst.
    </p>
</div>

<style>
    .e2t-settings-form table {
        width: 100%;
    }

    .e2t-settings-form th {
        font-weight: 600;
        padding: 15px 0;
        border-bottom: 1px solid #ddd;
    }

    .e2t-settings-form td {
        padding: 15px;
        border-bottom: 1px solid #ddd;
    }

    .e2t-settings-form input[type="number"],
    .e2t-settings-form select {
        width: 200px;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 3px;
    }

    .e2t-settings-form .description {
        margin: 8px 0 0 0;
        color: #666;
        font-size: 12px;
    }

    .e2t-settings-form pre {
        overflow-x: auto;
    }
</style>

