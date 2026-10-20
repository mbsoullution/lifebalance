import { saveDraft } from './draft.js';
import { calculateGuide } from './guide.js';

((Drupal, drupalSettings, once) => {
  Drupal.behaviors.lifebalanceQuestionnaire = {
    attach(context) {
      const form = once('lifebalance-questionnaire', '.lifebalance-questionnaire', context)[0];
      const settings = drupalSettings.lifebalance;
      if (!form || !settings?.sector) {
        return;
      }
      const status = form.querySelector('[data-lifebalance-status]');
      let timer = 0;
      let request = 0;

      const refresh = () => {
        const text = guideText(settings, form);
        const guide = form.querySelector('[data-lifebalance-guide]');
        if (guide) {
          guide.textContent = text;
        }
        const parsed = figures(settings, form);
        const progress = form.querySelector('[data-lifebalance-progress]');
        if (progress) {
          progress.textContent = `${parsed.progress}/${parsed.total}`;
        }
        const difference = form.querySelector('[data-lifebalance-difference]');
        if (difference) {
          const score = currentScore(settings);
          const show = parsed.display !== null && score !== null && score !== parsed.display;
          difference.hidden = !show;
        }
        const take = form.querySelector('[data-lifebalance-take]');
        if (take instanceof HTMLInputElement) {
          if (parsed.display === null) {
            take.hidden = true;
          }
          else {
            take.hidden = false;
            take.value = `Взяти орієнтир (${parsed.display})`;
          }
        }
      };

      const schedule = () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => {
          const current = ++request;
          saveDraft(settings.draftUrl, collectScores(settings), collectAnswers(settings, form)).then((ok) => {
            if (current !== request || !status) {
              return;
            }
            status.textContent = ok ? 'Збережено' : 'Чернетку не збережено';
          });
        }, 1000);
      };

      form.addEventListener('change', (event) => {
        if (!(event.target instanceof HTMLInputElement)) {
          return;
        }
        if (event.target.type !== 'radio' && event.target.type !== 'checkbox') {
          return;
        }
        refresh();
        schedule();
      });
      form.addEventListener('submit', () => {
        window.clearTimeout(timer);
      });
    },
  };
})(Drupal, drupalSettings, once);

/**
 * @param {Settings} settings
 * @param {ParentNode} form
 * @returns {string}
 */
function guideText(settings, form) {
  const parsed = figures(settings, form);
  if (parsed.display !== null) {
    return `Орієнтир: ${parsed.display}`;
  }
  return '';
}

/**
 * @param {Settings} settings
 * @param {ParentNode} form
 */
function figures(settings, form) {
  const selected = selections(settings, form);
  const inputs = [];
  const counts = { yes: 0, partial: 0, no: 0, na: 0 };
  let progress = 0;
  let total = 0;
  for (const criterion of settings.criteria) {
    if (criterion.sector !== settings.sector) {
      continue;
    }
    total += 1;
    const answer = selected[criterion.id];
    if (!answer) {
      continue;
    }
    progress += 1;
    if (Object.hasOwn(counts, answer)) {
      counts[answer] += 1;
    }
    inputs.push({
      tier: criterion.tier,
      answer,
      in_formula: criterion.inFormula,
    });
  }
  const result = calculateGuide(modeFor(settings, selected), inputs);
  return {
    display: result.guide === null ? null : Math.floor(result.guide / 10),
    progress,
    total,
    ...counts,
  };
}

/**
 * @param {Settings} settings
 * @param {Record<string, string>} selected
 * @returns {'free'|'premium'}
 */
function modeFor(settings, selected) {
  if (!settings.entitled) {
    return 'free';
  }
  for (const criterion of settings.criteria) {
    const answer = criterion.sector === settings.sector
      ? selected[criterion.id]
      : storedAnswer(settings, criterion.id);
    if (criterion.tier === 'premium' && answer) {
      return 'premium';
    }
  }
  return 'free';
}

/**
 * @param {Settings} settings
 * @param {ParentNode} form
 * @returns {Record<string, string>}
 */
function selections(settings, form) {
  const selected = {};
  for (const criterion of settings.criteria) {
    if (criterion.sector !== settings.sector) {
      continue;
    }
    const choice = form.querySelector(`input[name="criteria[${criterion.id}][answer]"]:checked`);
    if (choice instanceof HTMLInputElement && choice.value) {
      selected[criterion.id] = choice.value;
    }
  }
  return selected;
}

/**
 * @param {Settings} settings
 * @param {ParentNode} form
 */
function collectAnswers(settings, form) {
  const selected = selections(settings, form);
  const answers = {};
  for (const criterion of settings.criteria) {
    const value = criterion.sector === settings.sector
      ? selected[criterion.id]
      : storedAnswer(settings, criterion.id);
    if (!value) {
      continue;
    }
    answers[String(criterion.id)] = { rev: criterion.rev, a: value };
  }
  return answers;
}

/**
 * @param {Settings} settings
 * @returns {Record<string, number|null>}
 */
function collectScores(settings) {
  const scores = { ...settings.scores };
  scores[settings.sector] = currentScore(settings);
  return scores;
}

/**
 * @param {Settings} settings
 * @returns {number|null}
 */
function currentScore(settings) {
  const value = settings.scores?.[settings.sector];
  return Number.isInteger(value) && value >= 0 && value <= 10 ? value : null;
}

/**
 * @param {Settings} settings
 * @param {number} id
 * @returns {string|undefined}
 */
function storedAnswer(settings, id) {
  const answers = settings.answers;
  if (!answers || Array.isArray(answers)) {
    return undefined;
  }
  const value = answers[String(id)];
  return typeof value === 'string' ? value : undefined;
}

/**
 * @typedef {object} CriterionSetting
 * @property {number} id
 * @property {string} sector
 * @property {string} tier
 * @property {boolean} inFormula
 * @property {number} rev
 */

/**
 * @typedef {object} Settings
 * @property {string} draftUrl
 * @property {string} sector
 * @property {string} label
 * @property {boolean} entitled
 * @property {CriterionSetting[]} criteria
 * @property {Record<string, string>|[]} answers
 * @property {Record<string, number|null>} scores
 */
