<template>
  <img
      :src="src"
      class="background-image"
      @load="onImageLoaded"
      @error="onImageError"
      @click="navigateTo('tablet')"
      crossorigin="anonymous"
  />
</template>

<style scoped>
.background-image {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  z-index: -1;
}
</style>

<script setup lang="ts">
import ConfigService from '@/services/ConfigService';
import { inject, ref } from 'vue';

const src = ref(ConfigService.getConfig('BACKGROUND_URL') + '?v=' + Date.now());

const onImageLoaded = () => {
  console.log('Background image loaded successfully from:', src.value);
};

const onImageError = (event: Event) => {
  console.error('Failed to load background image:', src.value, event);
};

const navigateTo = inject<Function>('navigateTo');
</script>