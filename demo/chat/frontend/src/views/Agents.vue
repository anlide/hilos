<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <div class="card">
        <div class="card-header">
          <h2 class="h4 mb-0 fw-bold">Agents</h2>
        </div>
        <div class="card-body">
          <p class="mb-0">
            This page will host links to two Markdown documents describing how AI agents
            can interact with this site (API usage, automation guidelines, supported flows).
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'

useHead({
  title: 'Agents | Demo WebSocket Chat',
  meta: [
    {
      name: 'description',
      content: 'API usage and automation guidelines for AI agents interacting with the chat.'
    }
  ]
})
</script>
