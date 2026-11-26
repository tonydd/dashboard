const ICE_SERVERS = [
  { urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] },
];

const CONNECTION_RETRY_DELAY_MS = 3000;

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
// Publisher side: the tablet registers on the signaling server as soon as the
// page opens and shares its camera with connected viewers while ON.
// ---------------------------------------------------------------------------

function initCameraPublisher() {
  const toggle = document.querySelector('[data-camera-toggle]');
  if (!toggle) return;

  const label = toggle.querySelector('[data-camera-label]') ?? toggle;
  let mediaStream = null;
  let socketState = 'off';
  let sessionExpired = false;
  let retryTimer = null;
  // viewerId -> RTCPeerConnection, or null while waiting for the camera to be ON.
  const viewers = new Map();

  const send = (payload) => {
    if (socketState === 'open') {
      socket.send(JSON.stringify(payload));
    }
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
    send({ type: 'stop' });
    setToggleState('off');
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
        (message.viewers || []).forEach((viewerId) => {
          if (!viewers.has(viewerId)) viewers.set(viewerId, null);
          if (mediaStream) createPeerAndOffer(viewerId);
        });
        break;
      case 'evicted':
        // Another tablet page took the room; stop the fight and wait for a reload.
        sessionExpired = true;
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
    openCameraSocket('publisher', onMessage)
      .then((connection) => {
        socket = connection;
        socket.onclose = () => {
          socketState = 'closed';
          // The server dropped the room state with it; the user re-enables ON if needed.
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
    viewers.forEach((_, viewerId) => createPeerAndOffer(viewerId));
  });

  connect();
  // Sharing starts on its own when the tablet page loads; the toggle only stops/resumes it.
  toggle.click();
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
        setStatus('La tablette est déconnectée; nouvel essai…');
        closePeer();
        break;
      case 'publisher-stopped':
        setStatus('La tablette a arrêté le partage; en attente…');
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
