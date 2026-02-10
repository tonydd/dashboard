<template>
  <a @click="navigateTo(link, data)">
    <slot></slot>
  </a>
</template>

<script setup lang="ts">
import {inject} from "vue";

const navigateTo = inject<Function>('navigateTo');

const props = withDefaults(
    defineProps<{ link: string, data: Record<string, any> }>(),
    {
      data: () => ({})
    }
);

console.log('Link', JSON.parse(JSON.stringify(props)));
</script>