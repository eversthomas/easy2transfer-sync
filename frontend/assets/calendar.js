jQuery(document).ready(function ($) {
  // Weiterlesen
  $(".e2t-readmore").on("click", function () {
    const card = $(this).closest(".e2t-event-card");
    card.find(".e2t-event-full").toggleClass("hidden");
    card.find(".e2t-event-desc").toggleClass("short");
    $(this).text($(this).text() === "Weiterlesen" ? "Weniger anzeigen" : "Weiterlesen");
  });

  // Mehr anzeigen
  $(".e2t-load-more").on("click", function () {
    const wrap = $(this).closest(".e2t-calendar");
    const hidden = wrap.find(".e2t-event-card.hidden").slice(0, 10);
    hidden.removeClass("hidden");
    if (wrap.find(".e2t-event-card.hidden").length === 0) $(this).hide();
  });
});
