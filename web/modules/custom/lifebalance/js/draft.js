let tokenRequest;

/**
 * Posts scores and answers. The note and the guide are not sent.
 *
 * @param {string} url
 *   Draft endpoint.
 * @param {Record<string, number|null>} scores
 *   One value per sector.
 * @param {Record<string, {rev: number, a: string}>} answers
 *   Current answers.
 * @returns {Promise<boolean>}
 *   Whether the server accepted the draft.
 */
export async function saveDraft(url, scores, answers) {
  try {
    const token = await csrfToken();
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
      body: JSON.stringify({ scores, answers }),
    });
    return response.ok;
  }
  catch {
    tokenRequest = undefined;
    return false;
  }
}

/**
 * @returns {Promise<string>}
 */
function csrfToken() {
  tokenRequest ??= fetch('/session/token', {
    credentials: 'same-origin',
    headers: { Accept: 'text/plain' },
  }).then(async (response) => {
    if (!response.ok) {
      tokenRequest = undefined;
      throw new Error('token');
    }
    return (await response.text()).trim();
  });
  return tokenRequest;
}
