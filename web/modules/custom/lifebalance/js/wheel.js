import { saveDraft } from './draft.js';
import { renderRadar } from './svg-radar.js';

((Drupal, drupalSettings, once) => {
  Drupal.behaviors.lifebalanceWheel = {
    attach(context) {
      const form = once('lifebalance-wheel', '.lifebalance-wheel', context)[0];
      const settings = drupalSettings.lifebalance;
      if (!form || !settings) {
        return;
      }
      const status = form.querySelector('[data-lifebalance-status]');
      let timer = 0;
      let request = 0;

      const redraw = () => {
        const previous = form.querySelector('svg.lifebalance-radar');
        if (!previous) {
          return;
        }
        const scores = collectScores(settings);
        const axes = settings.sectors.map((sector) => ({
          id: sector.id,
          label: sector.label,
          color: sector.color,
        }));
        const series = [{ values: scores, style: 'solid' }];
        const toggle = form.querySelector('[data-lifebalance-show-guide]');
        if (toggle instanceof HTMLInputElement && toggle.checked) {
          const guides = {};
          for (const sector of settings.sectors) {
            guides[sector.id] = sector.guide;
          }
          series.push({ values: guides, style: 'dashed' });
        }
        previous.replaceWith(renderRadar({ axes, series, size: 200 }));
        for (const sector of settings.sectors) {
          writeScore(form, sector.id, scores[sector.id]);
        }
      };

      const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
          const current = ++request;
          const scores = collectScores(settings);
          saveDraft(settings.draftUrl, scores, answerPayload(settings)).then((ok) => {
            if (current !== request || !status) {
              return;
            }
            status.textContent = ok ? 'Збережено' : 'Чернетку не збережено';
          });
        }, 1000);
      };

      if (settings.sectors.some((sector) => Number.isInteger(sector.guide))) {
        const label = document.createElement('label');
        label.className = 'lifebalance-guide-toggle';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.lifebalanceShowGuide = '1';
        label.append(input, document.createTextNode(' Показати орієнтир'));
        const radar = form.querySelector('svg.lifebalance-radar');
        radar?.before(label);
        input.addEventListener('change', redraw);
      }

      form.addEventListener('input', (event) => {
        if (!(event.target instanceof HTMLInputElement)) {
          return;
        }
        if (event.target.dataset.lifebalanceShowGuide) {
          return;
        }
        if (event.target.type !== 'range' && event.target.type !== 'checkbox') {
          return;
        }
        redraw();
        schedule();
      });
      form.addEventListener('change', (event) => {
        if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'checkbox') {
          return;
        }
        if (event.target.dataset.lifebalanceShowGuide) {
          return;
        }
        redraw();
        schedule();
      });
      form.addEventListener('submit', () => {
        window.clearTimeout(timer);
      });
      form.addEventListener('pointerover', (event) => {
        const sector = event.target.closest?.('.lifebalance-radar__sector');
        raiseSector(sector);
        setGrown(sector, true);
      });
      form.addEventListener('pointerout', (event) => {
        const sector = event.target.closest?.('.lifebalance-radar__sector');
        const next = event.relatedTarget?.closest?.('.lifebalance-radar__sector');
        if (sector && next !== sector) {
          setGrown(sector, false);
        }
      });
      form.addEventListener('focusin', (event) => {
        const sector = event.target.closest?.('.lifebalance-radar__sector');
        raiseSector(sector);
        setGrown(sector, true);
      });
      form.addEventListener('focusout', (event) => {
        const sector = event.target.closest?.('.lifebalance-radar__sector');
        setGrown(sector, false);
      });
      let pressed = null;
      const openSector = (sector) => {
        const href = sector?.getAttribute('href') ?? '';
        if (!href.startsWith('#')) {
          return;
        }
        const target = document.getElementById(href.slice(1));
        if (!target) {
          return;
        }
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const top = target.getBoundingClientRect().top + window.scrollY - 72;
        window.scrollTo({ top: Math.max(0, top), behavior: reduce ? 'auto' : 'smooth' });
        form.querySelectorAll('.lifebalance-sector.is-current').forEach((item) => {
          item.classList.remove('is-current');
        });
        target.classList.add('is-current');
        if (window.location.hash !== href) {
          window.history.pushState(null, '', href);
        }
      };
      document.addEventListener('pointerdown', (event) => {
        const sector = event.target.closest?.('.lifebalance-radar__sector');
        pressed = sector && form.contains(event.target) ? sector : null;
      }, true);
      document.addEventListener('pointerup', (event) => {
        const sector = pressed;
        pressed = null;
        if (!sector || event.target.closest?.('.lifebalance-radar__sector') !== sector) {
          return;
        }
        openSector(sector);
      }, true);
      document.addEventListener('click', (event) => {
        if (!form.contains(event.target)) {
          return;
        }
        const sector = event.target.closest?.('.lifebalance-radar__sector');
        if (!sector) {
          return;
        }
        event.preventDefault();
        openSector(sector);
      }, true);
      redraw();
    },
  };
})(Drupal, drupalSettings, once);

/**
 * Puts the hovered sector above its neighbours.
 *
 * @param {Element|null|undefined} sector
 */
function raiseSector(sector) {
  const svg = sector?.ownerSVGElement;
  const labels = svg?.querySelector('.lifebalance-radar__labels');
  if (sector && svg && labels) {
    svg.insertBefore(sector, labels);
  }
}

/**
 * Grows one slice from the wheel centre. CSS transforms do not apply here.
 *
 * @param {Element|null|undefined} sector
 * @param {boolean} on
 */
function setGrown(sector, on) {
  const grow = sector?.querySelector('.lifebalance-radar__grow');
  const svg = sector?.ownerSVGElement;
  if (!grow || !svg) {
    return;
  }
  if (!on) {
    grow.removeAttribute('transform');
    return;
  }
  const center = svg.viewBox.baseVal.width / 2;
  grow.setAttribute('transform', `translate(${center} ${center}) scale(1.12) translate(${-center} ${-center})`);
}

/**
 * @param {{sectors: Array<{id: string}>}} settings
 * @returns {Record<string, number|null>}
 */
function collectScores(settings) {
  const scores = {};
  for (const sector of settings.sectors) {
    const value = sector.score;
    scores[sector.id] = Number.isInteger(value) && value >= 0 && value <= 10 ? value : null;
  }
  return scores;
}

/**
 * @param {{answers?: Record<string, {rev: number, a: string}>|[]}} settings
 * @returns {Record<string, {rev: number, a: string}>}
 */
function answerPayload(settings) {
  const source = settings.answers;
  if (!source || Array.isArray(source)) {
    return {};
  }
  const answers = {};
  for (const [id, answer] of Object.entries(source)) {
    answers[id] = { rev: answer.rev, a: answer.a };
  }
  return answers;
}

/**
 * @param {ParentNode} form
 * @param {string} id
 * @param {number|null} score
 */
function writeScore(form, id, score) {
  const text = score === null ? '—' : String(score);
  const output = form.querySelector(`output[for="lifebalance-score-${id}"]`);
  if (output) {
    output.textContent = text;
  }
  const cell = form.querySelector(`[data-lifebalance-value="${id}"]`);
  if (cell) {
    cell.textContent = text;
  }
  const range = document.getElementById(`lifebalance-score-${id}`);
  if (range) {
    range.setAttribute('aria-valuetext', score === null ? 'не оцінено' : `${score} з 10`);
  }
}
