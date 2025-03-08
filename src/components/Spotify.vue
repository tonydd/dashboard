<script>
import TokenService from "@/services/TokenService.js";
import LogService from "@/services/LogService.js";
import StringService from "@/services/StringService.js";
import DateService from "@/services/DateService.js";
import ConfigService from "@/services/ConfigService.js";

const baseAPIUrl = ConfigService.getConfig('SPOTIFY_API_BASE_URL');
const baseAccountUrl = ConfigService.getConfig('SPOTIFY_ACCOUNT_BASE_URL');

const client_id = ConfigService.getConfig('SPOTIFY_CLIENT_ID');
const client_secret = ConfigService.getConfig('SPOTIFY_CLIENT_SECRET');
const redirect_uri = window.location.origin;

const DEFER_ON_ACTION_MS = 950;
const WATCHDOG_ACTIVE_MS = ConfigService.getIntervalConfig('SPOTIFY_ACTIVE_REFRESH_INTERVAL');
const WATCHDOG_IDLE_MS = ConfigService.getIntervalConfig('SPOTIFY_IDLE_REFRESH_INTERVAL');

export default {
  name: "Spotify",
  beforeMount() {

    this.$watch('token', (newValue, oldValue) => {

      if (newValue === oldValue) {
        // No token change
        return;
      }

      if (oldValue === null && newValue !== null) {
        // First time token is set
        LogService.logDev('First time token is set');
        this.deferRefreshToken(JSON.parse(localStorage.getItem('spotifyToken')));
        this.getPlayback();

      } else {
        // Token has changed
        LogService.logDev('Token has changed');
        this.deferRefreshToken(JSON.parse(localStorage.getItem('spotifyToken')));
        this.getPlayback();
      }
    });
  },
  data() {
    return {
      token: null,
      playbackActive: false,
      currentSong: { name: '', artists: [], album: { images: [{ url: '' }] } },
      playbackTimeout: null,
      refreshTokenTimeout: null,
      mayResumePlaybackInterval: null
    };
  },
  props: {
    displayType: {
      type: String,
      defaultValue: 'compact'
    }
  },
  mounted() {
    const prevToken = JSON.parse(localStorage.getItem('spotifyToken'));
    LogService.logDev('retrieved ', prevToken);

    if (prevToken !== null) {
      // We have previous token information in localStorage
      if ('error' in prevToken && prevToken.error === 'invalid_grant') {
        // Previous token is invalid, we need to get a new one
        LogService.logDev('Previous token is invalid, we need to get a new one');
        localStorage.removeItem('spotifyToken');
        return;
      }

      if (TokenService.isTokenValid(prevToken)) {
        // Previous token is still valid
        LogService.logDev('Using ', prevToken);
        this.token = prevToken.access_token;

      } else {
        // Previous token is expired, we need to refresh it

        LogService.logDev('Fetch token');
        this.fetchRefreshToken(prevToken.refresh_token);
      }
    } else {
      // No previous token, we need to get a new one
      const params = Object.fromEntries(new URLSearchParams(location.search));
      LogService.logDev('params', params);

      if (params.hasOwnProperty('code')) {
        // This means we come back from OAuth app authorization and need an initial token
        const code = params.code;
        this.fetchAccessToken(code);
      }
    }
  },
  computed: {
    displayArtists() {
      return this.currentSong.artists.map(artist => artist.name).join(', ');
    },
    displayName() {
      if (this.currentSong.name.length > 32) {
        return this.currentSong.name.substring(0, 32) + '...';
      }
      return this.currentSong.name;
    }
  },
  methods: {
    fetchAccessToken(code) {
      return fetch(baseAccountUrl + '/api/token', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'Authorization': 'Basic ' + btoa(client_id + ':' + client_secret)
        },
        body: new URLSearchParams({
          'grant_type': 'authorization_code',
          'code': code,
          'redirect_uri': redirect_uri
        })
      })
        .then((res) => res.json())
        .then(res => {
          if (res.hasOwnProperty('error')) {
            console.error(res.error);
            return;
          }

          res.now = (Date.now() / 1000);
          res.expires_at = res.expires_in + res.now - 300;
          localStorage.setItem('spotifyToken', JSON.stringify(res));
          this.token = res.access_token;
          return res;
        })
        .catch(e => console.error(e));
    },

    fetchRefreshToken(refreshToken) {
      this.refreshTokenTimeout = null;
      return fetch(baseAccountUrl + '/api/token', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'Authorization': 'Basic ' + btoa(client_id + ':' + client_secret)
        },
        body: new URLSearchParams({
          'grant_type': 'refresh_token',
          'refresh_token': refreshToken,
          'client_id': client_id
        })
      })
        .then((res) => res.json())
        .then(res => {
          if (res.hasOwnProperty('error')) {
            console.error(res.error);
            localStorage.removeItem('spotifyToken');
            return;
          }

          res.now = (Date.now() / 1000);
          res.expires_at = res.expires_in + res.now - 300;

          let currentLocalStorageToken = JSON.parse(localStorage.getItem('spotifyToken'));
          currentLocalStorageToken.access_token = res.access_token;
          currentLocalStorageToken.expires_at = res.expires_at;
          localStorage.setItem('spotifyToken', JSON.stringify(currentLocalStorageToken));

          this.token = res.access_token;
          return res;
        })
        .catch(e => console.error(e));
    },

    /**
     * Defer the refresh token process to just before the access token expires
     * @param tokenData
     */
    deferRefreshToken(tokenData) {
      const diff = TokenService.getExpirationDiffSeconds(tokenData);
      LogService.logDev('Token expires in ' + diff + ' seconds');
      this.refreshTokenTimeout = setTimeout(() => {
        LogService.logDev('refreshtoken handler ', tokenData);
        this.fetchRefreshToken(tokenData.refresh_token);
      }, DateService.getDeferInterval(diff, 'second'));
    },

    paramsUrlEncode(data) {
      let out = [];

      for (const key in data) {
        if (data.hasOwnProperty(key)) {
          out.push(key + '=' + data[key]);
        }
      }

      return out.join('&');
    },
    spotifyLogin() {
      let state = StringService.generateRandomString(16);
      let scope = 'user-read-private user-read-email user-read-playback-state user-modify-playback-state streaming';

      window.location.assign(baseAccountUrl + '/authorize?' + this.paramsUrlEncode({
        response_type: 'code',
        client_id: client_id,
        scope: scope,
        redirect_uri: redirect_uri,
        state: state
      }));
    },
    getPlayback() {
      fetch(baseAPIUrl + '/me/player', {
        headers: {
          'Authorization': 'Bearer ' + this.token
        }
      })
        .then(res => {
          if (res.status === 204) {
            return { is_playing: false };
          } else {
            return res.json()
          }
        })
        .then(res => {
          if (!res.is_playing && this.mayResumePlaybackInterval !== null && (+new Date() < this.mayResumePlaybackInterval)) {
            LogService.logDev('Still may resume playback !');
            this.getPlaybackDeferred(WATCHDOG_ACTIVE_MS);
            return;
          }

          this.playbackActive = res.is_playing;
          if (this.playbackActive) {
            this.currentSong = res.item;
            this.device = res.device;
            this.getPlaybackDeferred(WATCHDOG_ACTIVE_MS);

          } else {
            this.mayResumePlaybackInterval = null;
            this.getPlaybackDeferred(WATCHDOG_IDLE_MS);
          }
        })
        .catch(e => console.error(e));
    },
    getPlaybackDeferred(defer = DEFER_ON_ACTION_MS) {
      //LogService.logDev('Next playback update in  ', (defer / 1000), ' seconds');
      const self = this;
      if (self.playbackTimeout !== null) {
        window.clearTimeout(self.playbackTimeout);
        self.playbackTimeout = null;
      }

      self.playbackTimeout = setTimeout(function () {
        self.getPlayback();
      }, defer);
    },
    next() {
      fetch(baseAPIUrl + '/me/player/next', {
        method: 'POST',
        headers: {
          'Authorization': 'Bearer ' + this.token
        }
      })
        .then(res => this.getPlaybackDeferred())
        .catch(e => console.error(e));
    },
    prev() {
      fetch(baseAPIUrl + '/me/player/previous', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + this.token }
      })
        .then(res => this.getPlaybackDeferred())
        .catch(e => console.error(e));
    },
    pauseSong() {
      fetch(baseAPIUrl + '/me/player/pause', {
        method: 'PUT',
        headers: { 'Authorization': 'Bearer ' + this.token },
      })
        .then(res => {
          if (res.status === 403) {
            this.resumePlayback();
          }
          else {
            this.mayResumePlaybackInterval = +new Date() + DateService.getDeferInterval(5, 'minute');
            LogService.logDev('May resume playback until ', this.mayResumePlaybackInterval, DateService.formatTime(this.mayResumePlaybackInterval));
            this.getPlaybackDeferred();
          }

        })
        .catch(e => console.error(e));
    },
    resumePlayback() {
      fetch(baseAPIUrl + '/me/player/play', {
        method: 'PUT',
        headers: { 'Authorization': 'Bearer ' + this.token },
      })
        .then(res => {
          LogService.logDev(res);
          if (res.status === 403) {
            this.pauseSong();
          }
          else {
            this.mayResumePlaybackInterval = null;
            this.getPlaybackDeferred();
          }
        })
        .catch(e => console.error(e));
    },
  },
}
</script>

