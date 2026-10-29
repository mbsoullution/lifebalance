/**
 * @file
 * Measures the Gin sidebar from the right edge.
 */

((Drupal, once) => {
  const place = () => {
    const placeholder = document.querySelector('.admin-toolbar__displace-placeholder');
    if (!placeholder) {
      return;
    }
    placeholder.removeAttribute('data-offset-left');
    placeholder.setAttribute('data-offset-right', '');
    Drupal.displace(true);
  };

  Drupal.behaviors.lifebalanceAdminToolbarSide = {
    attach(context) {
      once('lifebalance-admin-toolbar-side', '.admin-toolbar__displace-placeholder', context).forEach(() => {
        place();
        window.setTimeout(place, 0);
      });
    },
  };
})(Drupal, once);
