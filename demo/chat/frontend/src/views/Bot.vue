<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Bot</h5>
        </div>
        <div class="card-body">
          <template v-if="!chatStore.isConnected">
            <div class="placeholder-glow">
              <span class="placeholder col-4 mb-2 d-block" style="height: 0.875rem"></span>
              <span class="placeholder col-3" style="height: 0.875rem"></span>
            </div>
          </template>
          <template v-else>
            <p class="text-muted">Заготовка</p>
            <p>Bot ID: {{ botId }}</p>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useChatStore } from '@/stores'

const route = useRoute()
const chatStore = useChatStore()
const botId = computed(() => route.params.id as string)
</script>
