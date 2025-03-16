<template>
  <div class="error-container">
    <div class="error-content">
      <h2 class="error-title">{{ title }}</h2>
      <p class="error-message">{{ message }}</p>
      <button @click="retry" class="retry-button">
        Try Again
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    default: 'Oops! Something went wrong'
  },
  message: {
    type: String,
    default: 'An error occurred while processing your request.'
  }
})

const emit = defineEmits(['retry'])

const retry = () => {
  emit('retry')
}
</script>

<style scoped>
.error-container {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 200px;
  padding: 2rem;
}

.error-content {
  text-align: center;
  max-width: 500px;
}

.error-title {
  color: #dc3545;
  margin-bottom: 1rem;
}

.error-message {
  color: #6c757d;
  margin-bottom: 1.5rem;
}

.retry-button {
  background-color: #0d6efd;
  color: white;
  border: none;
  padding: 0.5rem 1rem;
  border-radius: 0.25rem;
  cursor: pointer;
  transition: background-color 0.2s;
}

.retry-button:hover {
  background-color: #0b5ed7;
}
</style>
