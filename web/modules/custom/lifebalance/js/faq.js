((Drupal, once) => {
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
    },
  };
})(Drupal, once);
