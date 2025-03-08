<script>
import DateService from "@/services/DateService.js";
import LogService from "@/services/LogService.js";
import ConfigService from "@/services/ConfigService.js";
import sampleWeatherData from '@/assets/sampleWeatherData.json';
import StringService from "@/services/StringService";
export default {
  name: "Weather",
  data() {
    return {
      url: '',
      weather: {
        current: {
          weather: [
            {
              icon: '',
              description: ''
            }
          ],
          temp: 0,
          humidity: 0,
          sunrise: 0,
          sunset: 0
        }
      },
      forecast: null,
      dialog: false
    };
  },
  beforeMount() {
    this.url = import.meta.env.VITE_OPENWEATHERMAP_API_URL;
    this.url += `?lat=${import.meta.env.VITE_OPENWEATHERMAP_LAT}&lon=${import.meta.env.VITE_OPENWEATHERMAP_LON}&appid=${import.meta.env.VITE_OPENWEATHERMAP_API_KEY}`;
    this.url += `&units=${import.meta.env.VITE_OPENWEATHERMAP_UNITS}&lang=${import.meta.env.VITE_OPENWEATHERMAP_LANG}`;
    this.getWeather();
  },
  mounted() {
    const self = this;
    setInterval(() => {
      self.getWeather();
    }, ConfigService.getIntervalConfig('WEATHER_REFRESH_INTERVAL'));
  },
  computed: {
    DateService: () => DateService,
    thermometerIcon() {
      if (this.weather.current.temp < 5) {
        return 'mdi-thermometer-low';
      } else if (this.weather.current.temp > 25) {
        return 'mdi-thermometer-high';
      } else {
        return 'mdi-thermometer';
      }
    },
  },
  methods: {
    getWeather() {
      if (import.meta.env.DEV) {
        this.weather = sampleWeatherData;
        this.computeForecast();
      } else {
        this._fetchWeather();
      }
    },
    _fetchWeather() {
      fetch(this.url)
        .then(response => response.json())
        .then(data => {
          if ('cod' in data && data.cod !== 200) {
            console.error(data);
            return;
          }
          LogService.logDev(data);
          this.weather = data;
          this.computeForecast();
        })
        .catch(error => { console.error(error) });
    },
    computeForecast() {
      let forecast = [];
      const minutely = this.weather.minutely[5];
      forecast.push({
        date: DateService.formatTime(new Date(minutely.dt * 1000)),
        precipitation: Math.round(minutely.precipitation * 10) / 10,
        temp: null,
        icon: minutely.precipitation === 0 ? 'mdi-cloud-check-outline' : (minutely.precipitation > 2 ? 'mdi-weather-pouring' : 'mdi-weather-rainy')
      })

      const boundaries = DateService.getMinutes(DateService.now()) > 30 ? [2, 4] : [1, 3];
      for (let i = boundaries[0]; i < boundaries[1]; i++) {
        const hourly = this.weather.hourly[i];
        forecast.push({
          date: DateService.formatTime(new Date(hourly.dt * 1000)),
          precipitation: null,
          temp: Math.round(hourly.temp),
          icon: this.getIcon(hourly.weather[0].icon)
        });
      }

      for (let i = 1; i < 3; i++) {
        const hourly = this.weather.daily[i];
        forecast.push({
          date: DateService.formatDate(new Date(hourly.dt * 1000)),
          precipitation: null,
          temp: Math.round(hourly.temp.min) + '/' + Math.round(hourly.temp.max),
          icon: this.getIcon(hourly.weather[0].icon)
        });
      }

      this.forecast = forecast;
    },
    getIcon(code) {
      return `https://openweathermap.org/img/wn/${code}@2x.png`;
      //http://openweathermap.org/img/wn
    },
    capitalizeFirstLetter(string) {
      return string.charAt(0).toUpperCase() + string.slice(1);
    },
    openDialog() {
      console.log('clcl');
      this.dialog = true;
    },
    formatDailyWeatherDate(timestamp) {
      return StringService.capitalizeFirstLetterArray(
          new Date((timestamp * 1000)).toLocaleString('fr-FR', {weekday: 'long', month: 'long', day: 'numeric'}).split(' ')
      );
    }
  }
}
</script>

