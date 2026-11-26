const pad = (value) => String(value).padStart(2, '0');
const logClientFailure = (action, error) => {
  console.warn(`${action} failed`, error && error.name ? error.name : 'Error');
};

function getStoredValue(key) {
  try {
    return localStorage.getItem(key);
  } catch (error) {
    logClientFailure(`Local storage read (${key})`, error);
    return null;
  }
}

function setStoredValue(key, value) {
  try {
    localStorage.setItem(key, value);
    return true;
  } catch (error) {
    logClientFailure(`Local storage write (${key})`, error);
    return false;
  }
}

function removeStoredValue(key) {
  try {
    localStorage.removeItem(key);
  } catch (error) {
    logClientFailure(`Local storage removal (${key})`, error);
  }
}

function updateClock() {
  const now = new Date();
  const date = now.toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  const time = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
  document.querySelectorAll('[data-clock-date]').forEach((element) => element.textContent = date);
  document.querySelectorAll('[data-clock-time]').forEach((element) => element.textContent = time);
}

updateClock();
setInterval(updateClock, 1000);

// Keep the screen on while the dashboard is visible; the kiosk browser used
// to handle this and Chrome relies on the Screen Wake Lock API instead.
let wakeLock = null;
async function requestWakeLock() {
  if (!navigator.wakeLock) return;
  try {
    if (!wakeLock || wakeLock.released) {
      wakeLock = await navigator.wakeLock.request('screen');
    }
  } catch (error) {
    logClientFailure('Screen wake lock', error);
  }
}
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible') requestWakeLock();
});
requestWakeLock();

const tablet = document.querySelector('[data-auto-slideshow]');
if (tablet) {
  setTimeout(() => window.location.replace('/slideshow'), Number(tablet.dataset.autoSlideshow));
}

const slideshowImage = document.querySelector('.slideshow-image');
slideshowImage?.addEventListener('error', () => {
  if (slideshowImage.dataset.localFallback) {
    console.error('The slideshow image and its local fallback could not be loaded.');
    return;
  }
  slideshowImage.dataset.localFallback = 'true';
  console.warn('The requested slideshow image could not be loaded; trying the local background.');
  slideshowImage.src = '/api/background';
});

const loginForm = document.querySelector('#dashboard-login');
if (loginForm) {
  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const password = document.querySelector('#password').value;
    const error = document.querySelector('#login-error');
    error.textContent = '';
    try {
      const response = await fetch('/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ username: 'tablet', password }),
      });
      if (response.status === 401) throw new Error('Mot de passe incorrect.');
      if (!response.ok) throw new Error('Impossible de se connecter au serveur.');
      const sessionResponse = await fetch('/api/session', {
        credentials: 'include',
        cache: 'no-store',
      });
      if (!sessionResponse.ok) throw new Error('Impossible de vérifier la session.');
      const session = await sessionResponse.json();
      if (session.authenticated !== true) throw new Error("La session n'a pas été établie.");
      window.location.replace('/tablet');
    } catch (err) {
      logClientFailure('Dashboard login', err);
      error.textContent = err instanceof Error ? err.message : 'Connexion impossible.';
    }
  });
}

function timerSeconds(name) {
  const key = `timer-${name}`;
  const stored = getStoredValue(key);
  if (!stored) return 0;
  const endTime = Number(stored);
  if (!Number.isFinite(endTime)) {
    console.warn(`Invalid stored timer value (${key}); clearing it.`);
    removeStoredValue(key);
    return 0;
  }
  return Math.max(0, Math.floor((endTime - Date.now()) / 1000));
}
function formatSeconds(seconds) {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  return `${pad(hours)}:${pad(minutes)}:${pad(seconds % 60)}`;
}
function beepTimer() {
  try {
    const context = new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = context.createOscillator();
    oscillator.connect(context.destination);
    oscillator.frequency.value = 880;
    oscillator.start();
    oscillator.stop(context.currentTime + 0.35);
  } catch (error) {
    logClientFailure('Timer tone', error);
  }
  navigator.vibrate?.([500, 350, 500]);
}
const expiredTimers = new Set();
function refreshTimers() {
  document.querySelectorAll('[data-timer]').forEach((button) => {
    const name = button.dataset.timer;
    const seconds = timerSeconds(name);
    button.querySelector('[data-timer-value]').textContent = formatSeconds(seconds);
    const stored = getStoredValue(`timer-${name}`);
    if (stored && Number(stored) <= Date.now() && !expiredTimers.has(name)) {
      expiredTimers.add(name);
      removeStoredValue(`timer-${name}`);
      beepTimer();
    } else if (stored && Number(stored) > Date.now()) {
      expiredTimers.delete(name);
    }
  });
}
refreshTimers();
setInterval(refreshTimers, 1000);

