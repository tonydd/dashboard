<script>
import ConfigService from "@/services/ConfigService.js";

export default {
  name: "WaterHeater",
  data() {
    return {
      waterHeaterData: {
        heatingState: false,
        waterTemperature: 0,
        available40Degrees: 0,
        electricalConsumption: 0
      },
      value: [],
      dialog: false,
    }
  },
  beforeMount() {
    this.getWaterHeaterData();
  },
  mounted() {
    setInterval(() => {
      this.getWaterHeaterData();
    }, ConfigService.getIntervalConfig('WATER_HEATER_REFRESH_INTERVAL'));
  },
  methods: {
    getWaterHeaterData() {
      fetch(ConfigService.getConfig('WATER_HEATER_API_URL'), {method: 'GET', mode: 'cors'})
          .then((res) => res.json())
          .then(res => {
            if ('error' in res) {
              console.error(res);
              return;
            }
            this.waterHeaterData = res;
          });
    },
    showDialog() {
      this.value = this.getSparklineData();
      this.dialog = true;
    },
    round1(num) {
      return Math.round(num * 10) / 10;
    },
    getSparklineData() {
      let history = JSON.parse(localStorage.getItem('waterBoilerHistory'));
      let data = [];
      for (const entry of history.history) {
        data.push({label: 'Test', value: entry.V40Capacity});
      }
      return data;
    },
    getDateForHistoryEntryAtIndex(index) {
      let history = JSON.parse(localStorage.getItem('waterBoilerHistory'));
      return history.history[index].date;
    }
  },
  computed: {
    color() {
      if (this.waterHeaterData.heatingState) {
        return 'red';
      }
      if (this.waterHeaterData.absenceMode) {
        return 'grey';
      }
      return 'white';
    }
  }
}
</script>

<template>
    <div class="row" @click="showDialog">
      <v-icon size="x-large" :color="color">mdi-water-boiler</v-icon>
      <div>
        <h3 style="display: inline">&nbsp;{{ Math.round((waterHeaterData.available40Degrees * 100) / 200) }}%</h3>
        <h6 style="color: lightgrey; display: inline">&nbsp;{{ Math.round((waterHeaterData.available40Degrees * 100) / 333) }}%</h6>
      </div>
    </div>
    <div v-if="false">
      <div class="row">
        <v-icon size="x-small">mdi-heat-wave</v-icon>
        <h4 :class="{red: waterHeaterData.heatingState}">
          &nbsp;{{ waterHeaterData.heatingState ? 'En chauffe' : 'Au repos' }}</h4>
      </div>
      <div class="row flex-center-vertical">
        <v-icon size="x-small">mdi-water-thermometer</v-icon>
        <h4>&nbsp;{{ Math.round((waterHeaterData.available40Degrees * 100) / 200) }}%</h4><h6 style="color: lightgrey">&nbsp;{{ Math.round((waterHeaterData.available40Degrees * 100) / 333) }}%</h6>
      </div>
      <div class="row flex-center-vertical">
        <v-icon size="x-small">mdi-meter-electric-outline</v-icon>
        <h4>&nbsp;{{ Math.round(waterHeaterData.wh / 1000) }} kWh</h4>
      </div>
    </div>

  <v-dialog
      v-model="dialog"
  >
    <v-card
        prepend-icon="mdi-water-boiler"
        title="Détails du chauffe eau"
    >
      <v-card-text>
        <v-row>
          <v-col cols="12">

            <v-sparkline
                :model-value="value"
                color="rgba(255, 255, 255, .7)"
                height="100"
                padding="24"
                stroke-linecap="round"
                smooth
            >
              <template v-slot:label="item">
                {{ (item.index % 3 === 0) ? item.value + 'L' : '' }}
              </template>
            </v-sparkline>

          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-actions style="justify-content: space-between">
        <v-btn
            text="Actualiser"
            variant="plain"
            @click="getWaterHeaterData"
        ></v-btn>

        <v-btn
            text="Fermer"
            variant="plain"
            @click="dialog = false"
        ></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.red {
  color: red;
}

text {
  font-size: 3px;
}
</style>
