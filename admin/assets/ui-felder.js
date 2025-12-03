// Easy2Transfer – Feldverwaltung (Sidebar + Sortable final)
// Komplett konsolidierte Datei (2025)
// -------------------------------------------------------------
// ✔ Drag & Drop stabil
// ✔ Kategorien (Sidebar)
// ✔ Filter
// ✔ Live-Suche
// ✔ Sortable reinitialisiert nach jedem Render
// ✔ cachedFields bleibt immer synchron zum DOM
// -------------------------------------------------------------

jQuery(document).ready(function ($) {
  const msg = $("#e2t-message");
  const saveBtn = $("#e2t-save-fields");
  const reloadBtn = $("#e2t-reload-fields");

  const areas = {
    above: $("#e2t-above"),
    below: $("#e2t-below"),
    unused: $("#e2t-unused"),
  };

  let cachedFields = [];
  let activeCategory = null;

  // ------------------------------------------------------------
  // 🧩 Sidebar HTML erzeugen
  // ------------------------------------------------------------
  $(".wrap.e2t-fields").prepend(`
    <div class="e2t-sidebar">
      <h3>Kategorien</h3>
      <ul id="e2t-categories"></ul>
      <hr>
      <h3>Filter</h3>
      <div class="e2t-filterbox">
        <input type="text" id="e2t-search" placeholder="🔍 Feld suchen...">

        <label><input type="checkbox" id="e2t-filter-labeled"> nur Felder mit Label</label>
        <label><input type="checkbox" id="e2t-filter-hasexample"> nur Felder mit Beispiel</label>
        <label><input type="checkbox" id="e2t-filter-custom"> nur CustomFields</label>
      </div>
    </div>
  `);

  // Sections einpacken
  $(".e2t-sections").wrap('<div class="e2t-mainarea"></div>');

  // ------------------------------------------------------------
  // 🔄 Felder von Server laden
  // ------------------------------------------------------------
  function loadFields() {
    msg.text("Lade Felder ...").removeClass("success error");
    saveBtn.prop("disabled", true);
    Object.values(areas).forEach(($el) => $el.empty());

    $.post(
      e2t_ajax.ajax_url,
      { action: "e2t_get_fields", nonce: e2t_ajax.nonce },
      function (response) {
        console.log("RAW GET FIELDS:", response);

        if (!response.success) {
          msg.text(response.data.message || "Fehler beim Laden.").addClass("error");
          return;
        }

        cachedFields = response.data.fields;

        renderSidebarCategories();
        renderFields();

        msg.text("Felder geladen.").addClass("success");
        saveBtn.prop("disabled", false);
      }
    );
  }

  // ------------------------------------------------------------
  // 🧭 Sidebar-Kategorien rendern
  // ------------------------------------------------------------
  function renderSidebarCategories() {
    const catUL = $("#e2t-categories");
    catUL.empty();

    const categories = {};
    cachedFields.forEach(f => {
      if (!categories[f.category]) {
        categories[f.category] = f.category_label;
      }
    });

    Object.entries(categories).forEach(([key, label]) => {
      const li = $(`<li data-cat="${key}">${label}</li>`);
      li.on("click", function () {
        activeCategory = key;
        $("#e2t-categories li").removeClass("active");
        li.addClass("active");
        renderFields();
      });
      catUL.append(li);
    });
  }

  // ------------------------------------------------------------
  // 🔍 Filter anwenden (Suchfeld + Checkboxen)
  // ------------------------------------------------------------
  function applyFilters(fields) {
    const search = $("#e2t-search").val().toLowerCase();
    const onlyLabel = $("#e2t-filter-labeled").is(":checked");
    const onlyExample = $("#e2t-filter-hasexample").is(":checked");
    const onlyCustom = $("#e2t-filter-custom").is(":checked");

    return fields.filter(f => {
      if (activeCategory && f.category !== activeCategory) return false;
      if (onlyLabel && (!f.label || f.label === f.id)) return false;
      if (onlyExample && (!f.example || f.example === '')) return false;
      if (onlyCustom && !f.category.startsWith("custom")) return false;
      if (search && !f.id.toLowerCase().includes(search) && !f.label.toLowerCase().includes(search)) return false;
      return true;
    });
  }

  // ------------------------------------------------------------
  // 🎨 Felder rendern (rechte Seite)
  // ------------------------------------------------------------
  function renderFields() {
    $(".e2t-sortable").empty();

    const toRender = applyFilters(cachedFields);

    toRender.forEach(f => {
      const example = f.example ? `<div class="e2t-example">${f.example}</div>` : "";

      const card = $(`
        <div class="e2t-field-row" data-id="${f.id}">
          <span class="drag-handle">☰</span>
          <span class="e2t-id">${f.id}</span>
          <input type="text" class="e2t-label" value="${f.label}" placeholder="Label">

          <div class="e2t-field-options">
            <label><input type="checkbox" class="e2t-show-label" ${f.show_label ? 'checked' : ''}> Label anzeigen</label>
            <label><input type="checkbox" class="e2t-filterable-field" ${f.filterable ? 'checked' : ''}> Filterbar</label>
            <label>Gruppe: <input type="text" class="e2t-inline-group" value="${f.inline_group || ''}"></label>
          </div>

          ${example}
        </div>
      `);

      const area = f.area || "unused";
      $("#e2t-" + area).append(card);
    });

    initSortable(); // ← Sortable NACH dem Rendern aktivieren
  }

  // ------------------------------------------------------------
  // 🧲 Sortable neu initialisieren (der WICHTIGE Fix!)
  // ------------------------------------------------------------
  function initSortable() {
    Object.values(areas).forEach(($el) => {
      new Sortable($el[0], {
        group: "fields",
        animation: 150,
        handle: ".drag-handle",
        ghostClass: "dragging",

        onEnd: function(evt) {
          const fieldId = $(evt.item).data("id");
          const newArea = $(evt.to).closest(".e2t-section").data("area");

          const field = cachedFields.find(f => f.id === fieldId);
          if (field) field.area = newArea;

          $(evt.to).children(".e2t-field-row").each(function(i) {
            const id = $(this).data("id");
            const f = cachedFields.find(x => x.id === id);
            if (f) f.order = i + 1;
          });

          console.log("UPDATE:", fieldId, "→", newArea);
        }
      });
    });
  }

  // ------------------------------------------------------------
  // 🔄 Manuelles neu laden
  // ------------------------------------------------------------
  reloadBtn.on("click", loadFields);

  // ------------------------------------------------------------
  // 💾 Speichern
  // ------------------------------------------------------------
  saveBtn.on("click", function () {
    const fields = [];

    Object.entries(areas).forEach(([area, $el]) => {
      $el.find(".e2t-field-row").each(function(index) {
        const row = $(this);

        const inlineGroupVal = row.find("input.e2t-inline-group").val()?.trim() || "";

        fields.push({
          id: row.data("id"),
          label: row.find(".e2t-label").val(),
          show: true,
          order: index + 1,
          area: area,
          show_label: row.find(".e2t-show-label").is(":checked"),
          filterable: row.find(".e2t-filterable-field").is(":checked"),
          inline_group: inlineGroupVal,
        });
      });
    });

    msg.text("Speichere ...").removeClass("success error");

    $.post(
      e2t_ajax.ajax_url,
      {
        action: "e2t_save_fields",
        nonce: e2t_ajax.nonce,
        fields: JSON.stringify(fields),
      },
      function (response) {
        if (response.success) {
          msg.html(`<div class="notice notice-success is-dismissible"><p>${	response.data.message}</p></div>`);
        } else {
          const err = response.data?.message || "Fehler beim Speichern.";
          msg.html(`<div class="notice notice-error is-dismissible"><p>${err}</p></div>`);
        }
      }
    );
  });

  // ------------------------------------------------------------
  // 🚀 Beim Start Felder laden
  // ------------------------------------------------------------
  loadFields();
});
