// ui-felder-sidebar.js — Sidebar + Drag&Drop + Favoriten + „Ausgeblendete“ + kompakte, klappbare Felder

jQuery(document).ready(function ($) {
  const msg = $("#e2t-message");
  const saveBtn = $("#e2t-save-fields");
  const reloadBtn = $("#e2t-reload-fields");

  const areas = {
    above: $("#e2t-above"),
    below: $("#e2t-below"),
    unused: $("#e2t-unused"),
  };

  const CATEGORY_LABELS = {
    member: "Mitglied",
    contact: "Kontakt",
    cf: "Mitglied Custom",
    cfraw: "Mitglied Custom (roh)",
    contactcf: "Kontakt Custom",
    contactcfraw: "Kontakt Custom (roh)",
    consent: "Einwilligungen",
    other: "Sonstige Felder",
  };

  let cachedFields = [];
  let activeCategory = null;
  let activeStatusFilter = 'all'; // 'all', 'configured', 'unconfigured'

  // ------------------------------------------------------------------
  // 📌 Sidebar einfügen
  // ------------------------------------------------------------------
  $(".wrap.e2t-fields").prepend(`
    <div class="e2t-sidebar">
      <h3>Kategorien</h3>
      <ul id="e2t-categories"></ul>
      <hr>
      <h3>Filter</h3>
      <div class="e2t-filterbox">
        <input type="text" id="e2t-search" placeholder="🔍 Feld suchen..." />

        <label><input type="checkbox" id="e2t-filter-labeled"> nur Felder mit Label</label>
        <label><input type="checkbox" id="e2t-filter-hasexample"> nur Felder mit Beispiel</label>
        <label><input type="checkbox" id="e2t-filter-custom"> nur CustomFields</label>
      </div>
    </div>
  `);

  // Hauptbereich um die Sections legen (rechts)
  $(".e2t-sections").wrap('<div class="e2t-mainarea"></div>');

  // ------------------------------------------------------------------
  // 🧠 Kategorie aus ID ableiten (Fallback)
  // ------------------------------------------------------------------
  function deriveCategoryFromId(id) {
    if (!id || typeof id !== "string") return "other";
    if (id.indexOf("member.") === 0) return "member";
    if (id.indexOf("contact.") === 0) return "contact";
    if (id.indexOf("cfraw.") === 0) return "cfraw";
    if (id.indexOf("cf.") === 0) return "cf";
    if (id.indexOf("contactcfraw.") === 0) return "contactcfraw";
    if (id.indexOf("contactcf.") === 0) return "contactcf";
    if (id.indexOf("consent.") === 0) return "consent";
    return "other";
  }

  // ------------------------------------------------------------------
  // 🔄 Felder vom Server laden
  // ------------------------------------------------------------------
  function loadFields() {
    msg.text("Lade Felder ...").removeClass("success error");
    saveBtn.prop("disabled", true);

    $.post(
      e2t_ajax.ajax_url,
      { action: "e2t_get_fields", nonce: e2t_ajax.nonce },
      function (response) {
        console.log("e2t_get_fields response:", response);

        if (!response || !response.success || !response.data || !response.data.fields) {
          msg
            .text(
              (response && response.data && response.data.message) ||
                "Fehler beim Laden der Felder."
            )
            .addClass("error");
          return;
        }

        cachedFields = response.data.fields;

        // Basisaufbereitung / Defaults
        cachedFields.forEach(function (f) {
          if (!f.category) {
            f.category = deriveCategoryFromId(f.id);
          }
          if (!f.category_label) {
            f.category_label = CATEGORY_LABELS[f.category] || f.category;
          }
          if (typeof f.favorite === "undefined") {
            f.favorite = false;
          }
          if (typeof f.ignored === "undefined") {
            f.ignored = false; // neue Eigenschaft für „Ausgeblendete“
          }
        });

        renderSidebarCategories();
        renderFields();
        updateStats();

        msg.text("Felder geladen").addClass("success");
        saveBtn.prop("disabled", false);
      }
    );
  }

  // ------------------------------------------------------------------
  // 🧭 Sidebar-Kategorien rendern
  // ------------------------------------------------------------------
  function renderSidebarCategories() {
    const catUL = $("#e2t-categories");
    catUL.empty();

    const categories = {};

    // Kategorien aus den Feldern sammeln
    cachedFields.forEach(function (f) {
      if (!f.category) return;
      if (!categories[f.category]) {
        categories[f.category] = f.category_label || f.category;
      }
    });

    // Zusätzliche „virtuelle“ Kategorien
    categories["favorite"] = "⭐ Favoriten";
    categories["ignored"]  = "🧹 Ausgeblendete";
    categories["none"]     = "Ohne Kategorie";

    Object.keys(categories).forEach(function (key) {
      const label = categories[key];
      const li = $('<li data-cat="' + key + '">' + label + "</li>");
      li.on("click", function () {
        activeCategory = key;
        $("#e2t-categories li").removeClass("active");
        li.addClass("active");
        renderFields();
      });
      catUL.append(li);
    });
  }

  // ------------------------------------------------------------------
  // 📊 Statistiken aktualisieren
  // ------------------------------------------------------------------
  function updateStats() {
    const total = cachedFields.length;
    const configured = cachedFields.filter(f => (f.label && f.label !== f.id) || f.area !== 'unused' || f.show).length;
    const inUse = cachedFields.filter(f => f.area === 'above' || f.area === 'below').length;
    
    $("#e2t-stat-total").text(total + " Felder");
    $("#e2t-stat-configured").text(configured + " konfiguriert");
    $("#e2t-stat-in-use").text(inUse + " in Verwendung");
  }

  // ------------------------------------------------------------------
  // 🔍 Filter anwenden (Suchfeld + Checkboxen + Kategorie + ignored + Status)
  // ------------------------------------------------------------------
  function applyFilters(fields) {
    const search = $("#e2t-search").val().toLowerCase();
    const onlyLabel = $("#e2t-filter-labeled").is(":checked");
    const onlyExample = $("#e2t-filter-hasexample").is(":checked");
    const onlyCustom = $("#e2t-filter-custom").is(":checked");

    return fields.filter(function (f) {
      // 0) Status-Filter (konfiguriert/unkonfiguriert)
      if (activeStatusFilter === 'configured') {
        const isConfigured = (f.label && f.label !== f.id) || f.area !== 'unused' || f.show;
        if (!isConfigured) return false;
      } else if (activeStatusFilter === 'unconfigured') {
        const isConfigured = (f.label && f.label !== f.id) || f.area !== 'unused' || f.show;
        if (isConfigured) return false;
      }
      // 1) Felder in „above“ oder „below“ → IMMER zeigen
      if (f.area === "above" || f.area === "below") {
        return true;
      }

      // 2) Ignored-Logik:
      //    - Standardansicht: ignorierte Felder ausgeblendet
      //    - Kategorie „Ausgeblendete“: nur ignorierte Felder
      if (f.ignored) {
        if (activeCategory !== "ignored") {
          return false; // in allen normalen Kategorien versteckt
        }
      } else {
        if (activeCategory === "ignored") {
          return false; // in „Ausgeblendete“ nur ignorierte anzeigen
        }
      }

      // 3) Kategorienlogik (nur, wenn wir nicht in „ignored“ sind – das ist oben schon erledigt)
      if (activeCategory === "favorite") {
        if (!f.favorite) return false;
      } else if (activeCategory === "none") {
        if (f.category && f.category !== "") return false;
      } else if (
        activeCategory &&
        activeCategory !== "favorite" &&
        activeCategory !== "none" &&
        activeCategory !== "ignored"
      ) {
        if (f.category !== activeCategory) return false;
      }

      // 4) Label-Filter
      if (onlyLabel && (!f.label || f.label === f.id)) {
        return false;
      }

      // 5) Beispiel-Filter
      if (onlyExample && (!f.example || f.example === "")) {
        return false;
      }

      // 6) Custom-Filter
      if (onlyCustom) {
        const cat = f.category || deriveCategoryFromId(f.id);
        if (
          cat !== "cf" &&
          cat !== "cfraw" &&
          cat !== "contactcf" &&
          cat !== "contactcfraw"
        ) {
          return false;
        }
      }

      // 7) Suchfeld
      if (search) {
        const idMatch =
          f.id && f.id.toLowerCase().indexOf(search) !== -1;
        const labelMatch =
          f.label && f.label.toLowerCase().indexOf(search) !== -1;
        if (!idMatch && !labelMatch) {
          return false;
        }
      }

      return true;
    });
  }

  // ------------------------------------------------------------------
  // 🎨 Felder rendern (kompakt & klappbar)
  // ------------------------------------------------------------------
  function renderFields() {
    $(".e2t-sortable").empty();

    const toRender = applyFilters(cachedFields);

    toRender.forEach(function (f) {
      const example = f.example
        ? '<div class="e2t-example">' + f.example + "</div>"
        : "";

      const favIcon = f.favorite ? "⭐" : "☆";
      const hideIcon = f.ignored ? "🙈" : "👁️"; // Ignoriert / sichtbar
      const labelValue = f.label || "";
      
      // Status-Badge bestimmen
      const isConfigured = (f.label && f.label !== f.id) || f.area !== 'unused' || f.show;
      const isInUse = f.area === 'above' || f.area === 'below';
      let statusBadge = '';
      if (isInUse) {
        statusBadge = '<span class="e2t-status-badge e2t-status-in-use" title="In Verwendung">✓</span>';
      } else if (isConfigured) {
        statusBadge = '<span class="e2t-status-badge e2t-status-configured" title="Konfiguriert">○</span>';
      } else {
        statusBadge = '<span class="e2t-status-badge e2t-status-unconfigured" title="Unkonfiguriert">—</span>';
      }

      const card = $(`
        <div class="e2t-field-row ${f.ignored ? "e2t-ignored" : ""} ${isConfigured ? "e2t-configured" : "e2t-unconfigured"}" data-id="${f.id}">
          <div class="e2t-field-header">
            <span class="drag-handle">☰</span>
            ${statusBadge}
            <button type="button" class="e2t-fav-btn" title="Favorit umschalten">${favIcon}</button>
            <button type="button" class="e2t-hide-btn" title="Ausblenden / wieder einblenden">${hideIcon}</button>
            <span class="e2t-id">${f.id}</span>
            <input type="text" class="e2t-label" value="${labelValue}" placeholder="Label">
            <button type="button" class="e2t-toggle-details" aria-expanded="false">Details ▾</button>
          </div>

          <div class="e2t-field-body" style="display:none">
            <div class="e2t-field-options">
              <label><input type="checkbox" class="e2t-show-label" ${f.show_label ? "checked" : ""}> Label anzeigen</label>
              <label><input type="checkbox" class="e2t-filterable-field" ${f.filterable ? "checked" : ""}> Filterbar</label>
              <label>Gruppe: <input type="text" class="e2t-inline-group" value="${f.inline_group || ""}"></label>
            </div>
            ${example}
          </div>
        </div>
      `);

      // ⭐ Favorit toggeln
      card.find(".e2t-fav-btn").on("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        const field = cachedFields.find((x) => x.id === f.id);
        if (!field) return;
        field.favorite = !field.favorite;
        renderSidebarCategories();
        renderFields();
      });

      // 🧹 Ignoriert / Ausgeblendet toggeln
      card.find(".e2t-hide-btn").on("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        const field = cachedFields.find((x) => x.id === f.id);
        if (!field) return;
        field.ignored = !field.ignored;
        renderSidebarCategories();
        renderFields();
      });

      // 🔽 Details auf-/zuklappen
      card.find(".e2t-toggle-details").on("click", function (e) {
        e.preventDefault();
        const btn = $(this);
        const body = card.find(".e2t-field-body");
        const isOpen = body.is(":visible");
        body.slideToggle(150);
        btn.attr("aria-expanded", !isOpen);
        btn.text(!isOpen ? "Details ▴" : "Details ▾");
      });

      const areaKey = f.area || "unused";
      const target = $("#e2t-" + areaKey);
      if (target.length) {
        target.append(card);
      } else {
        $("#e2t-unused").append(card);
      }
    });

    initSortable();
    bindLiveFieldEditors();
    updateStats();
  }

  function bindLiveFieldEditors() {
    $(".e2t-label")
      .off("input")
      .on("input", function () {
        const rowId = $(this).closest(".e2t-field-row").data("id");
        const field = cachedFields.find((f) => f.id === rowId);
        if (field) {
          field.label = $(this).val();
        }
      });

    $(".e2t-show-label, .e2t-filterable-field")
      .off("change")
      .on("change", function () {
        const row = $(this).closest(".e2t-field-row");
        const field = cachedFields.find((f) => f.id === row.data("id"));
        if (field) {
          field.show_label = row.find(".e2t-show-label").is(":checked");
          field.filterable = row.find(".e2t-filterable-field").is(":checked");
        }
      });

    $(".e2t-inline-group")
      .off("input")
      .on("input", function () {
        const rowId = $(this).closest(".e2t-field-row").data("id");
        const field = cachedFields.find((f) => f.id === rowId);
        if (field) {
          field.inline_group = $(this).val().trim();
        }
      });
  }

  // ------------------------------------------------------------------
  // 🧲 Sortable initialisieren
  // ------------------------------------------------------------------
  function initSortable() {
    Object.keys(areas).forEach(function (key) {
      const $el = areas[key];
      if (!$el.length) return;

      if ($el.data("sortable-init")) return;
      $el.data("sortable-init", true);

      new Sortable($el[0], {
        group: "fields",
        animation: 150,
        handle: ".drag-handle",
        ghostClass: "dragging",

        onEnd: function (evt) {
          const fieldId = $(evt.item).data("id");
          const newArea = $(evt.to).closest(".e2t-section").data("area");

          const field = cachedFields.find(function (f) {
            return f.id === fieldId;
          });
          if (field) {
            field.area = newArea;
          }

          $(evt.to)
            .children(".e2t-field-row")
            .each(function (index) {
              const id = $(this).data("id");
              const f = cachedFields.find(function (x) {
                return x.id === id;
              });
              if (f) {
                f.order = index + 1;
              }
            });

          console.log("Drag&Drop:", fieldId, "→", newArea);
        },
      });
    });
  }

  // ------------------------------------------------------------------
  // 🔄 Live-Filter
  // ------------------------------------------------------------------
  $(document).on(
    "input change",
    "#e2t-search, #e2t-filter-labeled, #e2t-filter-hasexample, #e2t-filter-custom",
    function () {
      renderFields();
    }
  );

