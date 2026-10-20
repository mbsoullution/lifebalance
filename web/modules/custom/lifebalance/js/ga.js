/**
 * @file
 * Starts GA4 from a data attribute. No scores or personal paths are sent.
 */
(function () {
  var current = document.currentScript;
  var id = current && current.getAttribute('data-measurement-id');
  if (!id || !/^G-[A-Z0-9]+$/.test(id)) {
    return;
  }
  window.dataLayer = window.dataLayer || [];
  function gtag() {
    window.dataLayer.push(arguments);
  }
  window.gtag = gtag;
  gtag('js', new Date());
  gtag('config', id, {
    allow_google_signals: false,
    allow_ad_personalization_signals: false
  });
})();
