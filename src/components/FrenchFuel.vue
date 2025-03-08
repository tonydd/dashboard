<script setup>
import { onBeforeMount, onMounted, ref } from "vue";
import ConfigService from "@/services/ConfigService.js";
import LogService from "@/services/LogService";

let fuels = ref([]);

const props = defineProps({
  stationId: String,
  name: String,
});

onBeforeMount(() => {
  fetchFuels();
});

onMounted(() => {
  setInterval(() => {
    fetchFuels();
  }, ConfigService.getIntervalConfig("FUEL_INFO_REFRESH_INTERVAL"));
});

async function fetchFuels() {
  let response = await fetch(ConfigService.getConfig("FUEL_API_URL"), { method: "GET", mode: "cors" });
  response = await response.json();
  fuels.value = Object.values(response);
}

function showAsDayAndMonth(isoDateString) {
  const date = new Date(isoDateString);
  return (
    String(date.getDate()).padStart(2, "0") +
    "/" +
    String(date.getMonth() + 1).padStart(2, "0")
  );
}

function getKey() {
  return "fuel-" + props.stationId;
}
</script>

<template>
  <div class="row flex-center-vertical">
    <v-icon color="white">mdi-gas-station-outline</v-icon>
    <h3>{{ name }}</h3>
  </div>
  <v-row v-for="fuel in fuels" style="justify-content: space-between; margin: 0">
    <v-col cols="3"
      ><v-chip size="small">{{ fuel.type }}</v-chip></v-col
    >
    <v-col cols="4"
      ><label class="fuel-update">{{
        showAsDayAndMonth(fuel.ts)
      }}</label></v-col
    >
    <v-col cols="5">
      <h4>{{ fuel.price }} €</h4>
    </v-col>
  </v-row>
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
