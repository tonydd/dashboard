<script>

import {defineComponent} from "vue";
import Tablet from "@/components/templates/Tablet.vue";
import Phone  from "@/components/templates/Phone.vue";
import ConfigService from "@/services/ConfigService.js";

export default defineComponent({
  components: {
    Tablet,
  },
  computed: {
    templateComponent() {
      return ConfigService.getConfig('TARGET') === 'phone' ? Phone : Tablet;
    },
  },
});

</script>

<template>
  <component :is="templateComponent"/>
</template>

<style scoped>

</style>
