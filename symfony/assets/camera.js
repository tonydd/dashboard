const ICE_SERVERS = [
  { urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] },
];

const CONNECTION_RETRY_DELAY_MS = 3000;

const logClientFailure = (action, error) => {
  console.warn(`${action} failed`, error && error.name ? error.name : 'Error');
};

function cameraSocketUrl() {
  const configured = document.querySelector('meta[name="camera-ws-url"]')?.content;
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

async function openCameraSocket(role, onMessage) {
  const { token } = await cameraWebSocketToken(role);
  const socket = new WebSocket(`${cameraSocketUrl()}?token=${encodeURIComponent(token)}`);
  socket.onmessage = (event) => {
    try {
      onMessage(JSON.parse(event.data));
    } catch (error) {
      logClientFailure(`Camera signal parse (${role})`, error);
    }
  };
  return socket;
}

function createPeerConnection() {
  return new RTCPeerConnection({ iceServers: ICE_SERVERS });
}

// ---------------------------------------------------------------------------
// Publisher side: the tablet exposes its camera to connected viewers.
// ---------------------------------------------------------------------------

function initCameraPublisher() {
  const toggle = document.querySelector('[data-camera-toggle]');
  if (!toggle) return;

  const preview = document.querySelector('[data-camera-preview]');
  const label = toggle.querySelector('[data-camera-label]') ?? toggle;
  let mediaStream = null;
  let socket = null;
  let socketState = 'off';
  let publisherTokenExpired = false;
  const peers = new Map();
  let retryTimer = null;

  const send = (payload) => {
    if (socketState === 'open') {
      socket.send(JSON.stringify(payload));
    }
  };

  const setToggleState = (state) => {
    toggle.dataset.cameraState = state;
    label.textContent = state === 'on' ? 'ON' : 'OFF';
    if (preview) preview.hidden = state !== 'on';
  };

  const onMessage = async (message) => {
    switch (message.type) {
      case 'registered':
      case 'waiting':
        publisherTokenExpired = false;
        break;
      case 'viewer-joined':
        await addViewer(message.viewerId);
        break;
      case 'viewer-left': {
        const peer = peers.get(message.viewerId);
        if (peer) {
          peer.close();
          peers.delete(message.viewerId);
        }
        break;
      }
      case 'answer': {
        const peer = peers.get(message.viewerId);
        if (peer && peer.signalingState !== 'stable') {
          await peer.setRemoteDescription({ type: 'answer', sdp: message.sdp });
        }
        break;
      }
      case 'ice': {
        const peer = peers.get(message.viewerId);
        if (peer) {
          await peer.addIceCandidate(message.candidate).catch((error) => logClientFailure('Camera publisher ICE', error));
        }
        break;
      }
      case 'publisher_left':
      case 'error':
        break;
    }
  };

  const addViewer = async (viewerId) => {
    if (!mediaStream || peers.has(viewerId)) return;
    const peer = createPeerConnection();
    mediaStream.getTracks().forEach((track) => peer.addTrack(track, mediaStream));
    peer.onicecandidate = (event) => {
      if (event.candidate) send({ type: 'ice', to: viewerId, candidate: event.candidate.toJSON() });
    };
    peer.onconnectionstatechange = () => {
      if (['failed', 'closed'].includes(peer.connectionState)) {
        peer.close();
        peers.delete(viewerId);
      }
    };
    peers.set(viewerId, peer);
    try {
      const offer = await peer.createOffer();
      await peer.setLocalDescription(offer);
      send({ type: 'offer', to: viewerId, sdp: offer.sdp });
    } catch (error) {
      logClientFailure('Camera publisher offer', error);
      peer.close();
      peers.delete(viewerId);
    }
  };

  const closePeers = () => {
    peers.forEach((peer) => peer.close());
    peers.clear();
  };

  const stopCamera = () => {
    if (retryTimer) clearTimeout(retryTimer);
    retryTimer = null;
    closePeers();
    socketState = 'closing';
    socket?.close();
    socket = null;
    socketState = 'off';
    if (mediaStream) {
      mediaStream.getTracks().forEach((track) => track.stop());
      mediaStream = null;
    }
    if (preview) preview.srcObject = null;
    setToggleState('off');
  };

  const reconnect = () => {
    if (socketState === 'open' || socketState === 'connecting') return;
    if (!mediaStream) return;
    socketState = 'connecting';
    openCameraSocket('publisher', onMessage)
      .then((connection) => {
        socket = connection;
        socket.onopen = () => {
          socketState = 'open';
        };
        socket.onclose = () => {
          socketState = 'closed';
          if (mediaStream) {
            reconnectRetry();
          }
        };
        socket.onerror = () => {
          if (socketState !== 'open') socketState = 'closed';
        };
      })
      .catch((error) => {
        logClientFailure('Camera publisher connect', error);
        if (error instanceof Error && error.message.includes('Session expirée')) {
          publisherTokenExpired = true;
        }
        reconnectRetry();
      });
  };

  const reconnectRetry = () => {
    if (publisherTokenExpired || !mediaStream) return;
    if (retryTimer) clearTimeout(retryTimer);
    retryTimer = setTimeout(reconnect, CONNECTION_RETRY_DELAY_MS);
  };

  toggle.addEventListener('click', async () => {
    if (toggle.dataset.cameraState === 'on') {
      stopCamera();
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

    if (preview) preview.srcObject = mediaStream;
    setToggleState('on');
    reconnect();
  });

  // Releasing the camera when the tablet page is hidden avoids keeping the sensor warm unused.
  document.addEventListener('visibilitychange', () => {
    if (document.hidden && toggle.dataset.cameraState === 'on') {
      stopCamera();
    }
  });
}

// ---------------------------------------------------------------------------
// Viewer side: watch the tablet camera from another device.
// ---------------------------------------------------------------------------

function initCameraViewer() {
  const video = document.querySelector('[data-camera-viewer]');
  if (!video) return;

  const status = document.querySelector('[data-camera-status]');
  let socket = null;
  let socketState = 'off';
  let peer = null;
  let retryTimer = null;
  let stopped = false;

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

  const onMessage = async (message) => {
    switch (message.type) {
      case 'waiting':
        setStatus('En attente de la tablette…');
        closePeer();
        break;
      case 'offer':
        setStatus('Connexion à la caméra…');
        await acceptOffer(message.sdp);
        break;
      case 'ice':
        if (peer) {
          await peer.addIceCandidate(message.candidate).catch((error) => logClientFailure('Camera viewer ICE', error));
        }
        break;
      case 'publisher-left':
        setStatus('La tablette a arrêté le partage; nouvel essai…');
        closePeer();
        break;
      case 'error':
        setStatus(message.message || 'Erreur de signalisation.');
        break;
    }
  };

  const acceptOffer = async (offerSdp) => {
    closePeer();
    peer = createPeerConnection();
    peer.ontrack = (event) => {
      if (video.srcObject !== event.streams[0]) {
        video.srcObject = event.streams[0];
        video.play().catch((error) => logClientFailure('Camera viewer play', error));
        setStatus('');
      }
    };
    peer.onicecandidate = (event) => {
      if (event.candidate) {
        socketState === 'open' && socket.send(JSON.stringify({ type: 'ice', candidate: event.candidate.toJSON() }));
      }
    };
    await peer.setRemoteDescription({ type: 'offer', sdp: offerSdp });
    const answer = await peer.createAnswer();
    await peer.setLocalDescription(answer);
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
          setStatus('En attente de la tablette…');
        };
        socket.onclose = () => {
          socketState = 'closed';
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

  connect();
  window.addEventListener('beforeunload', disconnect);
}

initCameraPublisher();
initCameraViewer();
