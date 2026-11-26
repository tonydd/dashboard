const ICE_SERVERS = [
  { urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] },
];

const CONNECTION_RETRY_DELAY_MS = 3000;
const HEALTHCHECK_INTERVAL_MS = 5000;

const logClientFailure = (action, error) => {
  console.warn(`${action} failed`, error && error.name ? error.name : 'Error');
};

function cameraSocketUrl() {
  // {host} is substituted client-side so LAN devices reach the signaling server too.
  const configured = document.querySelector('meta[name="camera-ws-url"]')?.content.replace('{host}', window.location.hostname);
  if (configured) return configured;
  const scheme = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
  return `${scheme}//${window.location.host}/camera-ws`;
}

async function cameraWebSocketToken(role) {
  const sessionResponse = await fetch('/api/session', { credentials: 'same-origin', cache: 'no-store' });
  if (!sessionResponse.ok) throw new Error('Session introuvable.');
  const session = await sessionResponse.json();
  if (session.authenticated !== true) throw new Error('Session expirée.');

  const response = await fetch('/api/camera/token', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': session.csrfToken },
    body: JSON.stringify({ role }),
  });
  if (!response.ok) throw new Error(`Jeton de signalisation refusé (HTTP ${response.status}).`);

  return response.json();
}

async function openCameraSocket(role, onMessage, extraParams = {}) {
  const { token } = await cameraWebSocketToken(role);
  const params = new URLSearchParams({ token, ...extraParams });
  const socket = new WebSocket(`${cameraSocketUrl()}?${params}`);
  socket.onmessage = (event) => {
    try {
      const handled = onMessage(JSON.parse(event.data));
      if (handled instanceof Promise) {
        handled.catch((error) => logClientFailure(`Camera signal async (${role})`, error));
      }
    } catch (error) {
      logClientFailure(`Camera signal parse (${role})`, error);
    }
  };
  return socket;
}

function createPeerConnection() {
  return new RTCPeerConnection({ iceServers: ICE_SERVERS });
}

/**
 * Display name of this camera client, persisted so a page reload evicts its
 * own previous session instead of piling up duplicate entries. `/tablet?name=
 * Cuisine` renames the device.
 */
function cameraClientName() {
  const requested = new URLSearchParams(window.location.search).get('name');
  if (requested) {
    localStorage.setItem('camera-client-name', requested.trim());

    return requested.trim();
  }
  const stored = localStorage.getItem('camera-client-name');
  if (stored) return stored;
  const generated = `Tablette-${Math.random().toString(36).slice(2, 6)}`;
  localStorage.setItem('camera-client-name', generated);

  return generated;
}

// ---------------------------------------------------------------------------
// Publisher side: the tablet registers on the signaling server as soon as the
// page opens and shares its camera with viewers that ask to watch it.
// ---------------------------------------------------------------------------

