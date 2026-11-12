/*
 * 出張買取LPのページ専用スクリプト。
 * 予約URLは予約ページ公開後にここへ設定し、data-visit-booking のリンクに一括反映します。
 */
(() => {
  "use strict";

  const VISIT_LINKS = Object.freeze({
    booking: ""
  });

  document.querySelectorAll("[data-visit-booking]").forEach((link) => {
    if (VISIT_LINKS.booking) {
      link.href = VISIT_LINKS.booking;
      link.hidden = false;
    } else {
      link.hidden = true;
    }
  });

  const isLocalPreview = ["localhost", "127.0.0.1", "::1"].includes(window.location.hostname);
  const showPlaceholders = isLocalPreview && new URLSearchParams(window.location.search).get("previewPlaceholders") === "1";
  if (showPlaceholders) {
    document.documentElement.classList.add("visit-preview-placeholders");
  }
})();
