// Water-heater admin screen: sends actions from the button bar and the
// off-peak schedule editor to the /api/thermor endpoints and reports the
// outcome inline.

const fetchSession = async () => {
  const sessionResponse = await fetch('/api/session', { credentials: 'same-origin', cache: 'no-store' });
  if (!sessionResponse.ok) throw new Error('Session introuvable.');
  const session = await sessionResponse.json();
  if (session.authenticated !== true) throw new Error('Session expirée, reconnectez-vous.');

  return session.csrfToken;
};

const postJson = async (endpoint, payload) => {
  const csrfToken = await fetchSession();

  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
    body: JSON.stringify(payload),
  });

  if (response.status === 429) {
    const retryAfter = response.headers.get('Retry-After');
    throw new Error(`Budget d'appels épuisé, réessayez${retryAfter ? ` dans ${retryAfter} s` : ' plus tard'}.`);
  }
  if (response.status === 400 || response.status === 422) {
    const body = await response.json().catch(() => ({}));
    throw new Error(body.error ?? 'Requête invalide.');
  }
  if (!response.ok) throw new Error(`Le serveur a refusé la requête (HTTP ${response.status}).`);

  return response.json();
};

const showResult = (container, message, isError) => {
  const result = container.querySelector('[data-thermor-result]');
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

const actionsRoot = document.querySelector('[data-thermor-actions]');

if (actionsRoot) {
  const payloadFor = (form) => {
    const payload = {};
    const slots = {};

    // Scalar fields first (named inputs other than slot* fields).
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

  for (const form of actionsRoot.querySelectorAll('form[data-thermor-endpoint]')) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      const button = form.querySelector('button[type="submit"]');
      const label = button?.textContent ?? '';
      if (button) {
        button.disabled = true;
        button.textContent = 'Envoi…';
      }

      try {
        await postJson(form.dataset.thermorEndpoint, payloadFor(form));
        showResult(actionsRoot, `Action acceptée (${label.trim()}). Le chauffe-eau applique la consigne d'ici quelques instants.`, false);
      } catch (error) {
        showResult(actionsRoot, error instanceof Error ? error.message : 'Erreur inconnue.', true);
      } finally {
        if (button) {
          button.disabled = false;
          button.textContent = label;
        }
      }
    });
  }
}

const scheduleForm = document.querySelector('[data-thermor-schedule]');

if (scheduleForm) {
  const scheduleSection = scheduleForm.closest('section') ?? scheduleForm;

  const newPeriodRow = () => {
    const row = document.createElement('div');
    row.className = 'schedule-period';
    row.innerHTML =
      '<input type="time" data-period-start required> → ' +
      '<input type="time" data-period-end required> ' +
      '<button type="button" data-remove-period aria-label="Supprimer ce créneau">✕</button>';

    return row;
  };

  scheduleForm.addEventListener('click', (event) => {
    if (event.target instanceof HTMLElement && event.target.matches('[data-add-period]')) {
      event.target.closest('fieldset')?.querySelector('.schedule-periods')?.append(newPeriodRow());
    } else if (event.target instanceof HTMLElement && event.target.matches('[data-remove-period]')) {
      event.target.closest('.schedule-period')?.remove();
    }
  });

  scheduleForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const button = scheduleForm.querySelector('button[type="submit"]');
    if (button) button.disabled = true;

    try {
      const days = {};
      for (const fieldset of scheduleForm.querySelectorAll('fieldset[data-day]')) {
        const periods = [];
        for (const row of fieldset.querySelectorAll('.schedule-period')) {
          const start = row.querySelector('[data-period-start]')?.value ?? '';
          const end = row.querySelector('[data-period-end]')?.value ?? '';
          if (start !== '' && end !== '') periods.push({ start, end });
        }
        days[fieldset.dataset.day] = periods;
      }

      await postJson('/api/thermor/actions/off-peak-schedule', { days });
      showResult(scheduleSection, 'Heures creuses enregistrées ; la reconduction prendra effet à la minute suivante.', false);
    } catch (error) {
      showResult(scheduleSection, error instanceof Error ? error.message : 'Erreur inconnue.', true);
    } finally {
      if (button) button.disabled = false;
    }
  });
}
