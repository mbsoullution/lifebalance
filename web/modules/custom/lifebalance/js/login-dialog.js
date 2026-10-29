/**
 * @file
 * Opens the password form from the door icon.
 */
((Drupal, once) => {
  Drupal.behaviors.lifebalanceLoginDialog = {
    attach(context) {
      const dialogs = once(
        'lifebalance-login-dialog',
        '[data-lifebalance-login-dialog]',
        context,
      );
      dialogs.forEach((dialog) => {
        if (!(dialog instanceof HTMLDialogElement)) {
          return;
        }
        dialog.addEventListener('click', (event) => {
          if (event.target === dialog) {
            dialog.close();
          }
        });
        dialog.querySelector('[data-lifebalance-login-close]')?.addEventListener('click', () => {
          dialog.close();
        });
        if (dialog.hasAttribute('data-lifebalance-login-show')) {
          dialog.showModal();
        }
      });
      once('lifebalance-login-open', '[data-lifebalance-login-open]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const dialog = document.querySelector('[data-lifebalance-login-dialog]');
          if (!(dialog instanceof HTMLDialogElement)) {
            return;
          }
          if (dialog.parentElement !== document.body) {
            document.body.appendChild(dialog);
          }
          if (!dialog.open) {
            dialog.showModal();
          }
          dialog.querySelector('input[name="name"]')?.focus();
        });
      });
    },
  };
})(Drupal, once);
