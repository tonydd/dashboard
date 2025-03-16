<script>
import { ref, defineAsyncComponent } from "vue";
import ConfigService from "@/services/ConfigService.js";

export default {
  setup() {
    const target = ConfigService.getConfig("TARGET");
    const templateComponent = ref(
        defineAsyncComponent(() =>
            import(`@/components/templates/${target.charAt(0).toUpperCase() + target.slice(1)}.vue`)
                .catch(() => import("@/components/ErrorComponent.vue"))
        )
    );

    return { templateComponent };
  },
};
</script>

<template>
  <component :is="templateComponent"/>
</template>

<style scoped>

</style>
