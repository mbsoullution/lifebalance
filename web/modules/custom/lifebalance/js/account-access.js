/**
 * @file
 * Opens the register and premium dialogs under the header.
 */
((Drupal, once) => {
  /**
   * @param {string} dialogSelector
   * @param {string} openSelector
   */
  function bindDialog(dialogSelector, openSelector) {
    once('lifebalance-access-dialog', dialogSelector).forEach((dialog) => {
      if (!(dialog instanceof HTMLDialogElement)) {
        return;
      }
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
          dialog.close();
        }
      });
      dialog.querySelector('[data-lifebalance-access-close]')?.addEventListener('click', () => {
        dialog.close();
      });
    });
    once('lifebalance-access-open', openSelector).forEach((button) => {
      button.addEventListener('click', () => {
        const dialog = document.querySelector(dialogSelector);
        if (!(dialog instanceof HTMLDialogElement)) {
          return;
        }
        if (dialog.parentElement !== document.body) {
          document.body.appendChild(dialog);
        }
        if (!dialog.open) {
          dialog.showModal();
        }
      });
    });
  }

  /**
   * Uses the translated message when the avatar file is missing.
   */
  function bindAvatar() {
    const selector = '[data-lifebalance-avatar-required]';
    once('lifebalance-avatar-required', selector).forEach((input) => {
      if (!(input instanceof HTMLInputElement)) {
        return;
      }
      const message = input.getAttribute('data-lifebalance-avatar-required') || '';
      const sync = () => {
        const chosen = input.files && input.files.length > 0;
        input.setCustomValidity(chosen ? '' : message);
      };
      sync();
      input.addEventListener('change', sync);
    });
  }

  Drupal.behaviors.lifebalanceAccountAccess = {
    attach() {
      bindDialog('[data-lifebalance-register-dialog]', '[data-lifebalance-register-open]');
      bindDialog('[data-lifebalance-premium-dialog]', '[data-lifebalance-premium-open]');
      bindAvatar();
      const invalid = document.querySelector('[data-lifebalance-register-invalid]');
      const dialog = document.querySelector('[data-lifebalance-register-dialog]');
      if (invalid && dialog instanceof HTMLDialogElement && !dialog.open) {
        if (dialog.parentElement !== document.body) {
          document.body.appendChild(dialog);
        }
        dialog.showModal();
      }
    },
  };
})(Drupal, once);
