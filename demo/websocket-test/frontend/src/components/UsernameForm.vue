<template>
  <div class="card mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
      <div>
        <small class="text-muted d-block">Your Name</small>
        <strong>{{ displayUsername }}</strong>
      </div>
      <router-link
        to="/profile"
        class="btn btn-link p-2"
        title="Change username"
        aria-label="Change username"
      >
        <i class="bi bi-gear-fill"></i>
      </router-link>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useChatStore } from '@/stores'

const chatStore = useChatStore()

const displayUsername = computed(() => {
  return chatStore.currentUsername || 'User'
})
</script>
