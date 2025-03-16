<script>
import ConfigService from "@/services/ConfigService.js";
import Tablet from "@/components/templates/Tablet.vue";
import Phone  from "@/components/templates/Phone.vue";

export default {
  computed: {
    templateComponent() {
      const target = ConfigService.getConfig("TARGET");

      switch (target) {
        case "tablet":
          return Tablet;
        case "phone":
          return Phone;
        default:
          return Tablet;
      }
    }
  },
};
</script>

<template>
  <component :is="templateComponent"/>
</template>

<style scoped>

</style>
