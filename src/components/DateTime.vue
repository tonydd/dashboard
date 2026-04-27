<script>
import StringService from "@/services/StringService.js";
import DateService from "@/services/DateService.js";
export default {
  name: "DateTime",
  data() {
    return {
      date: new Date().toLocaleDateString(),
      time: new Date().toLocaleTimeString(),
      navigateTo: () => window.location.replace("/slideshow")
    };
  },
  mounted() {
    setInterval(() => {
      this.date = StringService.capitalizeFirstLetterArray(
          new Date().toLocaleString('fr-FR', {weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'}).split(' ')
      );
      this.time = new Date().toLocaleTimeString();
    }, DateService.getDeferInterval(1, 'second'));
  }
}
</script>

<template>
  <div class="flex-center-vertical flex-center-horizontal" @click="navigateTo('slideshow')">
    <h2>{{ date }}</h2>
    <h2>{{ time }}</h2>
  </div>
</template>

<style scoped>
  * {
    text-align: center;
    font-size: 44px;
  }
</style>