<template>

  <div v-if="token === null">
    <h1>Spotify</h1>
    <v-btn @click="spotifyLogin" v-if="token === null">Se connecter</v-btn>
  </div>

  <div v-else-if="this.playbackActive" class="row flex-center-horizontal flex-center-vertical pad-right-20">

    <div v-if="true || displayType === 'compact'">
      <label>{{ displayName }}</label>
      <div>
        <v-btn-group>
          <v-btn @click="prev" size="x-large">
            <v-icon color="white" size="x-large">mdi-skip-previous</v-icon>
          </v-btn>
          <v-btn size="x-large" @click="playbackActive ? pauseSong() : resumePlayback()">
            <v-icon color="white" size="x-large">mdi-play-pause</v-icon>
          </v-btn>
          <v-btn @click="next" size="x-large">
            <v-icon color="white" size="x-large">mdi-skip-next</v-icon>
          </v-btn>
        </v-btn-group>
      </div>
    </div>
    <div v-else>
      <div class="column" id="spotify-album-image-col">
        <img id="spotify-album-cover" :src="currentSong.album.images[0].url" alt="Album cover" />
      </div>

      <div class="column">


        <h2>{{ displayName }}</h2>
        <h3>{{ displayArtists }}</h3>


        <div>
          <v-btn-group>
            <v-btn @click="prev" size="x-large">
              <v-icon color="white" size="x-large">mdi-skip-previous</v-icon>
            </v-btn>
            <v-btn size="x-large" @click="playbackActive ? pauseSong() : resumePlayback()">
              <v-icon color="white" size="x-large">mdi-play-pause</v-icon>
            </v-btn>
            <v-btn @click="next" size="x-large">
              <v-icon color="white" size="x-large">mdi-skip-next</v-icon>
            </v-btn>
          </v-btn-group>
        </div>


      </div>
    </div>
  </div>
  <div v-else class="row flex-center-horizontal flex-center-vertical" @click="getPlaybackDeferred(250)">
    <img src="https://storage.googleapis.com/pr-newsroom-wp/1/2023/05/Spotify_Primary_Logo_RGB_White.png"
      alt="Spotify logo" style="max-width: 100px" />
  </div>
</template>

<style scoped>
#spotify-album-image-col {
  max-width: 30%;
}

#spotify-album-cover {
  max-height: 100px;
  max-width: 100px;
}
</style>
