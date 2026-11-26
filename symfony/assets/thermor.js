// Water-heater admin screen: sends actions from the button bar to the
// /api/thermor/actions endpoints and reports the outcome inline.

const root = document.querySelector('[data-thermor-actions]');

if (root) {
  const result = root.querySelector('[data-thermor-result]');

  const showResult = (message, isError) => {
    if (!result) return;
    result.textContent = message;
    result.hidden = false;
    result.dataset.thermorResultState = isError ? 'error' : 'success';
  };

  const toTypedValue = (input) => {
    const type = input.dataset.jsonType ?? 'string';
    if (type === 'bool') return input.value === 'true';
    if (type === 'int') return parseInt(input.value, 10);
    if (type === 'float') return parseFloat(input.value);

    return input.value;
  };

  const payloadFor = (form) => {
    const slots = {};

    // Scalar fields first (named inputs other than slot* fields).
    const payload = {};
    for (const input of form.querySelectorAll('input[name], select[name]')) {
      if (input.name.startsWith('slot')) continue;
      payload[input.name] = toTypedValue(input);
    }

    // Programming slots: slot{N}_start / slot{N}_end time inputs.
    const slotNumbers = new Set();
    for (const input of form.querySelectorAll('input[name^="slot"]')) {
      slotNumbers.add(input.name.match(/^slot(\d+)_/)?.[1]);
    }
    for (const number of slotNumbers) {
      const start = form.querySelector(`input[name="slot${number}_start"]`);
      const end = form.querySelector(`input[name="slot${number}_end"]`);
      if (start?.value && end?.value) {
        slots[`slot${number}`] = { start: start.value, end: end.value };
      }
    }
    if (Object.keys(slots).length > 0) payload.slots = slots;

    return payload;
  };

  const sendAction = async (form) => {
    const sessionResponse = await fetch('/api/session', { credentials: 'same-origin', cache: 'no-store' });
    if (!sessionResponse.ok) throw new Error('Session introuvable.');
    const session = await sessionResponse.json();
    if (session.authenticated !== true) throw new Error('Session expirée, reconnectez-vous.');

    const response = await fetch(form.dataset.thermorEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrfToken },
      body: JSON.stringify(payloadFor(form)),
    });

    if (response.status === 429) {
      const retryAfter = response.headers.get('Retry-After');
      throw new Error(`Budget d'appels épuisé, réessayez${retryAfter ? ` dans ${retryAfter} s` : ' plus tard'}.`);
    }
    if (response.status === 422 || response.status === 400) {
      const body = await response.json().catch(() => ({}));
      throw new Error(body.error ?? 'Requête invalide.');
    }
    if (!response.ok) throw new Error(`Le chauffe-eau n'a pas accepté l'action (HTTP ${response.status}).`);

    return response.json();
  };

  for (const form of root.querySelectorAll('form[data-thermor-endpoint]')) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const button = form.querySelector('button[type="submit"]');
      const label = button?.textContent ?? '';
      if (button) {
        button.disabled = true;
        button.textContent = 'Envoi…';
      }

      try {
        await sendAction(form);
        showResult(`Action acceptée (${label.trim()}). Le chauffe-eau applique la consigne d'ici quelques instants.`, false);
      } catch (error) {
        showResult(error instanceof Error ? error.message : 'Erreur inconnue.', true);
      } finally {
        if (button) {
          button.disabled = false;
          button.textContent = label;
        }
      }
    });
  }
}
