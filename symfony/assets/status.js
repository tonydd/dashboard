const STATUS_POLL_INTERVAL_MS = 30000;

const FEED_LABELS = {
  solar: 'Solaire',
  fuel: 'Carburant',
  thermor: 'Chauffe-eau',
  waterQuality: 'Qualité de l\u2019eau',
};

const FEED_CYCLES = {
  solar: 'cycle 1 min',
  fuel: 'cycle 1 h',
  thermor: 'cycle 1 min',
  waterQuality: 'cycle 12 h',
};

const card = (name) => document.querySelector(`[data-status-card="${name}"]`);

function setCard(name, state, value, detail) {
  const element = card(name);
  if (!element) return;
  element.dataset.statusState = state;
  element.querySelector('[data-status-value]').textContent = value;
  element.querySelector('[data-status-detail]').textContent = detail ?? '';
}

function formatAge(ageSeconds) {
  if (ageSeconds === null) return null;
  if (ageSeconds < 90) return `${ageSeconds} s`;
  const minutes = Math.round(ageSeconds / 60);
  if (minutes < 120) return `${minutes} min`;
  return `${Math.round(minutes / 60)} h`;
}

function formatBytes(bytes) {
  const units = ['o', 'Kio', 'Mio', 'Gio', 'Tio'];
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return `${value >= 10 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

function formatUptime(seconds) {
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);

  return days > 0 ? `${days} j ${hours} h` : `${hours} h ${minutes} min`;
}

function renderServer(server) {
  if (!server) return;

  const load = server.loadAvg?.[0];
  const cores = server.cores ?? 0;
  if (load !== undefined && cores > 0) {
    const ratio = load / cores;
    setCard(
      'cpu',
      ratio > 1 ? 'stale' : 'ok',
      `${load.toFixed(2)} / ${cores} cœurs`,
      `charge 5 min : ${(server.loadAvg[1] ?? 0).toFixed(2)}, 15 min : ${(server.loadAvg[2] ?? 0).toFixed(2)}`,
    );
  }

  if (server.memory) {
    const { totalBytes, availableBytes, usedPercent } = server.memory;
    setCard(
      'memory',
      usedPercent > 90 ? 'stale' : 'ok',
      `${usedPercent} % utilisé`,
      `${formatBytes(totalBytes - availableBytes)} sur ${formatBytes(totalBytes)}`,
    );
  }

  if (server.swap) {
    setCard(
      'swap',
      server.swap.usedPercent > 50 ? 'stale' : 'ok',
      `${server.swap.usedPercent} % utilisé`,
      `${formatBytes(server.swap.totalBytes - server.swap.freeBytes)} sur ${formatBytes(server.swap.totalBytes)}`,
    );
  } else {
    setCard('swap', 'ok', 'Aucun', 'swap désactivé');
  }

  if (server.disk) {
    setCard(
      'disk',
      server.disk.usedPercent > 90 ? 'stale' : 'ok',
      `${server.disk.usedPercent} % utilisé`,
      `${formatBytes(server.disk.freeBytes)} libres sur ${formatBytes(server.disk.totalBytes)}`,
    );
  }

  if (server.uptimeSeconds !== null && server.uptimeSeconds !== undefined) {
    setCard('uptime', 'ok', formatUptime(server.uptimeSeconds), server.hostname ?? '');
  }
}

function renderStatus(status) {
  const refreshed = document.querySelector('[data-status-refreshed]');
  if (refreshed) {
    refreshed.textContent = `Actualisé à ${new Date().toLocaleTimeString('fr-FR')}`;
  }

  renderServer(status.server);

  // Messenger: the heartbeat middleware writes on every consumed message
  // (the scheduler emits at least one message per minute).
  const messenger = status.messenger ?? {};
  setCard(
    'messenger',
    messenger.alive === true ? 'ok' : 'stale',
    messenger.alive === true ? 'Vivant' : 'Silencieux',
    messenger.ageSeconds !== null
      ? `dernier message il y a ${formatAge(messenger.ageSeconds)}`
      : 'aucun message enregistré',
  );

  setCard(
    'cameraWs',
    status.cameraWs?.reachable === true ? 'ok' : 'stale',
    status.cameraWs?.reachable === true ? 'Joignable' : 'Injoignable',
    status.cameraWs?.target ?? '',
  );

  setCard(
    'weather',
    status.config?.weather === true ? 'ok' : 'stale',
    status.config?.weather === true ? 'Configurée' : 'Non configurée',
    status.config?.weather === true ? 'clé API et coordonnées présentes' : 'renseigner OPENWEATHERMAP_*',
  );

  for (const [feed, feedStatus] of Object.entries(status.feeds ?? {})) {
    const age = formatAge(feedStatus.ageSeconds);
    const stale = feedStatus.ageSeconds === null || feedStatus.ageSeconds > feedStatus.staleAfterSeconds;
    setCard(
      `feed-${feed}`,
      stale ? 'stale' : 'ok',
      feedStatus.ageSeconds === null ? 'Jamais' : (stale ? `Périmé (${age})` : `Il y a ${age}`),
      FEED_CYCLES[feed] ?? '',
    );
    const label = card(`feed-${feed}`)?.querySelector('[data-feed-label]');
    if (label) label.textContent = FEED_LABELS[feed] ?? feed;
  }
}

async function pollStatus() {
  try {
    const response = await fetch('/api/status', { credentials: 'same-origin', cache: 'no-store' });
    if (!response.ok) {
      setCard('messenger', 'stale', `Erreur ${response.status}`, '');
      return;
    }
    renderStatus(await response.json());
  } catch (error) {
    console.warn('Status polling failed', error);
  }
}

if (document.querySelector('[data-status-cards]')) {
  pollStatus();
  setInterval(pollStatus, STATUS_POLL_INTERVAL_MS);
}
