<template>
  <div class="row col-full flex-center-horizontal flex-center-vertical stepper-wrapper">
    <span v-for="i in total" class="stepper-element mr-4" :class="{ active: (i - 1) === current }">
    </span>
  </div>
</template>

<script setup lang="ts">
defineProps<{ total: number, current: number }>();
</script>

<style scoped>
.stepper-wrapper {
  display: flex;
  justify-content: space-between;
  align-items: center;
  width: 100%;
  gap: 1rem; /* espace entre les éléments */
}

.stepper-element {
  flex-grow: 1;
  height: 20px;
  border-radius: 2px;
  border: 1px solid white;
  background-color: silver;
  opacity: 0.25;
}

.stepper-element.active {
  opacity: 1;
  background-color: white;
}
</style>