function initCameraPublisher() {
  const toggle = document.querySelector('[data-camera-toggle]');
  if (!toggle) return;

  const label = toggle.querySelector('[data-camera-label]') ?? toggle;
  // Without a strong reference the browser may garbage-collect the socket and
  // silently drop the signaling connection seconds after registering.
  let socket = null;
  let mediaStream = null;
  let socketState = 'off';
  let sessionExpired = false;
  let retryTimer = null;
  // viewerId -> RTCPeerConnection, or null while waiting for the camera to be ON.
  const viewers = new Map();

  const send = (payload) => {
    if (socket && socketState === 'open') {
      socket.send(JSON.stringify(payload));
    }
  };

  const sendStatus = () => {
    send({ type: 'status', cameraOn: mediaStream !== null });
  };

  const setToggleState = (state) => {
    toggle.dataset.cameraState = state;
    label.textContent = state === 'on' ? 'ON' : 'OFF';
  };

  const closePeerFor = (viewerId) => {
    const peer = viewers.get(viewerId);
    if (peer) {
      peer.onicecandidate = null;
      peer.close();
      viewers.set(viewerId, null);
    }
  };

  const stopSharing = () => {
    if (!mediaStream) return;
    mediaStream.getTracks().forEach((track) => track.stop());
    mediaStream = null;
    viewers.forEach((_, viewerId) => closePeerFor(viewerId));
    setToggleState('off');
    sendStatus();
  };

  const createPeerAndOffer = async (viewerId) => {
    if (!mediaStream || viewers.get(viewerId)) return;
    const peer = createPeerConnection();
    viewers.set(viewerId, peer);
    mediaStream.getTracks().forEach((track) => peer.addTrack(track, mediaStream));
    peer.onicecandidate = (event) => {
      if (event.candidate) send({ type: 'ice', to: viewerId, candidate: event.candidate.toJSON() });
    };
    peer.onconnectionstatechange = () => {
      if (['failed', 'closed', 'disconnected'].includes(peer.connectionState)) {
        closePeerFor(viewerId);
      }
    };
    try {
      const offer = await peer.createOffer();
      await peer.setLocalDescription(offer);
      send({ type: 'offer', to: viewerId, sdp: offer.sdp });
    } catch (error) {
      logClientFailure('Camera publisher offer', error);
      closePeerFor(viewerId);
    }
  };

  const onMessage = (message) => {
    switch (message.type) {
      case 'registered':
        socketState = 'open';
        // Re-announce the camera state; the server hands back any viewer
        // still bound to us (signaling server restart or page reload).
        sendStatus();
        (message.viewers || []).forEach((viewerId) => {
          if (!viewers.has(viewerId)) viewers.set(viewerId, null);
          if (mediaStream) createPeerAndOffer(viewerId);
        });
        break;
      case 'evicted': {
        // Another page registered under the same name; stop sharing and let
        // the new page own the camera without fighting over reconnects.
        sessionExpired = true;
        if (mediaStream) {
          mediaStream.getTracks().forEach((track) => track.stop());
          mediaStream = null;
        }
        viewers.forEach((_, viewerId) => closePeerFor(viewerId));
        viewers.clear();
        setToggleState('off');
        break;
      }
      case 'viewer-joined':
        viewers.set(message.viewerId, null);
        if (mediaStream) createPeerAndOffer(message.viewerId);
        break;
      case 'viewer-left':
        closePeerFor(message.viewerId);
        viewers.delete(message.viewerId);
        break;
      case 'answer': {
        const peer = viewers.get(message.viewerId);
        if (peer && peer.signalingState !== 'stable') {
          peer.setRemoteDescription({ type: 'answer', sdp: message.sdp })
            .catch((error) => logClientFailure('Camera publisher answer', error));
        }
        break;
      }
      case 'ice': {
        const peer = viewers.get(message.viewerId);
        if (peer) {
          peer.addIceCandidate(message.candidate).catch((error) => logClientFailure('Camera publisher ICE', error));
        }
        break;
      }
    }
  };

  const connect = () => {
    if (sessionExpired || socketState === 'open' || socketState === 'connecting') return;
    socketState = 'connecting';
    openCameraSocket('publisher', onMessage, { name: cameraClientName() })
      .then((connection) => {
        socket = connection;
        socket.onclose = () => {
          socketState = 'closed';
          // The server dropped our registration with it; the user re-enables ON if needed.
          if (mediaStream) {
            mediaStream.getTracks().forEach((track) => track.stop());
            mediaStream = null;
            setToggleState('off');
          }
          viewers.clear();
          scheduleReconnect();
        };
      })
      .catch((error) => {
        socketState = 'closed';
        logClientFailure('Camera publisher connect', error);
        if (error instanceof Error && error.message.includes('Session expirée')) {
          window.location.replace('/tablet');
          sessionExpired = true;

          return;
        }
        scheduleReconnect();
      });
  };

  const scheduleReconnect = () => {
    if (sessionExpired || retryTimer) return;
    retryTimer = setTimeout(() => {
      retryTimer = null;
      connect();
    }, CONNECTION_RETRY_DELAY_MS);
  };

  toggle.addEventListener('click', async () => {
    if (toggle.dataset.cameraState === 'on') {
      stopSharing();

      return;
    }

    try {
      mediaStream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 720 } },
      });
    } catch (error) {
      logClientFailure('Camera activation', error);
      label.textContent = 'KO';
      setTimeout(() => setToggleState('off'), 2000);

      return;
    }

    setToggleState('on');
    connect();
    sendStatus();
    // Bound viewers are re-handed to us by the server on the status ON above
    // (viewer-joined); offering to them here too would race the renegotiation.
  });

  connect();
  // Sharing starts on its own when the tablet page loads; the toggle only stops/resumes it.
  toggle.click();
}

