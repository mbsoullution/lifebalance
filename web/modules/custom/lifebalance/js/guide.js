/**
 * Guide formula v1. Mirrors GuideCalculator.
 *
 * Yes is 1, partial is 0.5, no is 0. "na" and in_formula false are ignored.
 * Free mode ignores the premium tier. The returned guide is the display
 * integer times 10. precise is the same average in tenths (85 means 8.5).
 * Both are null below the minimum.
 *
 * @param {string} mode
 *   "free" or "premium".
 * @param {Array<{tier: string, answer: string, in_formula: boolean}>} answers
 *   Answers for one sector.
 * @returns {{guide: number|null, answered: number, precise: number|null}}
 *   Stored guide, the substantive count, and the one-decimal score.
 */
export function calculateGuide(mode, answers) {
  if (mode !== 'free' && mode !== 'premium') {
    throw new Error('Guide mode must be free or premium.');
  }
  const minimum = mode === 'premium' ? 10 : 5;
  const points = { yes: 2, partial: 1, no: 0 };
  let halfPoints = 0;
  let answered = 0;
  for (const item of answers) {
    if (item.tier !== 'free' && item.tier !== 'premium') {
      throw new Error('Guide answer tier must be free or premium.');
    }
    if (!item.in_formula || (mode === 'free' && item.tier === 'premium')) {
      continue;
    }
    if (item.answer === 'na') {
      continue;
    }
    if (!Object.hasOwn(points, item.answer)) {
      throw new Error('Guide answer must be yes, partial, no, or na.');
    }
    halfPoints += points[item.answer];
    answered += 1;
  }
  if (answered < minimum) {
    return { guide: null, answered, precise: null };
  }
  const display = roundHalfUp(5 * halfPoints, answered);
  const precise = roundHalfUp(50 * halfPoints, answered);
  return { guide: display * 10, answered, precise };
}

/**
 * Positive half-up division, matching PHP round(numerator / denominator).
 *
 * @param {number} numerator
 * @param {number} denominator
 * @returns {number}
 */
function roundHalfUp(numerator, denominator) {
  return Math.floor((numerator + denominator / 2) / denominator);
}
