((Drupal, once) => {
  Drupal.behaviors.lifebalanceLanguage = {
    attach(context) {
      once('lifebalance-lang', '.lifebalance-lang__select', context).forEach((select) => {
        const form = select.form;
        const button = form ? form.querySelector('.lifebalance-lang__go') : null;
        if (button) {
          button.hidden = true;
        }
        select.addEventListener('change', () => {
          const chosen = select.options[select.selectedIndex];
          const url = chosen ? chosen.getAttribute('data-url') : '';
          if (url) {
            window.location.assign(url);
            return;
          }
          if (form) {
            form.submit();
          }
        });
      });
    },
  };
})(Drupal, once);