const dialog = document.querySelector('#timer-dialog');
let selectedTimer = null;
document.querySelectorAll('[data-timer]').forEach((button) => {
  button.addEventListener('click', () => {
    selectedTimer = button.dataset.timer;
    document.querySelector('#timer-seconds').value = '0';
    dialog.showModal();
  });
});
document.querySelectorAll('[data-add-seconds]').forEach((button) => {
  button.addEventListener('click', () => {
    const input = document.querySelector('#timer-seconds');
    input.value = String(Number(input.value) + Number(button.dataset.addSeconds));
  });
});
document.querySelector('#timer-reset')?.addEventListener('click', () => {
  document.querySelector('#timer-seconds').value = '0';
});
document.querySelector('#timer-start')?.addEventListener('click', () => {
  if (!selectedTimer) return;
  const seconds = Number(document.querySelector('#timer-seconds').value);
  if (Number.isFinite(seconds) && seconds > 0) {
    setStoredValue(`timer-${selectedTimer}`, String(Date.now() + seconds * 1000));
  } else {
    removeStoredValue(`timer-${selectedTimer}`);
  }
  refreshTimers();
  dialog.close();
});

function setWidgetError(selector, message) {
  const widget = document.querySelector(selector);
  if (!widget) return;
  let status = widget.querySelector('.widget-error');
  if (!status) {
    status = document.createElement('span');
    status.className = 'widget-error';
    status.setAttribute('role', 'status');
    widget.appendChild(status);
  }
  status.textContent = message;
}

function clearWidgetError(selector) {
  const status = document.querySelector(`${selector} .widget-error`);
  if (status && status.parentNode) status.parentNode.removeChild(status);
}

function clearChildren(element) {
  while (element.firstChild) {
    element.removeChild(element.firstChild);
  }
}

const weatherDialog = document.querySelector('#weather-dialog');
const weatherForecast = document.querySelector('#weather-forecast');
const weatherWidget = document.querySelector('[data-widget="weather"]');
let latestWeather = null;

function renderWeatherForecast(data) {
  if (!weatherForecast) return;
  clearChildren(weatherForecast);

  if (!Array.isArray(data?.daily) || data.daily.length === 0) {
    const message = document.createElement('p');
    message.textContent = 'Aucune prévision disponible.';
    weatherForecast.appendChild(message);
    return;
  }

  let renderedDays = 0;
  data.daily.forEach((day) => {
    const date = new Date(Number(day.dt) * 1000);
    if (!Number.isFinite(date.getTime())) return;

    const row = document.createElement('article');
    row.className = 'weather-day';
    const heading = document.createElement('div');
    heading.className = 'weather-day-heading';
    const dateLabel = document.createElement('h3');
    dateLabel.textContent = date.toLocaleDateString('fr-FR', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
    });
    heading.appendChild(dateLabel);

    if (typeof day.summary === 'string' && day.summary) {
      const summary = document.createElement('p');
      summary.textContent = day.summary;
      heading.appendChild(summary);
    }

    const iconCode = day.weather?.[0]?.icon;
    if (typeof iconCode === 'string' && /^[0-9]{2}[dn]$/.test(iconCode)) {
      const icon = document.createElement('img');
      icon.src = `https://openweathermap.org/img/wn/${iconCode}@2x.png`;
      icon.alt = day.weather?.[0]?.description || '';
      icon.loading = 'lazy';
      row.appendChild(icon);
    }

    const temperatures = document.createElement('p');
    temperatures.className = 'weather-day-temperatures';
    const minimum = Number(day.temp?.min);
    const maximum = Number(day.temp?.max);
    temperatures.textContent = `Min. ${Number.isFinite(minimum) ? `${Math.round(minimum)} °C` : '—'} · Max. ${Number.isFinite(maximum) ? `${Math.round(maximum)} °C` : '—'}`;
    const conditions = document.createElement('p');
    const windSpeed = Number(day.wind_speed);
    const rain = Number(day.rain);
    const precipitationProbability = Number(day.pop);
    const precipitationLabel = Number.isFinite(rain)
      ? `${rain} mm`
      : `${Number.isFinite(precipitationProbability) ? Math.round(precipitationProbability * 100) : 0} %`;
    conditions.textContent = `Vent ${Number.isFinite(windSpeed) ? windSpeed : '—'} m/s · Pluie ${precipitationLabel}`;

    row.appendChild(heading);
    row.appendChild(temperatures);
    row.appendChild(conditions);
    weatherForecast.appendChild(row);
    renderedDays += 1;
  });

  if (renderedDays === 0) {
    const message = document.createElement('p');
    message.textContent = 'Aucune prévision disponible.';
    weatherForecast.appendChild(message);
  }
}

