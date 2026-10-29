((Drupal, once) => {
  const openFromHash = () => {
    const id = window.location.hash.replace('#', '');
    if (id === '') {
      return;
    }
    const item = document.getElementById(id);
    if (item instanceof HTMLDetailsElement) {
      item.open = true;
    }
  };

  Drupal.behaviors.lifebalanceFaq = {
    attach(context) {
      once('lifebalance-faq', '.lifebalance-faq details', context).forEach((item) => {
        item.addEventListener('toggle', () => {
          if (!item.open || !item.parentElement) {
            return;
          }
          for (const other of item.parentElement.querySelectorAll('details[open]')) {
            if (other !== item) {
              other.open = false;
            }
          }
        });
      });
      if (context === document) {
        openFromHash();
        once('lifebalance-faq-hash', 'html', context).forEach(() => {
          window.addEventListener('hashchange', openFromHash);
        });
      }
    },
  };
})(Drupal, once);
