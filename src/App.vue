<script>
import Router from "@/components/core/Router.vue";
import AuthGate from "@/components/AuthGate.vue";

export default {
  components: {AuthGate, Router},
};
</script>

<template>
  <AuthGate>
    <Router />
  </AuthGate>
</template>

<style scoped>

</style>
