<template>
  <div class="row">
    <div class="column">
      <DateTime />
        <Solar class="flex-center-horizontal flex-center-vertical"/>
        <WaterHeater />
    </div>
  </div>
</template>

<script setup lang="ts">
import Solar from "@/components/Solar.vue";
import DateTime from "@/components/DateTime.vue";
import WaterHeater from "@/components/WaterHeater.vue";
</script>