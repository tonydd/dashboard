<script>
import DateService from "@/services/DateService.js";
import SolarService from "@/services/SolarService.js";
import ConfigService from "@/services/ConfigService.js";

export default {
  name: "Solar",
  data() {
    return {
      solarData: 'INIT',
    }
  },
  computed: {
  },
  beforeMount() {
    this.fetchSolarOutput();
  },
  mounted() {
    setInterval(() => {
      this.fetchSolarOutput()
    }, ConfigService.getIntervalConfig('SOLAR_PANEL_REFRESH_INTERVAL'));
  },
  methods: {
    fetchSolarOutput() {
      const now = DateService.now();
      if (SolarService.isSolarProductionPeriod(now)) {
        fetch(ConfigService.getConfig('SOLAR_API_URL'), {mode: "cors"}).then(res => res.json()).then(res => {
          this.solarData = res.production + ' W';
        });
      } else {
        this.solarData = 'OFFLINE';
      }
    }
  }
}
</script>

<template>
  <div class="row" @click="fetchSolarOutput">
    <v-icon size="x-large">mdi-solar-power-variant-outline</v-icon>
    <h3>{{solarData}}</h3>
  </div>
</template>

<style scoped>

</style>
