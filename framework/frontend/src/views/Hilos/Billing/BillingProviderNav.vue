<template>
  <div class="d-flex flex-wrap gap-2">
    <router-link class="btn btn-sm btn-outline-primary" :to="`/hilos/billing/${providerId}`">
      Config
    </router-link>
    <router-link class="btn btn-sm btn-outline-secondary" :to="`/hilos/billing/${providerId}/payments`">
      Payments
    </router-link>
    <router-link class="btn btn-sm btn-outline-secondary" :to="`/hilos/billing/${providerId}/refunds`">
      Refunds
    </router-link>
    <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/billing">All providers</router-link>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  providerId: string
}>()
</script>
