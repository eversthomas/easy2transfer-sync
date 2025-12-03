/**
 * Easy2Transfer Frontend JS (mit Live-PLZ-Filter)
 * ------------------------------------------------
 * - Feld "zip" wird als Texteingabe behandelt (statt Dropdown)
 * - Filter kombinierbar (UND-Logik)
 * - Live-Filterung beim Tippen
 */

window.addEventListener("load", function () {
  const $ = jQuery;
  console.log("✅ Frontend-Script gestartet");

  const $cards = $(".e2t-member-card");
  const $btnMore = $("#e2t-load-more");
  const batchSize = 25;

  // ------------------------------------------------------
  // 🔢 Initialanzeige (erste 25 Karten)
  // ------------------------------------------------------
  function showInitialCards() {
    console.log("📦 Zeige erste", batchSize, "Karten von", $cards.length);
    $cards.hide().slice(0, batchSize).show();
    $btnMore.attr("data-loaded", batchSize);
    if ($cards.length > batchSize) $btnMore.show();
    else $btnMore.hide();
  }

  showInitialCards();

  // ------------------------------------------------------
  // 🔄 "Mehr anzeigen"-Button
  // ------------------------------------------------------
  $btnMore.on("click", function () {
    const loaded = parseInt($btnMore.attr("data-loaded"), 10);
    const next = loaded + batchSize;
    console.log("📈 Mehr anzeigen:", loaded, "→", next);
    $cards.filter(":hidden").slice(0, batchSize).fadeIn(200);
    $btnMore.attr("data-loaded", next);
    if (next >= $cards.length) $btnMore.hide();
  });

  // ------------------------------------------------------
  // 🧩 Filter- und Suchlogik
  // ------------------------------------------------------
  // ------------------------------------------------------
// 🧩 Filter- und Suchlogik (NEU, an renderer.php angepasst)
// ------------------------------------------------------
function initFilters() {
  const $filterbar = $(".e2t-filterbar");
  if ($filterbar.length === 0) return;

  const $selects = $(".e2t-filterbar select");
  const $search  = $("#e2t-search");
  const $reset   = $("#e2t-reset");

  console.log(
    "🧭 Filter initialisiert:",
    $selects.length,
    "Dropdowns gefunden"
  );

  // ------------------------------------------------------
  // 🏗️ Dropdowns aufbauen, PLZ-Feld ggf. zu Textfeld machen
  // ------------------------------------------------------
  $selects.each(function () {
    const $select = $(this);
    const fieldId = String($select.data("field") || "");

    // 🔹 Sonderfall PLZ:
    // nicht mehr hart auf "zip" prüfen, sondern auf ID-Namen
    // (z.B. contact.zipCode, member.zip, irgendwas mit plz/zip)
    const fieldIdLower = fieldId.toLowerCase();
    const isZipField =
      fieldIdLower.includes("zip") || fieldIdLower.includes("plz");

    if (isZipField) {
      console.log("✏️ Ersetze Dropdown für PLZ-Feld", fieldId, "durch Eingabefeld");
      const $input = $("<input>")
        .attr({
          type: "text",
          placeholder: "PLZ eingeben …",
          class: "e2t-filter-zip",
          "data-field": fieldId, // WICHTIG: echte Feld-ID behalten!
        })
        .on("input", function () {
          applyFilters();
        });

      $select.replaceWith($input);
      return; // nächste Schleife
    }

    // 🔸 normale Dropdowns befüllen (mit data-value, falls vorhanden)
    const values = new Set();
    console.groupCollapsed(`⚙️ Baue Dropdown für Feld ${fieldId}`);

    $cards.each(function () {
      const $field = $(this).find(`.e2t-field[data-id="${fieldId}"]`);
      if ($field.length === 0) return;

      // Prefer data-value (pipe-getrennte Werte)
      const dataVal = $field.attr("data-value") || "";
      if (dataVal) {
        const parts = dataVal.split("|").map(v => v.trim()).filter(Boolean);
        parts.forEach(v => values.add(v));
      } else {
        // Fallback: Textinhalt ohne Label
        const val = $field
          .clone()
          .children("strong")
          .remove()
          .end()
          .text()
          .replace(/^\s*[:\-–]\s*/, "")
          .trim();
        if (val && val !== "null" && val !== "undefined" && val !== "[]") {
          values.add(val);
        }
      }
    });

    const sorted = Array.from(values).sort((a, b) =>
      a.localeCompare(b, "de", { sensitivity: "base" })
    );

    sorted.forEach((val) => {
      $select.append(`<option value="${val}">${val}</option>`);
    });

    console.groupEnd();
  });

  // ------------------------------------------------------
  // 🔍 Filterlogik anwenden (inkl. PLZ-Textfeld + Suche)
  // ------------------------------------------------------
  function applyFilters() {
    const searchVal = $search.val().toLowerCase().trim();

    // filters = { feldId: { type: 'select'|'zip', value: '...' }, ... }
    const filters = {};

    // Dropdown-Filter
    $(".e2t-filterbar select").each(function () {
      const fieldId = String($(this).data("field") || "");
      const val = $(this).val();
      if (val) {
        filters[fieldId] = { type: "select", value: String(val).toLowerCase() };
      }
    });

    // PLZ-Textfelder (könnte theoretisch auch mehrere geben)
    $(".e2t-filterbar .e2t-filter-zip").each(function () {
      const fieldId = String($(this).data("field") || "");
      const val = $(this).val().toLowerCase().trim();
      if (val) {
        filters[fieldId] = { type: "zip", value: val };
      }
    });

    console.group("🧮 applyFilters()");
    console.log("🔎 Aktive Filter:", filters, "| Suchwert:", searchVal);

    const visibleCards = [];

    $cards.each(function () {
      const $card = $(this);
      let visible = true;

      // 1) Freitext-Suche (nur im Top-Bereich)
      const cardText = $card.find(".e2t-fields-top").text().toLowerCase();
      if (searchVal && !cardText.includes(searchVal)) {
        visible = false;
      }

      // 2) Feldbasierte Filter
      if (visible) {
        for (const [fieldId, filter] of Object.entries(filters)) {
          const $fieldEl = $card.find(`.e2t-field[data-id="${fieldId}"]`);
          if ($fieldEl.length === 0) {
            visible = false;
            break;
          }

          // Prefer data-value, ansonsten Text
          const rawAttr = $fieldEl.attr("data-value") || "";
          const fieldText = $fieldEl
            .clone()
            .children("strong")
            .remove()
            .end()
            .text()
            .trim()
            .toLowerCase();

          if (filter.type === "zip") {
            // PLZ: startsWith, sowohl auf data-value als auch Text
            const candidates = rawAttr
              ? rawAttr.split("|").map(v => v.trim().toLowerCase())
              : [fieldText];

            const zipMatch = candidates.some(v => v.startsWith(filter.value));
            if (!zipMatch) {
              visible = false;
              break;
            }
          } else {
            // normale Select-Filter: exakte Option in data-value oder im Text vorkommend
            const candidates = rawAttr
              ? rawAttr.split("|").map(v => v.trim().toLowerCase())
              : [fieldText];

            const hasMatch = candidates.some(v => v.includes(filter.value));
            if (!hasMatch) {
              visible = false;
              break;
            }
          }
        }
      }

      if (visible) visibleCards.push($card);
    });

    console.log("📊 Sichtbare Karten:", visibleCards.length);
    console.groupEnd();

    // 3) Anzeige aktualisieren (inkl. "Mehr anzeigen")
    $cards.hide();
    $(visibleCards).each(function (i) {
      if (i < batchSize) $(this).fadeIn(150);
    });

    if (visibleCards.length > batchSize) {
      $btnMore.show().attr("data-loaded", batchSize);
      $btnMore.off("click").on("click", function () {
        const loaded = parseInt($btnMore.attr("data-loaded"), 10);
        const next = loaded + batchSize;
        $(visibleCards).slice(loaded, next).fadeIn(200);
        $btnMore.attr("data-loaded", next);
        if (next >= visibleCards.length) $btnMore.hide();
      });
    } else {
      $btnMore.hide();
    }
  }

  // ------------------------------------------------------
  // ⚙️ Events binden
  // ------------------------------------------------------
  $search.on("input", applyFilters);
  // Achtung: $selects enthält noch die ursprünglichen <select>s.
  // Für PLZ ist inzwischen ein <input> da, der schon sein eigenes "input"-Event hat.
  $selects.on("change", applyFilters);
  $reset.on("click", function () {
    $search.val("");
    $(".e2t-filterbar select").val("");
    $(".e2t-filter-zip").val("");
    console.log("🔄 Filter zurückgesetzt");
    showInitialCards();
  });

  console.log("✅ Filter-Events gebunden");
}

  // ------------------------------------------------------
  // ⏳ Beobachte DOM, bis Filterbar da ist
  // ------------------------------------------------------
  const observer = setInterval(() => {
    const count = $(".e2t-filterbar select, .e2t-filterbar .e2t-filter-zip").length;
    if (count > 0) {
      clearInterval(observer);
      console.log("🚀 Filterbar gefunden, initialisiere Filter …");
      initFilters();
    } else {
      console.log("⏳ Warte auf Filterbar …");
    }
  }, 500);

  // ------------------------------------------------------
  // ⬇️ Toggle ("Mehr anzeigen" / "Weniger anzeigen")
  // ------------------------------------------------------
  $(document).on("click", ".e2t-toggle-btn", function () {
    const expanded = $(this).attr("aria-expanded") === "true";
    $(this).attr("aria-expanded", !expanded);
    $(this)
      .next(".e2t-fields-middle")
      .slideToggle(200)
      .attr("hidden", expanded);
    $(this).text(expanded ? "Mehr anzeigen" : "Weniger anzeigen");
  });
});