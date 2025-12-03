<?php
if (!defined('ABSPATH')) exit;

/**
 * ----------------------------------------------------------
 * 🔄 AJAX: Felder abrufen
 * ----------------------------------------------------------
 */
add_action('wp_ajax_e2t_get_fields', function () {
  check_ajax_referer('e2t_felder_nonce', 'nonce');

  $json_path   = E2T_DATA . 'members_consent.json';
  $config_path = E2T_DATA . 'fields-config.json';

  if (!file_exists($json_path)) {
    wp_send_json_error(['message' => 'Die Datei members_consent.json wurde nicht gefunden.']);
  }

  $data = json_decode(file_get_contents($json_path), true);
  if (isset($data['data']) && is_array($data['data'])) {
    $data = $data['data'];
  }

  $fields = [];

  // ------------------------------------------------------------
  // 🖼️ PROFILBILD-FELD
  // ------------------------------------------------------------
  if (!empty($data[0]['member']['_profilePicture'])) {
    $example_img = $data[0]['member']['_profilePicture'];
    $fields['_profilePicture'] = [
      'id'      => '_profilePicture',
      'label'   => 'Profilbild',
      'show'    => true,
      'order'   => 0,
      'area'    => 'above',
      'example' => $example_img,
      'show_label' => false,
      'inline_group' => '',
      'format' => 'normal'
    ];
  }

  // ------------------------------------------------------------
  // 🧩 SYSTEMFELDER (Name, E-Mail, Adresse, etc.)
  // ------------------------------------------------------------
  $system_fields = [
    'firstName'     => 'Vorname',
    'familyName'    => 'Nachname',
    'name'          => 'Name',
    'email'         => 'E-Mail',
    'companyEmail'  => 'E-Mail (Firma)',
    'phone'         => 'Telefon',
    'mobilePhone'   => 'Mobil',
    'street'        => 'Straße',
    'zip'           => 'PLZ',
    'city'          => 'Ort',
    'bio'           => 'Biografie / Beschreibung'
  ];

  $contact = !empty($data[0]['member']['contactDetails'])
    ? $data[0]['member']['contactDetails']
    : (!empty($data[0]['contact']) ? $data[0]['contact'] : []);

  foreach ($system_fields as $key => $label) {
    $example = isset($contact[$key]) ? wp_strip_all_tags($contact[$key]) : '';
    if (strlen($example) > 80) $example = substr($example, 0, 77) . '…';

    $fields[$key] = [
      'id'      => $key,
      'label'   => $label,
      'show'    => false,
      'order'   => 0,
      'area'    => 'unused',
      'example' => $example,
      'show_label' => true,
      'inline_group' => '',
      'format' => 'normal'
    ];
  }

  // ------------------------------------------------------------
  // 🧩 CUSTOM FIELDS (individuelle EasyVerein-Felder)
  // ------------------------------------------------------------
  foreach ($data as $member) {
    if (empty($member['member_cf'])) continue;

    foreach ($member['member_cf'] as $cf) {
      $id = intval(basename($cf['customField']));
      if (!isset($fields[$id])) {
        $val = trim($cf['value'] ?? '');
        if ($val && str_starts_with($val, '[')) {
          $decoded = json_decode($val, true);
          if (is_array($decoded)) $val = implode(', ', $decoded);
        }
        $example = $val ? wp_strip_all_tags($val) : '';
        if (strlen($example) > 80) $example = substr($example, 0, 77) . '…';

        $fields[$id] = [
          'id'      => $id,
          'label'   => "Feld $id",
          'show'    => false,
          'order'   => 0,
          'area'    => 'unused',
          'example' => $example,
          'show_label' => true,
          'inline_group' => '',
          'format' => 'normal'
        ];
      }
    }
  }

  // ------------------------------------------------------------
  // ⚙️ Gespeicherte Config übernehmen (falls vorhanden)
  // ------------------------------------------------------------
  if (file_exists($config_path)) {
    $config = json_decode(file_get_contents($config_path), true);
    if (is_array($config)) {
      foreach ($config as $c) {
        $id = $c['id'] ?? null;
        if ($id && isset($fields[$id])) {
          $fields[$id]['label']       = $c['label'] ?? $fields[$id]['label'];
          $fields[$id]['show']        = $c['show'] ?? false;
          $fields[$id]['order']       = $c['order'] ?? 0;
          $fields[$id]['area']        = $c['area'] ?? 'unused';
          $fields[$id]['show_label']  = $c['show_label'] ?? true;
          $fields[$id]['filterable']  = $c['filterable'] ?? false;
          $fields[$id]['inline_group'] = $c['inline_group'] ?? '';
        }
      }
    }
  }

  // 🔧 Reihenfolge beibehalten, sortiert nach 'order'
  usort($fields, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
  wp_send_json_success($fields);
});

/**
 * ----------------------------------------------------------
 * 💾 AJAX: Felder speichern
 * ----------------------------------------------------------
 */
add_action('wp_ajax_e2t_save_fields', function () {
  check_ajax_referer('e2t_felder_nonce', 'nonce');

  $fields_raw = isset($_POST['fields']) ? json_decode(stripslashes($_POST['fields']), true) : [];

  if (empty($fields_raw)) {
    wp_send_json_error(['message' => 'Keine Felder übermittelt.']);
  }

  // 🧹 Alle Felder säubern und sicherstellen, dass inline_group übernommen wird
  $fields = array_map(function ($f) {
    return [
      'id'           => sanitize_text_field($f['id'] ?? ''),
      'label'        => sanitize_text_field($f['label'] ?? ''),
      'show'         => !empty($f['show']),
      'order'        => intval($f['order'] ?? 0),
      'area'         => sanitize_text_field($f['area'] ?? 'unused'),
      'show_label'   => !empty($f['show_label']),
      'filterable'   => !empty($f['filterable']),
      'inline_group' => sanitize_text_field($f['inline_group'] ?? ''), // 🆕 bleibt erhalten
    ];
  }, $fields_raw);

  // 💾 Datei speichern
  $file = E2T_DATA . 'fields-config.json';
  file_put_contents($file, json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

  wp_send_json_success(['message' => 'Felder gespeichert.']);
});
