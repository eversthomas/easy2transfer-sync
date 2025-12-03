<?php if (!defined('ABSPATH')) exit; ?>

<div class="wrap e2t-fields">

  <!-- 🔹 LINKER SIDEBAR-BEREICH WIRD PER JS EINGEFÜGT -->

  <div class="e2t-mainarea">
      
      <!-- Kopfbereich gehört in den Mainarea -->
      <div class="e2t-header">
        <h2>EasyVerein – Feldverwaltung</h2>
        <p>Shortcode: <strong>[e2t_members]</strong></p>
        <p>Hier kannst du festlegen, welche Felder im Frontend angezeigt werden sollen, und ob sie oberhalb oder unterhalb des „Weiterlesen“-Buttons erscheinen.</p>
        <div id="e2t-message" class="notice-inline"></div>
      </div>

      <!-- Quick-Actions Toolbar -->
      <div class="e2t-quick-actions">
        <div class="e2t-stats">
          <span id="e2t-stat-total">0 Felder</span>
          <span id="e2t-stat-configured">0 konfiguriert</span>
          <span id="e2t-stat-in-use">0 in Verwendung</span>
        </div>
        <div class="e2t-action-buttons">
          <button type="button" class="button button-small" id="e2t-show-configured">✓ Nur konfigurierte</button>
          <button type="button" class="button button-small" id="e2t-show-unconfigured">○ Nur unkonfigurierte</button>
          <button type="button" class="button button-small" id="e2t-show-all">Alle anzeigen</button>
          <button type="button" class="button button-small" id="e2t-collapse-all">Alle einklappen</button>
          <button type="button" class="button button-small" id="e2t-expand-all">Alle ausklappen</button>
        </div>
      </div>

      <!-- FELDER-BEREICHE (werden von JS neu befüllt) -->
      <div class="e2t-sections">

        <div class="e2t-section" data-area="above">
          <h3>🟩 Über dem Button</h3>
          <div id="e2t-above" class="e2t-sortable"></div>
        </div>

        <div class="e2t-section" data-area="below">
          <h3>🟦 Unter dem Button</h3>
          <div id="e2t-below" class="e2t-sortable"></div>
        </div>

        <div class="e2t-section" data-area="unused">
          <h3>⬜ Nicht gebraucht</h3>
          <div id="e2t-unused" class="e2t-sortable"></div>
        </div>

      </div>

      <!-- Aktionen -->
      <div class="e2t-actions">
        <button id="e2t-save-fields" class="button button-primary">💾 Änderungen speichern</button>
        <button id="e2t-reload-fields" class="button">🔄 Neu laden</button>
      </div>

  </div> <!-- END .e2t-mainarea -->

</div> <!-- END .wrap -->