// ---------------------------------------------------------------------------
// Viewer side: watch one of the registered cameras from another device.
// ---------------------------------------------------------------------------

function initCameraViewer() {
  const video = document.querySelector('[data-camera-viewer]');
  const listEl = document.querySelector('[data-camera-list]');
  if (!video) return;

  const status = document.querySelector('[data-camera-status]');
  let socket = null;
  let socketState = 'off';
  let peer = null;
  let retryTimer = null;
  let stopped = false;
  let selectedId = null;
  let selectedName = '';
  // Camera id whose watch request the server acknowledged; a pending
  // (unacknowledged) watch is retried by the healthcheck, an acknowledged
  // binding must never be restarted (it would kill the pending negotiation).
  let boundId = null;
  let lastCameras = [];

  const setStatus = (text) => {
    if (status) status.textContent = text;
  };

  const closePeer = () => {
    if (peer) {
      peer.close();
      peer = null;
    }
    video.srcObject = null;
  };

  const watchCamera = (camera) => {
    if (socketState !== 'open') return;
    if (camera.id === selectedId && camera.id === boundId) return;
    if (camera.id !== selectedId) closePeer();
    selectedId = camera.id;
    selectedName = camera.name;
    socket.send(JSON.stringify({ type: 'watch', to: camera.id }));
    renderCameras(lastCameras);
    setStatus(`Connexion à ${camera.name}…`);
  };

  const renderCameras = (cameras) => {
    if (!listEl) return;
    listEl.textContent = '';
    cameras.forEach((camera) => {
      const item = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.classList.toggle('is-selected', camera.id === selectedId);
      button.disabled = !camera.cameraOn;
      button.title = camera.cameraOn
        ? `Voir le flux de ${camera.name}`
        : `${camera.name} : caméra inactive ou déconnectée`;
      button.textContent = camera.cameraOn ? `${camera.name} ●` : `${camera.name} ○`;
      button.addEventListener('click', () => watchCamera(camera));
      item.appendChild(button);
      listEl.appendChild(item);
    });
  };

  const onMessage = async (message) => {
    switch (message.type) {
      case 'cameras': {
        const cameras = message.list || [];
        lastCameras = cameras;
        renderCameras(cameras);

        // A camera vanished (server restart, disconnect): give up on it.
        if (selectedId !== null && !cameras.some((camera) => camera.id === selectedId)) {
          closePeer();
          setStatus(`${selectedName} est déconnectée; nouvel essai dès son retour…`);
          selectedId = null;
          selectedName = '';
          boundId = null;
        }

        const active = cameras.filter((camera) => camera.cameraOn);
        if (cameras.length === 0) {
          setStatus('Aucune caméra enregistrée.');
          break;
        }
        if (active.length === 0) {
          setStatus(selectedId !== null
            ? `${selectedName} a arrêté le partage; en attente…`
            : 'Aucune caméra active.');
          break;
        }

        // Re-attach automatically (after a signaling restart the selection
        // survives but the binding does not; the healthcheck re-establishes it).
        if (selectedId === null) {
          if (active.length === 1) {
            // Single usable camera: connect to it right away, no click needed.
            watchCamera(active[0]);
          } else {
            const noun = `caméra${active.length > 1 ? 's' : ''}`;
            setStatus(`${active.length} ${noun} disponible${active.length > 1 ? 's' : ''} — cliquez sur une caméra.`);
          }
          break;
        }

        const current = cameras.find((camera) => camera.id === selectedId);
        if (current && current.cameraOn) {
          watchCamera(current);
        }
        break;
      }
      case 'watching':
        boundId = message.to;
        break;
      case 'offer':
        setStatus(`Connexion à ${selectedName}…`);
        await acceptOffer(message.sdp);
        break;
      case 'ice':
        if (peer) {
          await peer.addIceCandidate(message.candidate).catch((error) => logClientFailure('Camera viewer ICE', error));
        }
        break;
      case 'publisher-left':
        // The healthcheck re-watches as soon as the camera registers again.
        setStatus(`${selectedName} est déconnectée; nouvel essai dès son retour…`);
        closePeer();
        break;
      case 'publisher-stopped':
        setStatus(`${selectedName} a arrêté le partage; en attente…`);
        closePeer();
        break;
      case 'error':
        setStatus(message.message || 'Erreur de signalisation.');
        break;
    }
  };

  const acceptOffer = async (offerSdp) => {
    const pc = createPeerConnection();
    closePeer();
    peer = pc;
    pc.ontrack = (event) => {
      if (video.srcObject !== event.streams[0]) {
        video.srcObject = event.streams[0];
        video.play().catch((error) => logClientFailure('Camera viewer play', error));
        setStatus('');
      }
    };
    pc.onconnectionstatechange = () => {
      // Dropping the dead peer lets the healthcheck re-watch the camera.
      if (pc && ['failed', 'disconnected', 'closed'].includes(pc.connectionState)) {
        closePeer();
      }
    };
    pc.onicecandidate = (event) => {
      if (event.candidate) {
        socketState === 'open' && socket.send(JSON.stringify({ type: 'ice', candidate: event.candidate.toJSON() }));
      }
    };
    await pc.setRemoteDescription({ type: 'offer', sdp: offerSdp });
    // A newer offer may have replaced this negotiation while we awaited.
    if (peer !== pc) {
      pc.close();

      return;
    }
    const answer = await pc.createAnswer();
    await pc.setLocalDescription(answer);
    if (peer !== pc) {
      pc.close();

      return;
    }
    socketState === 'open' && socket.send(JSON.stringify({ type: 'answer', sdp: answer.sdp }));
  };

  const disconnect = () => {
    stopped = true;
    if (retryTimer) clearTimeout(retryTimer);
    socket?.close();
    closePeer();
  };

  const connect = () => {
    if (stopped || socketState === 'open' || socketState === 'connecting') return;
    socketState = 'connecting';
    setStatus('Connexion au serveur…');
    openCameraSocket('viewer', onMessage)
      .then((connection) => {
        socket = connection;
        socket.onopen = () => {
          socketState = 'open';
          setStatus('Recherche des caméras…');
        };
        socket.onclose = () => {
          socketState = 'closed';
          // The signaling server dropped our bindings with it.
          boundId = null;
          if (!stopped) {
            closePeer();
            retryTimer = setTimeout(connect, CONNECTION_RETRY_DELAY_MS);
          }
        };
      })
      .catch((error) => {
        logClientFailure('Camera viewer connect', error);
        socketState = 'closed';
        if (error instanceof Error && error.message.includes('Session expirée')) {
          window.location.replace('/');
          stopped = true;

          return;
        }
        setStatus('Serveur de signalisation injoignable, nouvel essai…');
        retryTimer = setTimeout(connect, CONNECTION_RETRY_DELAY_MS);
      });
  };

  // Healthcheck: poll the registered cameras every 5 seconds. The server only
  // reports connections that are still open on its side with their camera ON,
  // so the list tracks what is actually usable.
  setInterval(() => {
    if (!stopped && socketState === 'open') {
      socket.send(JSON.stringify({ type: 'cameras' }));
    }
  }, HEALTHCHECK_INTERVAL_MS);

  connect();
  window.addEventListener('beforeunload', disconnect);
}

initCameraPublisher();
initCameraViewer();