async function refreshEndpoint(endpoint, selector, render) {
  try {
    const response = await fetch(endpoint, { credentials: 'same-origin' });
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }
    render(data);
    clearWidgetError(selector);
  } catch (error) {
    logClientFailure(`Dashboard request ${endpoint}`, error);
    setWidgetError(selector, error.message || 'Impossible d’actualiser ces données.');
  }
}

if (tablet) {
  const refreshSolar = () => refreshEndpoint('/api/solar', '[data-widget="solar"]', (data) => {
    const target = document.querySelector('[data-widget="solar"] strong');
    if (!target || data.production === undefined) throw new Error('Production solaire indisponible.');
    target.textContent = `${data.production / 100} W`;
  });
  const refreshThermor = () => refreshEndpoint('/api/thermor', '[data-widget="thermor"]', (data) => {
    const target = document.querySelector('[data-widget="thermor"] strong');
    if (!target || data.available40Degrees === undefined) throw new Error('État du chauffe-eau indisponible.');
    target.textContent = `${Math.round(data.available40Degrees * 100 / 200)} %`;
  });
  const refreshWaterQuality = () => refreshEndpoint('/api/waterQuality', '[data-widget="water-quality"]', (data) => {
    const target = document.querySelector('[data-widget="water-quality"] strong');
    if (!target || data.score === undefined) throw new Error('Qualité de l’eau indisponible.');
    target.textContent = `${data.score}/10`;
  });
  const refreshFuel = () => refreshEndpoint('/api/fuel', '[data-widget="fuel"]', (data) => {
    const target = document.querySelector('[data-widget="fuel"]');
    if (!target || !data || typeof data !== 'object') throw new Error('Données carburant invalides.');
    target.querySelectorAll('.fuel-row').forEach((row) => row.parentNode.removeChild(row));
    Object.entries(data).forEach(([name, item]) => {
      const row = document.createElement('div');
      row.className = 'fuel-row';
      const label = document.createElement('span');
      const price = document.createElement('strong');
      label.textContent = name;
      price.textContent = `${item.price ?? '—'} €`;
      row.appendChild(label);
      row.appendChild(price);
      target.appendChild(row);
    });
  });
  const refreshWeather = () => refreshEndpoint('/api/weather', '[data-widget="weather"]', (data) => {
    const target = document.querySelector('[data-widget="weather"]');
    if (!target || !data.current) throw new Error('Réponse météo invalide.');
    latestWeather = data;
    if (weatherDialog?.open) renderWeatherForecast(data);
    const temperature = document.createElement('strong');
    const description = document.createElement('span');
    const humidity = document.createElement('span');
    temperature.textContent = `${Math.round(data.current.temp)} °C`;
    description.textContent = data.current.weather?.[0]?.description || '';
    humidity.textContent = Number.isFinite(Number(data.current.humidity)) ? `Humidité ${data.current.humidity} %` : '';
    clearChildren(target);
    target.appendChild(temperature);
    target.appendChild(description);
    target.appendChild(humidity);
  });
  const refreshNews = () => refreshEndpoint('/api/news', '[data-widget="news"]', (data) => {
    const target = document.querySelector('[data-widget="news"]');
    if (!target || !Array.isArray(data.items)) throw new Error('Réponse actualités invalide.');
    clearChildren(target);
    const heading = document.createElement('h2');
    heading.textContent = 'Actus SSF';
    target.appendChild(heading);
    if (data.items.length === 0) {
      const empty = document.createElement('span');
      empty.textContent = 'Aucune actualité disponible.';
      target.appendChild(empty);
    }
    data.items.forEach((item) => {
      const link = document.createElement('a');
      link.href = item.link;
      link.dataset.newsArticle = '';
      link.dataset.newsTitle = item.title;
      link.textContent = item.title;
      target.appendChild(link);
    });
  });

  refreshSolar();
  refreshThermor();
  refreshWaterQuality();
  refreshFuel();
  refreshWeather();
  refreshNews();
  setInterval(refreshSolar, 60000);
  setInterval(refreshThermor, 120000);
  setInterval(refreshWaterQuality, 720000);
  setInterval(refreshFuel, 21600000);
  setInterval(refreshWeather, 600000);
  setInterval(refreshNews, 900000);
}

