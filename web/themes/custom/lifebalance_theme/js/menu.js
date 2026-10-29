((Drupal, once) => {
  const placeMenu = (nav) => {
    const rows = [
      nav.querySelector('.block-system-branding-block'),
      nav.querySelector('.block-lifebalance-language-switcher'),
      nav.querySelector('.navbar-toggler'),
    ].filter((item) => item && getComputedStyle(item).display !== 'none');
    if (!rows.length) {
      return;
    }
    const navTop = nav.getBoundingClientRect().top;
    const bottom = Math.max(...rows.map((item) => item.getBoundingClientRect().bottom));
    nav.style.setProperty('--lb-nav-menu-top', `${Math.round(bottom - navTop)}px`);
  };

  const closeBranch = (branch) => {
    branch.classList.remove('is-open');
    const toggle = branch.querySelector('.lifebalance-menu__toggle');
    if (toggle) {
      toggle.setAttribute('aria-expanded', 'false');
    }
  };

  const fitPanel = (nav) => {
    const panel = nav.querySelector('#lifebalance-nav');
    if (!panel) {
      return;
    }
    const open = nav.querySelector('.lifebalance-menu__branch.is-open > .lifebalance-menu__sub');
    panel.style.minHeight = open ? `${open.offsetHeight}px` : '';
  };

  Drupal.behaviors.lifebalanceMenu = {
    attach(context) {
      once('lifebalance-menu-nav', '.navbar', context).forEach((nav) => {
        const update = () => placeMenu(nav);
        update();
        window.addEventListener('resize', update);
        const opener = nav.querySelector('.navbar-toggler');
        if (opener) {
          opener.addEventListener('click', update);
        }
      });

      once('lifebalance-menu-toggle', '.lifebalance-menu__toggle', context).forEach((button) => {
        button.addEventListener('click', () => {
          const branch = button.closest('.lifebalance-menu__branch');
          const nav = button.closest('.navbar');
          if (!branch || !nav) {
            return;
          }
          const willOpen = !branch.classList.contains('is-open');
          nav.querySelectorAll('.lifebalance-menu__branch.is-open').forEach(closeBranch);
          if (willOpen) {
            branch.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
          }
          fitPanel(nav);
        });
      });

      once('lifebalance-menu-back', '.lifebalance-menu__backbutton', context).forEach((button) => {
        button.addEventListener('click', () => {
          const branch = button.closest('.lifebalance-menu__branch');
          if (!branch) {
            return;
          }
          closeBranch(branch);
          const nav = button.closest('.navbar');
          if (nav) {
            fitPanel(nav);
          }
          const toggle = branch.querySelector('.lifebalance-menu__toggle');
          if (toggle) {
            toggle.focus();
          }
        });
      });

      once('lifebalance-menu-panel', '#lifebalance-nav', context).forEach((panel) => {
        panel.addEventListener('hidden.bs.collapse', () => {
          panel.querySelectorAll('.lifebalance-menu__branch.is-open').forEach(closeBranch);
          panel.style.minHeight = '';
        });
      });
    },
  };
})(Drupal, once);
