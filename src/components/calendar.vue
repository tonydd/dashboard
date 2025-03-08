<template>
  <div class="row" >
    <button @click="navigateToGoogleLogin" style=""></button>
    <iframe id="embedded-agenda"
            :src="googleCalendarIframeUrl"
            style="border-width:0"
            width="600"
            height="320"
            frameborder="0"
            scrolling="no">

    </iframe>
  </div>
</template>

<script>
import ConfigService from "@/services/ConfigService.js";

export default {
  name: "GoogleCalendar",
  data() {
    return {
      googleCalendarIframeUrl: ConfigService.getConfig('GOOGLE_CALENDAR_IFRAME_URL')
    };
  },
  methods: {
    /**
     * On click on the invisible button, redirects the user to the Google login page
     */
    navigateToGoogleLogin() {
      window.location.href = 'https://accounts.google.com';
    }
  },
  mounted() {
    // This hack is to refresh the iframe every hour
    setInterval(
        () => document.getElementById('embedded-agenda').src = document.getElementById('embedded-agenda').src,
        ConfigService.getIntervalConfig('CALENDAR_REFRESH_INTERVAL')
    );
  },
};
</script>

<style scoped>
  /* This is a hack to have an invisible button in the top left corner */
  button {
    position: absolute;
    top: 0;
    left: 0;
    width: 75px;
    height: 75px;
    background: rgba(0,0,0,0);
  }
</style>