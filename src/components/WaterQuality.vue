<script setup>
import {computed, onBeforeMount, onMounted, ref} from "vue";
import ConfigService from "@/services/ConfigService.js";

let waterQuality = ref([]);

const props = defineProps({
  stationId: String,
  name: String,
});

onBeforeMount(() => {
  fetchWaterQuality();
});

onMounted(() => {
  setInterval(() => {
    fetchWaterQuality();
  }, ConfigService.getIntervalConfig("WATER_QUALITY_REFRESH_INTERVAL"));
});

async function fetchWaterQuality() {
  let response = await fetch(ConfigService.getConfig("WATER_QUALITY_API_URL"), { method: "GET", mode: "cors" });
  response = await response.json();
  waterQuality.value = response;
}

const color = computed(() => {
  if (!waterQuality.value) {
    return "white";
  }

  let color = '';
  switch (true) {
    case waterQuality.value.score < 8:
      color = "red";
      break;
    case waterQuality.value.score < 9:
      color = "orange";
      break;
    default:
      color = "green";
  }

  return color;
});

</script>

<template>
  <div class="row flex-center-vertical">
    <v-icon :color="color">mdi-water-check</v-icon>
      <h3>{{ waterQuality.score }}</h3>
      <label style="color: lightgray; font-size: 10px; margin-left: 4px">{{ waterQuality.status }}</label>
  </div>
</template>

<style scoped>
.fuel-update {
  font-size: smaller;
}

.v-col {
  padding-left: 0;
  padding-right: 0;
  padding-top: 0;
  padding-bottom: 0;
}

.v-row {
  padding: 0;
  margin: 0;
}
</style>
