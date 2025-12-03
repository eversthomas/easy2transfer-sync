jQuery(function ($) {
  $(".e2t-tabs button").on("click", function () {
    const tab = $(this).data("tab");
    $(".e2t-tabs button").removeClass("active");
    $(this).addClass("active");
    $(".e2t-admin .tab").removeClass("active");
    $("#tab-" + tab).addClass("active");
  });
});
