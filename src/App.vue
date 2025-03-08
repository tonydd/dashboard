<script>
import DateTime  from "@/components/DateTime.vue";
import Weather from "@/components/Weather.vue";
import GoogleCalendar from "@/components/calendar.vue";
import Spotify from "@/components/Spotify.vue";
import Image from "@/components/Image.vue";
import Timer from "@/components/Timer.vue";
import FrenchFuel from "@/components/FrenchFuel.vue";
import CustomMessage from "@/components/CustomMessage.vue";
import Solar from "@/components/Solar.vue";
import WaterHeater from "@/components/WaterHeater.vue";
import {defineComponent} from "vue";
import Battery from "@/components/Battery.vue";
import ConfigService from "./services/ConfigService";
import Background from "@/components/Background.vue";
import SsfNews from "@/components/SsfNews.vue";
import Recipes from "@/components/Recipes.vue";

export default defineComponent({
  components: {
    Recipes,
    SsfNews,
    Background,
    Battery,
    Solar, FrenchFuel, Timer, Image, Spotify, GoogleCalendar, Weather, DateTime, CustomMessage, WaterHeater},
  computed: {
    fuelStationId() {
      return import.meta.env.VITE_FUEL_API_STATION_ID;
    },
    fuelStationName() {
      return import.meta.env.VITE_FUEL_API_STATION_NAME;
    }
  },
  mounted () {
    console.log(ConfigService.getConfig('TEST'));
  }
})

</script>

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
          <Solar class="flex-center-horizontal flex-center-vertical"/>
          <WaterHeater />
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
      <FrenchFuel :station-id="fuelStationId" :name="fuelStationName" />
    </div>
    <div class="column" style="padding-left: 20px; max-width: 55%;">
      <SsfNews/>
    </div>
    <div class="column" style="padding-left: 20px;">
      <CustomMessage id="daily" />
    </div>
  </div>

  <v-divider style="margin-top: 20px; margin-bottom: 20px"/>
  <div class="row">
    <div class="column" @click="onClick" style="max-width: 50%; padding-right: 20px;">
      <GoogleCalendar />
    </div>
    <div class="column flex-center-vertical flex-center-horizontal" style="padding-left: 20px;">
      <Image />
    </div>
  </div>
</template>

<style scoped>

</style>
