<template>
  <v-dialog v-model="dialog">
    <template v-slot:activator="{ props: activatorProps }">
      <div v-bind="activatorProps" @click="secondsTarget = 0">
        <v-icon v-if="icon !== ''">{{ icon }}</v-icon>
        <h3 class="display" :class="{ red: isRed }">{{ formattedTime }}</h3>
      </div>
    </template>

    <v-card prepend-icon="mdi-timer-settings-outline" title="Durée du minuteur">
      <v-card-text>
        <v-row>
          <v-alert density="compact" text="" title="Comment ça marche ?" type="info">
            <span
              >Chaque appui ajoute la durée correspondante à la durée totale du
              minuteur</span
            >
            <br />
            <span
              >Par exemple pour un minuteur de 1 minute 30, appuyer une fois sur '30
              secondes' puis une fois sur '1 minute' ou l'inverse :)</span
            >
            <br />
            <span>A la fin, appuyer sur "Lancer" !</span>
          </v-alert>
        </v-row>
        <v-row>
          <v-col cols="3">
            <v-btn size="x-large" @click="secondsTarget += 30">30 secondes</v-btn>
          </v-col>
          <v-col cols="3">
            <v-btn size="x-large" @click="secondsTarget += 60">1 minute</v-btn>
          </v-col>
          <v-col cols="3">
            <v-btn size="x-large" @click="secondsTarget += 60 * 2">2 minutes</v-btn>
          </v-col>
          <v-col cols="3">
            <v-btn size="x-large" @click="secondsTarget += 60 * 5">5 minutes</v-btn>
          </v-col>
        </v-row>
        <v-row>
          <v-col cols="3">
            <v-btn size="x-large" @click="secondsTarget += 60 * 10">10 minutes</v-btn>
          </v-col>
          <v-col cols="3">
            <v-btn size="x-large" @click="secondsTarget += 60 * 30">30 minutes</v-btn>
          </v-col>
          <v-col cols="3">
            <v-btn size="x-large" @click="secondsTarget += 60 * 60">1 heure</v-btn>
          </v-col>
          <v-col cols="3">
            <v-btn size="x-large" @click="secondsTarget += 60 * 60 * 2">2 heures</v-btn>
          </v-col>
        </v-row>
        <v-row>
          <v-col cols="12">
            <v-text-field type="number" v-model="secondsTarget">{{
              secondsToHourMinuteSecondsTwoDigits(secondsTarget)
            }}</v-text-field>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-actions style="justify-content: space-between">
        <v-btn text="Remettre à zéro" variant="plain" @click="secondsTarget = 0"></v-btn>

        <v-btn text="Fermer" variant="plain" @click="dialog = false"></v-btn>

        <v-btn
          text="Lancer"
          variant="tonal"
          @click="
            defineCountdown();
            dialog = false;
          "
        ></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<script setup>
import LogService from "@/services/LogService.js";
import DateService from "@/services/DateService.js";
import { computed, onBeforeMount, ref } from "vue";


const timer = ref(null),
  beepTimer = ref(null),
  beepCounter = ref(0),
  isRed = ref(false),
  endTs = ref(null),
  secondsRemaining = ref(0),
  dialog = ref(false),
  secondsTarget = ref(0),
  sound = ref(null);

const props = defineProps({
  name: String,
  icon: String
});

onBeforeMount(() => {
  const endTs = localStorage.getItem(getLocalStorageKey());
    if (endTs) {
      startInterval();
    }
});

const formattedTime = computed(() => DateService.secondsToHourMinuteSecondsTwoDigits(secondsRemaining.value));

function getLocalStorageKey() {
  return `timer-${props.name}`;
}
function defineCountdown() {
  if (timer.value!== null) {
    clearInterval(timer.value);
    timer.value= null;
  }
  clearBeepInterval();

  const seconds = secondsTarget.value;
  secondsTarget.value = 0;
  endTs.value = DateService.now() + seconds;
  localStorage.setItem(getLocalStorageKey(), endTs.value);
  secondsRemaining.value = seconds;
  startInterval();
}