// ------------------------------------------------------------------
// 💾 Speichern (alle Felder, nicht nur sichtbare)
// ------------------------------------------------------------------
saveBtn.on("click", function () {
  // 1️⃣ Ausgangspunkt: kompletter Stand aus cachedFields
  const fieldMap = {};

  cachedFields.forEach((f) => {
    // flache Kopie, damit wir nicht direkt cachedFields mutieren müssen
    fieldMap[f.id] = { ...f };
  });

  // optional: show für alle erstmal auf false setzen
  Object.values(fieldMap).forEach((f) => {
    f.show = false;
  });

  // 2️⃣ DOM durchgehen und die sichtbaren Felder in fieldMap aktualisieren
  Object.keys(areas).forEach(function (areaKey) {
    const $el = areas[areaKey];

    $el.find(".e2t-field-row").each(function (index) {
      const row = $(this);
      const id = row.data("id");
      const f = fieldMap[id];
      if (!f) return; // sollte nicht passieren, aber sicher ist sicher

      const inlineInput = row.find("input.e2t-inline-group");
      const inlineGroupVal = inlineInput.length
        ? inlineInput.val().trim()
        : "";

      f.label = row.find(".e2t-label").val();
      f.show = true;
      f.order = index + 1;
      f.area = areaKey;
      f.show_label = row.find(".e2t-show-label").is(":checked");
      f.filterable = row.find(".e2t-filterable-field").is(":checked");
      f.inline_group = inlineGroupVal;

      // ❗ favorite und ignored NICHT hier setzen,
      // die kommen direkt aus cachedFields und werden oben übernommen
    });
  });

  // 3️⃣ In Array umwandeln
  const fields = Object.values(fieldMap);

  console.log("🔄 Sende Felder an e2t_save_fields:", fields);

  msg.text("Speichere ...").removeClass("success error");

  $.ajax({
    url: e2t_ajax.ajax_url,
    method: "POST",
    dataType: "json",
    data: {
      action: "e2t_save_fields",
      nonce: e2t_ajax.nonce,
      fields: JSON.stringify(fields),
    },
  })
    .done(function (response) {
      console.log("✅ e2t_save_fields Antwort:", response);

      if (response && response.success) {
        msg.html(
          '<div class="notice notice-success is-dismissible"><p>' +
            response.data.message +
            "</p></div>"
        );
        // Statistiken aktualisieren
        updateStats();
        // Automatisch nach oben zur Meldung scrollen
        $("html, body").animate(
          {
            scrollTop: msg.offset().top - 100
          },
          500
        );
      } else {
        const err =
          (response &&
            response.data &&
            response.data.message) ||
          "Fehler beim Speichern (Server hat keinen Erfolg gemeldet).";
        msg.html(
          '<div class="notice notice-error is-dismissible"><p>' +
            err +
            "</p></div>"
        );
        // Auch bei Fehlern nach oben scrollen
        $("html, body").animate(
          {
            scrollTop: msg.offset().top - 100
          },
          500
        );
      }
    })
    .fail(function (jqXHR, textStatus, errorThrown) {
      console.error("❌ AJAX-Fehler bei e2t_save_fields:", {
        status: textStatus,
        error: errorThrown,
        responseText: jqXHR.responseText,
      });

      msg.html(
        '<div class="notice notice-error is-dismissible"><p>' +
          "AJAX-Fehler beim Speichern: " +
          textStatus +
          "</p></div>"
      );
    });
});


  // ------------------------------------------------------------------
  // 🔄 Reload-Button
  // ------------------------------------------------------------------
  reloadBtn.on("click", function () {
    loadFields();
  });

  // ------------------------------------------------------------------
  // ⚡ Quick-Actions
  // ------------------------------------------------------------------
  $("#e2t-show-configured").on("click", function() {
    activeStatusFilter = 'configured';
    $(this).addClass("button-primary").siblings().removeClass("button-primary");
    renderFields();
  });

  $("#e2t-show-unconfigured").on("click", function() {
    activeStatusFilter = 'unconfigured';
    $(this).addClass("button-primary").siblings().removeClass("button-primary");
    renderFields();
  });

  $("#e2t-show-all").on("click", function() {
    activeStatusFilter = 'all';
    $(this).addClass("button-primary").siblings().removeClass("button-primary");
    renderFields();
  });

  $("#e2t-collapse-all").on("click", function() {
    $(".e2t-field-body").slideUp(200);
    $(".e2t-toggle-details").attr("aria-expanded", "false").text("Details ▾");
  });

  $("#e2t-expand-all").on("click", function() {
    $(".e2t-field-body").slideDown(200);
    $(".e2t-toggle-details").attr("aria-expanded", "true").text("Details ▴");
  });

  // ------------------------------------------------------------------
  // 🚀 Start
  // ------------------------------------------------------------------
  loadFields();
  
  // Standard: "Alle anzeigen" aktivieren
  $("#e2t-show-all").addClass("button-primary");
});