<template>
  <div class="row" @click="openDialog">
    <div class="column flex-center-horizontal flex-center-vertical" style="max-width: 45%">
      <h2><img :src="getIcon(weather.current.weather[0].icon)" /></h2>
      <h2>{{ capitalizeFirstLetter(weather.current.weather[0].description) }}</h2>
    </div>

    <div class="column flex-center-horizontal">
      <h2><v-icon color="white">{{ thermometerIcon }}</v-icon>{{ Math.round(weather.current.temp) }}°C <label
          class="feels-like-temp">({{ Math.round(weather.current.feels_like) }}°C)</label></h2>
      <h2><v-icon color="white">mdi-water-percent</v-icon>{{ weather.current.humidity }} %</h2>
      <h2><v-icon color="white">mdi-weather-sunset-up</v-icon>Lever de soleil {{
        DateService.displayTimestampAsTime(weather.current.sunrise) }}</h2>
      <h2><v-icon color="white">mdi-weather-sunset-down</v-icon>Coucher de soleil {{
        DateService.displayTimestampAsTime(weather.current.sunset) }}</h2>
    </div>
  </div>
  <div class="row" v-if="forecast !== null">
    <div v-for="(fc, index) in forecast" class="column flex-center-vertical flex-center-horizontal">
      <h5>{{ fc.date }}</h5>
      <div v-if="index === 0">
        <v-icon color="white" style="margin: 7px;">{{ fc.icon }}</v-icon><br>
        <h5>{{ fc.precipitation }}mm</h5>
      </div>
      <div v-else>
        <img :src="fc.icon" style="max-height: 30px" />
        <h5>{{ fc.temp }}°C</h5>
      </div>
    </div>
  </div>

  <v-dialog v-model="dialog" fullscreen>

    <v-card prepend-icon="mdi-weather-cloudy-clock" title="Prévision des prochains jours">
      <v-card-text>
        <v-row style="overflow-y: auto">
          <v-col cols="12">
            <v-list>
              <v-list-item v-for="item in weather.daily">
                <template v-slot:default="">
                  <v-row>
                    <v-col cols="3">
                      <h4>{{ formatDailyWeatherDate(item.dt) }}</h4>
                      <label>{{ item.summary }}</label>
                    </v-col>
                    <v-col cols="3">
                      <img :src="getIcon(item.weather[0].icon)" />
                    </v-col>
                    <v-col cols="3">
                      <v-icon>mdi-thermometer-high</v-icon>&nbsp;Max: {{ item.temp.max }}°C<br />
                      <v-icon>mdi-thermometer-low</v-icon>&nbsp;Min: {{ item.temp.min }}°C
                    </v-col>
                    <v-col cols="3">
                      <v-icon>mdi-wind-power-outline</v-icon>&nbsp;Vent: {{ item.wind_speed }}m/s<br/>
                      <v-icon>{{ item.rain > 0 ? 'mdi-weather-pouring' : 'mdi-cloud-check-outline' }}</v-icon>&nbsp;Pluie prévue: {{ item.rain }}mm
                    </v-col>
                  </v-row>
                </template>
              </v-list-item>
              <v-list-item>
                <v-row>
                  <v-col cols="6">
                    <v-btn text="Actualiser" @click="getWeather"></v-btn>
                  </v-col>
                  <v-col cols="6">
                    <v-btn text="Fermer" variant="plain" @click="dialog = false"></v-btn>
                  </v-col>
                </v-row>
              </v-list-item>
            </v-list>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card>
      <v-card-actions style="justify-content: space-between">
        <v-btn text="Actualiser" @click="getWeather"></v-btn>
        <v-btn text="Fermer" variant="plain" @click="dialog = false"></v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</template>

<style scoped>
.feels-like-temp {
  color: lightgrey;
  font-size: 16px;
}
</style>
