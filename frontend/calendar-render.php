<?php
if (!defined('ABSPATH')) exit;

/**
 * ------------------------------------------------------------
 * 📅 FRONTEND-KALENDER (Mehrere Kalender, robuste Version)
 * Shortcode: [e2t_kalender id="intern" limit="10"]
 * ------------------------------------------------------------
 */

/**
 * Shortcode registrieren
 */
add_shortcode('e2t_kalender', function ($atts) {

    $atts = shortcode_atts([
        'id'    => '',
        'limit' => 10,
    ], $atts, 'e2t_kalender');

    $id    = sanitize_title($atts['id']);
    $limit = max(1, intval($atts['limit']));

    // Kalender-Konfiguration aus Option holen
    $calendars = get_option('e2t_calendars', []);
    $calendar  = null;

    foreach ($calendars as $c) {
        if (!empty($c['id']) && $c['id'] === $id) {
            $calendar = $c;
            break;
        }
    }

    if (!$calendar || empty($calendar['url'])) {
        return '<p>⚠️ Kein gültiger Kalender gefunden.</p>';
    }

    // Events über Cache-Funktion holen
    $events = e2t_calendar_get_events($calendar);

    if (empty($events) || !is_array($events)) {
        return '<p>Keine Termine im Kalender gefunden.</p>';
    }

    // Nur kommende Events behalten
    $today_ts = strtotime('today');

    $events = array_filter($events, function ($e) use ($today_ts) {
        if (empty($e['start'])) {
            return false;
        }
        $ts = strtotime($e['start']);
        if ($ts === false) {
            return false;
        }
        return $ts >= $today_ts;
    });

    error_log('e2t_calendar: Events after date filter for "' . $calendar['id'] . '": ' . count($events));

    if (empty($events)) {
        return '<p>Keine kommenden Termine gefunden.</p>';
    }

    // Nach Startdatum sortieren (aufsteigend)
    usort($events, function ($a, $b) {
        return strcmp($a['start'] ?? '', $b['start'] ?? '');
    });

    // HTML-Ausgabe
    ob_start(); ?>
    <div class="e2t-calendar" data-limit="<?php echo esc_attr($limit); ?>">
        <h2><?php echo esc_html($calendar['name'] ?? 'Kalender'); ?></h2>
        <div class="e2t-calendar-grid">
            <?php
            $index = 0;
            foreach ($events as $e):
                $index++;
                $hidden_class = ($index > $limit) ? ' hidden' : '';
                $start_ts = !empty($e['start']) ? strtotime($e['start']) : false;
                ?>
                <div class="e2t-event-card<?php echo esc_attr($hidden_class); ?>">
                    <?php if ($start_ts): ?>
                        <div class="e2t-event-date">
                            <?php echo esc_html(date_i18n('d.m.Y', $start_ts)); ?>
                        </div>
                    <?php endif; ?>

                    <h3 class="e2t-event-title">
                        <?php echo esc_html($e['title'] ?? '(Ohne Titel)'); ?>
                    </h3>

                    <?php if (!empty($e['location'])): ?>
                        <div class="e2t-event-location">
                            <?php echo esc_html($e['location']); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($e['html_description']) || !empty($e['description'])): ?>
                        <button class="e2t-readmore">Weiterlesen</button>
                        <div class="e2t-event-full hidden">
                            <?php
                            if (!empty($e['html_description'])) {
                                echo wp_kses_post($e['html_description']);
                            } else {
                                echo nl2br(esc_html($e['description']));
                            }
                            ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($e['url'])): ?>
                        <a href="<?php echo esc_url($e['url']); ?>" class="e2t-event-link" target="_blank" rel="noopener">
                            Zur Veranstaltung
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (count($events) > $limit): ?>
            <button class="e2t-load-more">Mehr anzeigen</button>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * ------------------------------------------------------------
 * 🔁 Events mit Cache holen
 * ------------------------------------------------------------
 *
 * - Nutzt eine JSON-Datei pro Kalender-ID als Cache
 * - Überschreibt Cache nur bei erfolgreichem ICS-Fetch
 */
function e2t_calendar_get_events(array $calendar)
{
    $id  = sanitize_title($calendar['id'] ?? 'default');
    $max = !empty($calendar['max']) ? intval($calendar['max']) : 200;

    // Cache-Datei
    $cache_dir  = trailingslashit(E2T_DATA);
    $cache_file = $cache_dir . "calendar-cache-{$id}.json";
    $cache_lifetime = 6 * HOUR_IN_SECONDS;

    if (!is_dir($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }

    $events = [];

    $cache_valid = file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_lifetime);

    if ($cache_valid) {
        $json = file_get_contents($cache_file);
        if ($json !== false && $json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $events = $decoded;
            }
        }
    }

    // Wenn Cache leer/ungültig → neu laden
    if (!$cache_valid || empty($events)) {

        $parsed = e2t_parse_ics($calendar['url'], $max);

        if ($parsed === null) {
            // Fehler beim Holen → alten Cache verwenden (falls vorhanden)
            if (!empty($events)) {
                error_log('e2t_calendar: Using existing cache for "' . $id . '" due to fetch error.');
            } else {
                error_log('e2t_calendar: No cache available and fetch failed for "' . $id . '".');
                $events = [];
            }
        } else {
            // Erfolgreich geparst (auch wenn 0 Events)
            $events = $parsed;
            file_put_contents(
                $cache_file,
                json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );
        }
    }

    return is_array($events) ? $events : [];
}

