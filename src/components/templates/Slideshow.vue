<template>
  <img
      :src="src"
      class="background-image"
      @load="onImageLoaded"
      @error="onImageError"
      @click="navigateTo('tablet')"
      crossorigin="anonymous"
  />
  <div 
    ref="dateTimeWrapper"
    @mousedown="startDrag"
    @touchstart="startDrag"
    :style="dateTimeWrapperStyle"
    class="datetime-wrapper"
  >
    <DateTime :fontSize="'50px'" />
  </div>
</template>

<style scoped>
.background-image {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  z-index: -1;
}

.datetime-wrapper {
  border-radius: 5px;
  background-color: rgba(0, 0, 0, 0.5);
  padding: 20px;
  position: fixed;
  cursor: grab;
  user-select: none;
  touch-action: none;
  z-index: 10;
}

.datetime-wrapper:active {
  cursor: grabbing;
}
</style>

<script setup lang="ts">
import ConfigService from '@/services/ConfigService';
import { inject, ref, computed, onMounted, onUnmounted } from 'vue';
import DateTime from '../DateTime.vue';

const src = ref(ConfigService.getConfig('BACKGROUND_URL') + '?token=' + ConfigService.getConfig('BACKGROUND_TOKEN') + '&timestamp=' + new Date().getTime());

const STORAGE_KEY = 'slideshow-datetime-position';
const DEFAULT_POSITION = { x: 50, y: 10 };

const position = ref<{ x: number; y: number }>(DEFAULT_POSITION);
const isDragging = ref(false);
const dragStart = ref<{ x: number; y: number }>({ x: 0, y: 0 });
const dragOffset = ref<{ x: number; y: number }>({ x: 0, y: 0 });
const dateTimeWrapper = ref<HTMLElement | null>(null);

const dateTimeWrapperStyle = computed(() => ({
  left: `${position.value.x}%`,
  top: `${position.value.y}%`,
}))

const loadPositionFromStorage = () => {
  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      position.value = JSON.parse(stored);
    } catch (e) {
      console.error('Failed to parse stored position:', e);
      position.value = DEFAULT_POSITION;
    }
  }
};

const savePositionToStorage = () => {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(position.value));
};

const startDrag = (e: MouseEvent | TouchEvent) => {
  isDragging.value = true;
  const clientX = e instanceof MouseEvent ? e.clientX : e.touches[0].clientX;
  const clientY = e instanceof MouseEvent ? e.clientY : e.touches[0].clientY;
  
  dragStart.value = { x: clientX, y: clientY };
  dragOffset.value = { x: position.value.x, y: position.value.y };
  
  document.addEventListener('mousemove', onDragMove);
  document.addEventListener('touchmove', onDragMove);
  document.addEventListener('mouseup', endDrag);
  document.addEventListener('touchend', endDrag);
};

const onDragMove = (e: MouseEvent | TouchEvent) => {
  if (!isDragging.value) return;
  
  const clientX = e instanceof MouseEvent ? e.clientX : (e as TouchEvent).touches[0]?.clientX;
  const clientY = e instanceof MouseEvent ? e.clientY : (e as TouchEvent).touches[0]?.clientY;
  
  if (clientX === undefined || clientY === undefined) return;
  
  const deltaX = (clientX - dragStart.value.x) / window.innerWidth * 100;
  const deltaY = (clientY - dragStart.value.y) / window.innerHeight * 100;
  
  position.value = {
    x: dragOffset.value.x + deltaX,
    y: dragOffset.value.y + deltaY
  };
};

const endDrag = () => {
  isDragging.value = false;
  savePositionToStorage();
  
  document.removeEventListener('mousemove', onDragMove);
  document.removeEventListener('touchmove', onDragMove);
  document.removeEventListener('mouseup', endDrag);
  document.removeEventListener('touchend', endDrag);
};

onMounted(() => {
  loadPositionFromStorage();
});

onUnmounted(() => {
  document.removeEventListener('mousemove', onDragMove);
  document.removeEventListener('touchmove', onDragMove);
  document.removeEventListener('mouseup', endDrag);
  document.removeEventListener('touchend', endDrag);
});

const onImageLoaded = () => {
  console.log('Background image loaded successfully from:', src.value);
};

const onImageError = (event: Event) => {
  console.error('Failed to load background image:', src.value, event);
};

const navigateTo = inject<Function>('navigateTo');
</script>