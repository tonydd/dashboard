<script setup>
import {onBeforeUnmount, onMounted, ref} from "vue";
import API from "@/http/API.js";
import ConfigService from "@/services/ConfigService.js";

const configuredApiBaseUrl = ConfigService.getConfig('API_BASE_URL', window.location.origin).replace(/\/+$/, '');
const apiBaseUrl = configuredApiBaseUrl.endsWith('/api')
  ? configuredApiBaseUrl
  : `${configuredApiBaseUrl}/api`;
const authenticated = ref(false);
const loading = ref(true);
const submitting = ref(false);
const apiUnavailable = ref(false);
const password = ref('');
const errorMessage = ref('');

async function checkSession() {
  loading.value = true;
  apiUnavailable.value = false;
  errorMessage.value = '';

  try {
    const session = await API.get(`${apiBaseUrl}/session`, {}, 'cors');
    authenticated.value = session.authenticated === true;
  } catch (error) {
    if (error.status === 401) {
      authenticated.value = false;
    } else {
      apiUnavailable.value = true;
      errorMessage.value = "Impossible de joindre l'API.";
    }
  } finally {
    loading.value = false;
  }
}

async function login() {
  submitting.value = true;
  errorMessage.value = '';

  try {
    await API.post(`${apiBaseUrl}/login`, {username: 'tablet', password: password.value}, 'cors');
    const session = await API.get(`${apiBaseUrl}/session`, {}, 'cors');
    authenticated.value = session.authenticated === true;
    password.value = '';
    if (!authenticated.value) {
      errorMessage.value = "La connexion n'a pas pu être établie.";
    }
  } catch (error) {
    errorMessage.value = error.status === 401
      ? 'Mot de passe incorrect.'
      : "Impossible de se connecter à l'API.";
  } finally {
    submitting.value = false;
  }
}

function handleUnauthorized() {
  authenticated.value = false;
  password.value = '';
  errorMessage.value = 'La session a expiré. Saisissez à nouveau le mot de passe.';
}

onMounted(() => {
  window.addEventListener('dashboard:unauthorized', handleUnauthorized);
  checkSession();
});

onBeforeUnmount(() => {
  window.removeEventListener('dashboard:unauthorized', handleUnauthorized);
});
</script>

<template>
  <div v-if="loading" class="auth-state">
    <v-progress-circular indeterminate />
  </div>
  <div v-else-if="apiUnavailable" class="auth-state">
    <v-card class="auth-card" title="Connexion indisponible">
      <v-card-text>{{ errorMessage }}</v-card-text>
      <v-card-actions>
        <v-btn color="primary" @click="checkSession">Réessayer</v-btn>
      </v-card-actions>
    </v-card>
  </div>
  <div v-else-if="!authenticated" class="auth-state">
    <v-card class="auth-card" title="Dashboard">
      <form @submit.prevent="login">
        <v-card-text>
          <v-text-field
            v-model="password"
            label="Mot de passe"
            type="password"
            autocomplete="current-password"
            autofocus
            required
            :disabled="submitting"
            @update:model-value="errorMessage = ''"
          />
          <v-alert v-if="errorMessage" type="error" density="compact">
            {{ errorMessage }}
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-btn type="submit" color="primary" block :loading="submitting">
            Se connecter
          </v-btn>
        </v-card-actions>
      </form>
    </v-card>
  </div>
  <slot v-else />
</template>

<style scoped>
.auth-state {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 24px;
}

.auth-card {
  width: min(100%, 420px);
}
</style>