function startInterval() {
  timer.value = setInterval(() => {
    let endTs = localStorage.getItem(getLocalStorageKey());
    if (endTs) {
      endTs = parseInt(endTs);
      secondsRemaining.value = Math.floor(endTs - DateService.now());
    } else {
      secondsRemaining.value = 0;
    }

    if (secondsRemaining.value <= 0) {
      clearInterval(timer.value);
      timer.value = null;
      localStorage.removeItem(getLocalStorageKey());

      startBeepInterval();
    }
  }, DateService.getDeferInterval(1, "second"));
}

function startBeepInterval() {
  clearBeepInterval();
  beepTimer.value = setInterval(() => {
    beepCounter.value++;
    if (beepCounter.value % 2 === 0) {
      isRed.value = !isRed.value;
    }

    beep();
    if ("vibrate" in navigator) {
      navigator.vibrate(500);
    } else {
      LogService.logDev("Vibration not supported");
    }

    if (beepCounter.value >= 15) {
      clearBeepInterval();
      beepTimer.value = null;
      isRed.value = false;
      beepCounter.value = 0;
    }
  }, 850);
}

function clearBeepInterval() {
  if (beepTimer.value !== null) {
    clearInterval(beepTimer.value);
    beepTimer.value = null;
  }
}

function beep() {
  if (sound.value === null) {
    sound.value = new Audio(
      "data:audio/wav;base64,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qJrx4KOQjahZxWbcZgztj2c49nKmkId44S71j0c8eV9yDK6uPRzx5X18eDvjvQ6yKo9ZSS6l//8elePK/Lf//IInrOF/FvDoADYAGBMGb7FtErm5MXMlmPAJQVgWta7Zx2go+8xJ0UiCb8LHHdftWyLJE0QIAIsI+UbXu67dZMjmgDGCGl1H+vpF4NSDckSIkk7Vd+sxEhBQMRU8j/12UIRhzSaUdQ+rQU5kGeFxm+hb1oh6pWWmv3uvmReDl0UnvtapVaIzo1jZbf/pD6ElLqSX+rUmOQNpJFa/r+sa4e/pBlAABoAAAAA3CUgShLdGIxsY7AUABPRrgCABdDuQ5GC7DqPQCgbbJUAoRSUj+NIEig0YfyWUho1VBBBA//uQZB4ABZx5zfMakeAAAAmwAAAAF5F3P0w9GtAAACfAAAAAwLhMDmAYWMgVEG1U0FIGCBgXBXAtfMH10000EEEEEECUBYln03TTTdNBDZopopYvrTTdNa325mImNg3TTPV9q3pmY0xoO6bv3r00y+IDGid/9aaaZTGMuj9mpu9Mpio1dXrr5HERTZSmqU36A3CumzN/9Robv/Xx4v9ijkSRSNLQhAWumap82WRSBUqXStV/YcS+XVLnSS+WLDroqArFkMEsAS+eWmrUzrO0oEmE40RlMZ5+ODIkAyKAGUwZ3mVKmcamcJnMW26MRPgUw6j+LkhyHGVGYjSUUKNpuJUQoOIAyDvEyG8S5yfK6dhZc0Tx1KI/gviKL6qvvFs1+bWtaz58uUNnryq6kt5RzOCkPWlVqVX2a/EEBUdU1KrXLf40GoiiFXK///qpoiDXrOgqDR38JB0bw7SoL+ZB9o1RCkQjQ2CBYZKd/+VJxZRRZlqSkKiws0WFxUyCwsKiMy7hUVFhIaCrNQsKkTIsLivwKKigsj8XYlwt/WKi2N4d//uQRCSAAjURNIHpMZBGYiaQPSYyAAABLAAAAAAAACWAAAAApUF/Mg+0aohSIRobBAsMlO//Kk4soosy1JSFRYWaLC4qZBYWFRGZdwqKiwkNBVmoWFSJkWFxX4FFRQWR+LsS4W/rFRb/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////VEFHAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAU291bmRib3kuZGUAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMjAwNGh0dHA6Ly93d3cuc291bmRib3kuZGUAAAAAAAAAACU="
    );
  }
  sound.value.play();
}

function secondsToHourMinuteSecondsTwoDigits(seconds) {
  return DateService.secondsToHourMinuteSecondsTwoDigits(seconds);
}
</script>

<style scoped>
.display.red {
  color: red;
}
</style>
