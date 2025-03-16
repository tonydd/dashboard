<script>
import { defineComponent, defineAsyncComponent } from "vue";
import ConfigService from "@/services/ConfigService.js";

export default defineComponent({
  computed: {
    templateComponent() {
      const target = ConfigService.getConfig('TARGET');
      return defineAsyncComponent(() => 
        import(`@/components/templates/${target.charAt(0).toUpperCase() + target.slice(1)}.vue`)
          .catch(() => import('@/components/ErrorComponent.vue'))
      );
    },
  },
});
</script>

<template>
  <component :is="templateComponent"/>
</template>

<style scoped>

</style>
