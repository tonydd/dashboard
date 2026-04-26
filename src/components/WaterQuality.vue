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
  try {
    let response = await fetch(ConfigService.getConfig("WATER_QUALITY_API_URL"), { method: "GET", mode: "cors" });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const data = await response.json();
    if (data && typeof data === 'object') {
      waterQuality.value = data;
    } else {
      console.error('Invalid water quality data format');
    }
  } catch (error) {
    console.error('Water quality API error:', error);
    waterQuality.value = null;
  }
}

const color = computed(() => {
  if (!waterQuality.value) {
    return "white";
  }

  let color;
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
      <h3 style="margin-left: 4px;">{{ waterQuality.score }}</h3>
      <label style="color: lightgray; font-size: 10px; margin-left: 4px">{{ waterQuality.status }}</label>
  </div>
</template>
