/**
 * @file
 * Polls the owner's order status. The request does not grant access.
 */

(function () {
  const root = document.querySelector('[data-lifebalance-billing-status]');
  const settings = window.drupalSettings && window.drupalSettings.lifebalanceBilling;
  if (!root || !settings || !settings.url) {
    return;
  }
  if (root.getAttribute('data-lifebalance-billing-status') !== 'pending') {
    return;
  }
  let left = 12;
  const tick = function () {
    fetch(settings.url, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    }).then(function (response) {
      if (!response.ok) {
        return null;
      }
      return response.json();
    }).then(function (data) {
      if (!data || typeof data.status !== 'string') {
        return;
      }
      if (data.status === 'paid') {
        root.textContent = settings.paid;
        return;
      }
      if (data.status === 'failed' || data.status === 'expired' || data.status === 'cancelled') {
        root.textContent = settings.stopped;
        return;
      }
      left -= 1;
      if (left <= 0) {
        root.textContent = settings.open;
        return;
      }
      window.setTimeout(tick, 5000);
    }).catch(function () {
      left -= 1;
      if (left > 0) {
        window.setTimeout(tick, 5000);
      }
    });
  };
  window.setTimeout(tick, 5000);
})();
