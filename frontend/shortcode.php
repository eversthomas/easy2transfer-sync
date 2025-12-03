<?php
if (!defined('ABSPATH')) exit;

/**
 * Shortcode: [e2t_members]
 * ----------------------------------------------------------
 * Rendert Mitgliederkarten oder Karte im Frontend
 * 
 * Optionen:
 *   view="kachel"  → Nur Mitgliederkacheln (Standard)
 *   view="map"     → Nur Karte
 *   view="toggle"  → Toggle zwischen Kacheln & Karte
 */
add_shortcode('e2t_members', function ($atts) {

  // 🔧 Attribute
  $atts = shortcode_atts([
    'view' => 'kachel'  // kachel, map, oder toggle
  ], $atts, 'e2t_members');

  $view = sanitize_text_field($atts['view']);

  // 🎨 Frontend-Assets einbinden (Kacheln)
  wp_enqueue_style(
    'e2t-frontend-style',
    E2T_URL . 'frontend/assets/frontend.css',
    [],
    '1.0'
  );

  wp_enqueue_script(
    'e2t-frontend-script',
    E2T_URL . 'frontend/assets/frontend.js',
    ['jquery'],
    '1.0',
    true
  );

  // Map-Assets einbinden (wenn Karte benötigt)
  if (in_array($view, ['map', 'toggle'])) {
    // Leaflet.js CSS
    wp_enqueue_style(
      'leaflet-css',
      'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css',
      [],
      '1.9.4'
    );

    // Leaflet.js
    wp_enqueue_script(
      'leaflet-js',
      'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js',
      [],
      '1.9.4',
      true
    );

    // Leaflet Cluster CSS (für viele Marker)
    wp_enqueue_style(
      'leaflet-cluster-css',
      'https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.1/MarkerCluster.min.css',
      [],
      '1.5.1'
    );

    wp_enqueue_style(
      'leaflet-cluster-default-css',
      'https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.1/MarkerCluster.Default.min.css',
      [],
      '1.5.1'
    );

    // Leaflet Cluster JS
    wp_enqueue_script(
      'leaflet-cluster-js',
      'https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.1/leaflet.markercluster.min.js',
      ['leaflet-js'],
      '1.5.1',
      true
    );

    // Map-Styling
    wp_enqueue_style(
      'e2t-map-style',
      E2T_URL . 'frontend/assets/map.css',
      [],
      '1.0'
    );

    // Map-JavaScript
    wp_enqueue_script(
      'e2t-map-script',
      E2T_URL . 'frontend/assets/map.js',
      ['jquery', 'leaflet-js', 'leaflet-cluster-js'],
      '1.0',
      true
    );
  }

  // 💡 Renderer aufrufen basierend auf View
  if ($view === 'map') {
    if (!function_exists('e2t_render_members_map')) {
      require_once E2T_PATH . 'frontend/map-render.php';
    }
    $output = e2t_render_members_map();
  } elseif ($view === 'toggle') {
    if (!function_exists('e2t_render_members')) {
      return '<p>❌ Rendering-Funktion nicht gefunden.</p>';
    }
    if (!function_exists('e2t_render_members_map')) {
      require_once E2T_PATH . 'frontend/map-render.php';
    }

    $kacheln = e2t_render_members();
    $karte = e2t_render_members_map();

    ob_start();
    ?>
    <div class="e2t-view-toggle">
      <div class="e2t-toggle-buttons">
        <button class="e2t-toggle-btn e2t-toggle-kachel active" data-view="kachel">
          🎴 Kacheln
        </button>
        <button class="e2t-toggle-btn e2t-toggle-map" data-view="map">
          🗺️ Karte
        </button>
      </div>

      <div class="e2t-view-content">
        <div class="e2t-view e2t-view-kachel active">
          <?php echo $kacheln; ?>
        </div>
        <div class="e2t-view e2t-view-map" style="display: none;">
          <?php echo $karte; ?>
        </div>
      </div>
    </div>

    <script>
      document.addEventListener('DOMContentLoaded', function() {
        // Nur die View-Toggle-Buttons (nicht die "Mehr anzeigen" Buttons in den Kacheln)
        const toggleContainer = document.querySelector('.e2t-view-toggle');
        if (!toggleContainer) return;

        const toggleButtons = toggleContainer.querySelectorAll('.e2t-toggle-btn[data-view]');
        const views = toggleContainer.querySelectorAll('.e2t-view');

        toggleButtons.forEach(btn => {
          btn.addEventListener('click', function(e) {
            e.stopPropagation(); // Verhindere Bubble
            const view = this.getAttribute('data-view');

            // Update buttons
            toggleButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            // Update views
            views.forEach(v => {
              v.style.display = 'none';
              v.classList.remove('active');
            });

            const activeView = toggleContainer.querySelector('.e2t-view-' + view);
            if (activeView) {
              activeView.style.display = 'block';
              activeView.classList.add('active');

              // Trigger Leaflet map resize wenn Karte angezeigt wird
              if (view === 'map' && window.e2tMapInstance) {
                setTimeout(() => {
                  window.e2tMapInstance.invalidateSize();
                }, 100);
              }
            }
          });
        });
      });
    </script>
    <?php
    $output = ob_get_clean();
  } else {
    // Standard: Kacheln
    if (!function_exists('e2t_render_members')) {
      return '<p>❌ Rendering-Funktion nicht gefunden. Bitte überprüfe includes/renderer.php.</p>';
    }
    $output = e2t_render_members();
  }

  // 🔄 Wrapper für Konsistenz
  return '<div class="e2t-members-wrapper">' . $output . '</div>';
});
