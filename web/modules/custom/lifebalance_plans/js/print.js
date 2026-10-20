/**
 * @file
 * Opens the browser print dialog. The server does not build a PDF.
 */

(function () {
  const button = document.querySelector('[data-lifebalance-print]');
  if (!button) {
    return;
  }
  button.addEventListener('click', function () {
    window.print();
  });
})();