/**
 * ------------------------------------------------------------
 * 🧩 ICS PARSER (robust, mit "unfolding")
 * ------------------------------------------------------------
 *
 * - Holt ICS per wp_remote_get
 * - Unfolded Zeilen (Weiterführungen mit Leerzeichen/Tab)
 * - Erkennt:
 *   SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION, X-ALT-DESC, URL, CLASS/CLASSIFICATION
 *
 * Rückgabe:
 *   - Array von Events (auch leeres Array, wenn keine vorhanden)
 *   - null bei HTTP-/Transportfehler → Cache wird nicht überschrieben
 */
function e2t_parse_ics($url, $max = 200)
{
    $args = [
        'timeout'     => 7,
        'redirection' => 3,
        'sslverify'   => true,
    ];

    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        error_log('e2t_calendar: HTTP error: ' . $response->get_error_message());
        return null; // Fehler-Signal
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        error_log('e2t_calendar: HTTP status ' . $code . ' for URL ' . $url);
        return null; // Fehler-Signal
    }

    $body = wp_remote_retrieve_body($response);
    if ($body === '' || $body === null) {
        error_log('e2t_calendar: Empty body for URL ' . $url);
        return [];
    }

    // Zeilen auftrennen
    $raw_lines = preg_split("/\r\n|\n|\r/", $body);

    // ICS "unfolding": Zeilen, die mit Leerzeichen/Tab starten, an vorherige Zeile anhängen
    $lines = [];
    foreach ($raw_lines as $raw) {
        if ($raw === '') {
            $lines[] = $raw;
            continue;
        }
        $first = substr($raw, 0, 1);
        if (($first === ' ' || $first === "\t") && !empty($lines)) {
            // An letzte Zeile anhängen, führendes Leerzeichen entfernen
            $lines[count($lines) - 1] .= substr($raw, 1);
        } else {
            $lines[] = $raw;
        }
    }

    $events = [];
    $event  = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if ($line === 'BEGIN:VEVENT') {
            $event = [];
            continue;
        }

        if ($line === 'END:VEVENT') {
            if (!empty($event)) {
                $events[] = $event;
                if (count($events) >= $max) {
                    break;
                }
            }
            $event = [];
            continue;
        }

        // Alle anderen Properties: NAME[;PARAMS]:VALUE
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }

        $name_and_params = substr($line, 0, $pos);  // z.B. DTSTART;TZID=Europe/Berlin
        $value           = substr($line, $pos + 1); // nach dem Doppelpunkt
        $name            = strtoupper(strtok($name_and_params, ';'));

        switch ($name) {
            case 'SUMMARY':
                $event['title'] = e2t_ics_unescape_text($value);
                break;

            case 'DTSTART':
                $event['start'] = e2t_parse_datetime($value);
                break;

            case 'DTEND':
                $event['end'] = e2t_parse_datetime($value);
                break;

            case 'LOCATION':
                $event['location'] = e2t_ics_unescape_text($value);
                break;

            case 'DESCRIPTION':
                $event['description'] = e2t_ics_unescape_text($value);
                break;

            case 'X-ALT-DESC':
                // HTML-Beschreibung (oft mit Formatparametern, daher lieber nur Value)
                $event['html_description'] = $value;
                break;

            case 'URL':
                $event['url'] = trim($value);
                break;

            case 'CLASS':
            case 'CLASSIFICATION':
                $event['category'] = trim($value);
                break;
        }
    }

    error_log('e2t_calendar: Parsed ' . count($events) . ' events from ' . $url);

    return $events;
}

/**
 * ------------------------------------------------------------
 * 🕒 Datumsparser für ICS
 * ------------------------------------------------------------
 *
 * Erwartet Value-Teil nach dem Doppelpunkt, z.B.:
 *  - 20251201T183000
 *  - 20251201T183000Z
 *  - 20251201 (Ganztag)
 */
function e2t_parse_datetime($value)
{
    $value = trim($value);

    // 1) DATETIME mit Uhrzeit (YYYYMMDDTHHMMSS oder YYYYMMDDTHHMMSSZ)
    if (preg_match('/^(\d{8}T\d{6})(Z)?$/', $value, $m)) {
        $str = $m[1];
        return date('Y-m-d H:i:s', strtotime($str));
    }

    // 2) Nur Datum (Ganztag, YYYYMMDD)
    if (preg_match('/^(\d{8})$/', $value, $m)) {
        return date('Y-m-d 00:00:00', strtotime($m[1]));
    }

    // Fallback: vielleicht schon etwas, was strtotime versteht
    $ts = strtotime($value);
    if ($ts !== false) {
        return date('Y-m-d H:i:s', $ts);
    }

    return '';
}

/**
 * ------------------------------------------------------------
 * 🔤 ICS-Text-Unescape
 * ------------------------------------------------------------
 *
 * Wandelt ICS-Escapes wie "\n", "\,", "\;" zurück.
 */
function e2t_ics_unescape_text($text)
{
    $text = str_replace(['\\n', '\\N'], "\n", $text);
    $text = str_replace(['\\,'], ',', $text);
    $text = str_replace(['\\;'], ';', $text);
    $text = str_replace(['\\\\'], '\\', $text);
    return $text;
}

/**
 * ------------------------------------------------------------
 * 💅 Skript & CSS einbinden
 * ------------------------------------------------------------
 */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'e2t-calendar',
        E2T_URL . 'frontend/assets/calendar.css',
        [],
        '1.1'
    );
    wp_enqueue_script(
        'e2t-calendar-js',
        E2T_URL . 'frontend/assets/calendar.js',
        ['jquery'],
        '1.1',
        true
    );
});