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
    // Fallback (z. B. bei direkter Einbindung)
    $upload_dir = wp_upload_dir();
    define('E2T_UPLOADS_DIR', trailingslashit($upload_dir['basedir']) . 'easy2transfer-sync/');
    define('E2T_UPLOADS_URL', trailingslashit($upload_dir['baseurl']) . 'easy2transfer-sync/');
    define('E2T_DATA', E2T_UPLOADS_DIR);
    define('E2T_IMG', E2T_UPLOADS_DIR . 'img/');
  }
}


function e2t_render_members()
{
  $members_file = E2T_DATA . 'members_consent.json';
  $config_file  = E2T_DATA . 'fields-config.json';
  $img_dir      = E2T_DATA . 'img/';

  if (!file_exists($members_file) || !file_exists($config_file)) {
    return '<p>❌ Benötigte Daten wurden nicht gefunden.</p>';
  }

  error_log('E2T_DATA = ' . E2T_DATA);
  error_log('members_consent exists? ' . (file_exists(E2T_DATA . 'members_consent.json') ? 'yes' : 'no'));

  $members_data = json_decode(file_get_contents($members_file), true);
  $config_data  = json_decode(file_get_contents($config_file), true);

  if (!isset($members_data['data']) || !is_array($members_data['data'])) {
    return '<p>Keine Mitgliederdaten gefunden.</p>';
  }

  $members = $members_data['data'];
  $config  = is_array($config_data) ? $config_data : [];

  // ----------------------------------------------------------
  // 🔢 Sortierung nach order (globale Grundsortierung)
  // ----------------------------------------------------------
  usort($config, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

  // ----------------------------------------------------------
  // 🧠 Hilfsfunktion: Wert eines Feldes auslesen
  // ----------------------------------------------------------
  $get_value = function ($member, $fid) {

    // 1) member.* Felder
    if (str_starts_with($fid, 'member.')) {
        $key = substr($fid, 7);

        // EasyVerein: viele Member-Felder liegen in "contact"
        if (isset($member['contact'][$key])) {
            return $member['contact'][$key];
        }
        if (isset($member['member'][$key])) {
            return $member['member'][$key];
        }
        return '';
    }

    // 2) cf.* (CustomFields – extracted)
    if (str_starts_with($fid, 'cf.')) {
        $cfid = substr($fid, 3);
        if (isset($member['member_cf_extracted'][$cfid])) {
            $cf = $member['member_cf_extracted'][$cfid];
            return $cf['display_value'] 
                ?? $cf['value'] 
                ?? '';
        }
        return '';
    }

    // 3) cfraw.*
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

    // 4) contact.*
    if (str_starts_with($fid, 'contact.')) {
        $key = substr($fid, 8);
        return $member['contact'][$key] ?? '';
    }

    // 5) contactcf.*
    if (str_starts_with($fid, 'contactcf.')) {
        $cfid = substr($fid, 10);
        if (isset($member['contact_cf_extracted'][$cfid])) {
            $cf = $member['contact_cf_extracted'][$cfid];
            return $cf['display_value'] 
                ?? $cf['value'] 
                ?? '';
        }
        return '';
    }

    // 6) contactcfraw.*
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

    // 7) consent.*
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


  // ----------------------------------------------------------
  // 🎨 Hilfsfunktion: Feldwert formatiert ausgeben
  // ----------------------------------------------------------
  $format_value = function ($value, $format) {
    $value = wp_kses_post($value);

    // JSON-Arrays hübsch darstellen
    if (is_string($value) && str_starts_with($value, '[')) {
      $decoded = json_decode($value, true);
      if (is_array($decoded)) {
        $value = implode(', ', $decoded);
      }
    }

    // Klickbare Links
    if (preg_match('/^(https?:\/\/|www\.)/i', $value)) {
      if (!str_starts_with($value, 'http')) {
        $value = 'https://' . $value;
      }
      $value = '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . esc_html($value) . '</a>';
    }

    // Formatierung
    switch ($format) {
      case 'bold':
        $value = '<strong>' . $value . '</strong>';
        break;
      case 'heading':
        $value = '<h3 class="e2t-heading">' . $value . '</h3>';
        break;
      default:
        break;
    }

    return $value;
  };

  // ----------------------------------------------------------
  // 🧩 Hilfsfunktion: Felder einer Area rendern (mit stabiler Sortierung)
  // ----------------------------------------------------------
  $render_area = function ($member, $config, $area, $get_value, $format_value) {
    $html = '';

    // 1️⃣ Filtere alle sichtbaren Felder dieser Area und sortiere nach 'order'
    $fields_in_area = array_filter($config, fn($f) => !empty($f['show']) && $f['area'] === $area);
    usort($fields_in_area, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

    $current_group = null;
    $group_html = '';

    // 2️⃣ Iteriere in Reihenfolge und erkenne Gruppenwechsel
    foreach ($fields_in_area as $field) {
      if ($field['id'] === '_profilePicture') continue;

      $fid    = $field['id'];
      $label  = isset($field['label']) && trim($field['label']) !== '' ? esc_html($field['label']) : '';
      $format = $field['format'] ?? 'normal';
      $group  = $field['inline_group'] ?? null;

      // Gruppenwechsel: vorherige Gruppe abschließen
      if ($current_group !== null && $group !== $current_group) {
        if ($current_group) {
          $html .= "<div class='e2t-inline-group e2t-inline-{$current_group}'>{$group_html}</div>";
        } else {
          $html .= $group_html;
        }
        $group_html = '';
      }

      // Feldwert abrufen
      $raw_value = $get_value($member, $fid);

      // JSON-Feld dekodieren
            // JSON-Feld dekodieren (und Einzelwerte für Filter speichern)
      $raw_values_for_attr = [];

      if (is_string($raw_value) && str_starts_with(trim($raw_value), '[')) {
        $decoded = json_decode($raw_value, true);
        if (is_array($decoded)) {
          // Wenn das Feld ein JSON-Array ist, alle Werte merken
          $raw_values_for_attr = array_map('trim', $decoded);
          $raw_value = implode(', ', $decoded);
        }
      } elseif (is_string($raw_value) && str_contains($raw_value, ',')) {
        // Falls der Wert schon als Kommaliste gespeichert ist
        $raw_values_for_attr = array_map('trim', explode(',', $raw_value));
      } else {
        // Einzelwert
        $raw_values_for_attr = [trim((string)$raw_value)];
      }

      if ($raw_value === '' || $raw_value === '[]' || $raw_value === 'null') continue;

      $formatted_value = $format_value($raw_value, $format);

      // 💾 Daten-Attribut: alle Einzelwerte als mit | getrennte Liste speichern
      $raw_value_attr = esc_attr(implode('|', $raw_values_for_attr));


      if ($label && ($field['show_label'] ?? true)) {
        $group_html .= "<div class='e2t-field' data-id='{$fid}' data-value='{$raw_value_attr}'><strong>{$label}:</strong> {$formatted_value}</div>";
      } else {
        $group_html .= "<div class='e2t-field' data-id='{$fid}' data-value='{$raw_value_attr}'>{$formatted_value}</div>";
      }

      $current_group = $group;
    }

    // 3️⃣ Letzte Gruppe anhängen
    if ($group_html !== '') {
      if ($current_group) {
        $html .= "<div class='e2t-inline-group e2t-inline-{$current_group}'>{$group_html}</div>";
      } else {
        $html .= $group_html;
      }
    }

    return $html;
  };

  // ----------------------------------------------------------
  // 🔍 Filterleiste rendern (wenn Filterfelder definiert sind)
  // ----------------------------------------------------------
  $filter_fields = array_filter($config, fn($f) => !empty($f['filterable']));

  ob_start();
  if (!empty($filter_fields)) :
?>
    <div class="e2t-filterbar">
      <?php foreach ($filter_fields as $field): ?>
        <div class="e2t-filter">
          <label><?php echo esc_html($field['label']); ?></label>
          <select data-field="<?php echo esc_attr($field['id']); ?>">
            <option value="">Alle</option>
          </select>
        </div>
      <?php endforeach; ?>

      <div class="e2t-filter e2t-search">
        <input type="text" id="e2t-search" placeholder="Suche nach Name...">
      </div>

      <button id="e2t-reset" class="e2t-btn-reset">Zurücksetzen</button>
    </div>
  <?php
  endif;
  $filterbar_html = ob_get_clean();

  // ----------------------------------------------------------
  // 🧱 Mitgliederkarten rendern
  // ----------------------------------------------------------
  ob_start(); ?>
  <div class="e2t-members-grid">
    <?php foreach ($members as $member): ?>
      <?php
      $id = $member['id'];
      $img_path = E2T_IMG . $id . '.png';
      $img_url  = E2T_UPLOADS_URL . 'img/' . $id . '.png';
      ?>
      <div class="e2t-member-card">
        <?php if (file_exists($img_path)): ?>
          <div class="e2t-member-image">
            <img src="<?php echo esc_url($img_url); ?>" alt="Profilbild">
          </div>
        <?php endif; ?>

        <div class="e2t-member-content">
          <div class="e2t-fields-top">
            <?php echo $render_area($member, $config, 'above', $get_value, $format_value); ?>
          </div>

          <?php $below_html = $render_area($member, $config, 'below', $get_value, $format_value); ?>
          <?php if ($below_html): ?>
            <button class="e2t-toggle-btn" aria-expanded="false">Mehr anzeigen</button>
            <div class="e2t-fields-middle" hidden>
              <?php echo $below_html; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <button id="e2t-load-more" class="e2t-load-more" data-loaded="25">Mehr anzeigen</button>
<?php
  $grid_html = ob_get_clean();

  // ----------------------------------------------------------
  // 🔚 Rückgabe: Filterleiste + Grid zusammen
  // ----------------------------------------------------------
  return $filterbar_html . $grid_html;
}