weatherWidget?.addEventListener('click', () => {
  if (!weatherDialog) return;
  if (latestWeather) {
    renderWeatherForecast(latestWeather);
  } else if (weatherForecast) {
    weatherForecast.textContent = 'Les prévisions ne sont pas encore disponibles.';
  }
  weatherDialog.showModal();
});

weatherDialog?.addEventListener('click', (event) => {
  if (event.target === weatherDialog) weatherDialog.close();
});

const newsDialog = document.querySelector('#news-dialog');
const newsFrame = document.querySelector('#news-frame');
const newsDialogTitle = document.querySelector('#news-dialog-title');
const newsFallbackLink = document.querySelector('#news-fallback-link');

document.addEventListener('click', (event) => {
  const link = event.target.closest('a[data-news-article]');
  if (!link || !newsDialog || !newsFrame || !newsDialogTitle || !newsFallbackLink) return;

  let articleUrl;
  try {
    articleUrl = new URL(link.href, window.location.origin);
  } catch (error) {
    logClientFailure('News article URL', error);
    event.preventDefault();
    return;
  }
  if (!['http:', 'https:'].includes(articleUrl.protocol)) {
    event.preventDefault();
    console.warn('Refusing to open a news article with an unsupported URL scheme.');
    return;
  }

  event.preventDefault();
  newsDialogTitle.textContent = link.dataset.newsTitle || link.textContent;
  newsFrame.src = articleUrl.href;
  newsFallbackLink.href = articleUrl.href;
  newsDialog.showModal();
});

newsDialog?.addEventListener('close', () => {
  if (newsFrame) newsFrame.src = 'about:blank';
});

newsDialog?.addEventListener('click', (event) => {
  if (event.target === newsDialog) newsDialog.close();
});

const clock = document.querySelector('[data-drag-clock]');
if (clock) {
  const storageKey = 'slideshow-datetime-position';
  let position = { x: 50, y: 10 };
  try {
    const rawPosition = getStoredValue(storageKey);
    const stored = rawPosition ? JSON.parse(rawPosition) : null;
    if (stored && !Array.isArray(stored) && Number.isFinite(stored.x) && Number.isFinite(stored.y)) {
      position = stored;
    } else if (rawPosition) {
      console.warn('Invalid slideshow clock position; using the default.');
    }
  } catch (error) {
    logClientFailure('Slideshow clock position parsing', error);
  }

  function applyPosition() {
    position.x = Math.min(90, Math.max(0, position.x));
    position.y = Math.min(90, Math.max(0, position.y));
    clock.style.left = `${position.x}%`;
    clock.style.top = `${position.y}%`;
  }
  applyPosition();

  let dragging = false;
  let moved = false;
  let startX = 0;
  let startY = 0;
  let startPosition = null;

  function getCoordinates(event) {
    const touch = event.touches && event.touches.length
      ? event.touches[0]
      : (event.changedTouches && event.changedTouches.length ? event.changedTouches[0] : event);
    return { x: touch.clientX, y: touch.clientY };
  }

  function startDrag(event) {
    const point = getCoordinates(event);
    dragging = true;
    moved = false;
    startX = point.x;
    startY = point.y;
    startPosition = { ...position };
    if (event.pointerId !== undefined && clock.setPointerCapture) {
      clock.setPointerCapture(event.pointerId);
    }
  }

  function moveDrag(event) {
    if (!dragging) return;
    const point = getCoordinates(event);
    const deltaX = point.x - startX;
    const deltaY = point.y - startY;
    if (Math.abs(deltaX) > 2 || Math.abs(deltaY) > 2) moved = true;
    if (!moved) return;
    if (event.touches && event.preventDefault) event.preventDefault();
    position.x = startPosition.x + deltaX / window.innerWidth * 100;
    position.y = startPosition.y + deltaY / window.innerHeight * 100;
    applyPosition();
  }

  function endDrag() {
    if (!dragging) return;
    dragging = false;
    setStoredValue(storageKey, JSON.stringify(position));
  }

  if ('PointerEvent' in window) {
    clock.addEventListener('pointerdown', startDrag);
    clock.addEventListener('pointermove', moveDrag);
    clock.addEventListener('pointerup', endDrag);
    clock.addEventListener('pointercancel', endDrag);
  } else {
    clock.addEventListener('mousedown', startDrag);
    document.addEventListener('mousemove', moveDrag);
    document.addEventListener('mouseup', endDrag);
    clock.addEventListener('touchstart', startDrag, { passive: true });
    document.addEventListener('touchmove', moveDrag, { passive: false });
    document.addEventListener('touchend', endDrag);
    document.addEventListener('touchcancel', endDrag);
  }

  clock.addEventListener('click', (event) => {
    if (moved) event.preventDefault();
  });
}
