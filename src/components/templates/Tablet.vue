<template>
  <Background/>

  <div class="row">
    <div class="column">
      <DateTime />
      <div class="row" style="min-height: 75px;">
        <div class="column flex-center-vertical flex-center-horizontal">
          <Timer icon="mdi-stove" name="timer-stove" />
        </div>
        <div class="column flex-center-vertical flex-center-horizontal">
          <Timer icon="mdi-toaster-oven" name="timer-oven" />
        </div>
        <div class="column flex-center-vertical flex-center-horizontal">
          <Solar/>
          <WaterHeater />
          <WaterQuality />
        </div>
      </div>
    </div>
    <div class="column">
      <Weather />
    </div>
  </div>
  <v-divider style="margin-top: 20px; margin-bottom: 20px"/>

  <div class="row">
    <div class="column" style="max-width: 12%">
      <FrenchFuel :station-id="ConfigService.getConfig('FUEL_API_STATION_ID')" :name="ConfigService.getConfig('FUEL_API_STATION_NAME')" />
    </div>
    <div class="column" style="padding-left: 20px; max-width: 35%;">
      <SsfNews/>
    </div>
    <div class="column" style="padding-left: 20px; max-width: 50%">
      <CustomMessage id="daily" />
    </div>
    <div class="column" style="padding-left: 20px;">
      <a id="torecipe" href="/recipe/list">
        <h1>Liste des recettes 🍽️</h1>
      </a>
    </div>
  </div>

  <v-divider style="margin-top: 20px; margin-bottom: 20px"/>
  <div class="row">
    <div class="column" style="max-width: 50%; padding-right: 20px;">
      <GoogleCalendar />
    </div>
    <div class="column flex-center-vertical flex-center-horizontal" style="padding-left: 20px;">
      <Image />
    </div>
  </div>
</template>

<script setup>
import Image from "@/components/Image.vue";
import FrenchFuel from "@/components/FrenchFuel.vue";
import Solar from "@/components/Solar.vue";
import DateTime from "@/components/DateTime.vue";
import SsfNews from "@/components/SsfNews.vue";
import WaterHeater from "@/components/WaterHeater.vue";
import GoogleCalendar from "@/components/calendar.vue";
import Background from "@/components/Background.vue";
import Weather from "@/components/Weather.vue";
import Timer from "@/components/Timer.vue";
import CustomMessage from "@/components/CustomMessage.vue";
import ConfigService from "@/services/ConfigService.js";
import WaterQuality from "@/components/WaterQuality.vue";
</script>

<style scoped>
#torecipe {
  border: 1px solid white;
  border-radius: 5px;
  text-align: center;
  height: 100px;
  color: white;
}
#torecipe h1 {
  margin: auto;
  text-align: center;
  margin-top: 0.6em;
}
</style>