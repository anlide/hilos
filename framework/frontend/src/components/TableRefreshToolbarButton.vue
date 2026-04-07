<template>
  <LoadingButton
    type="button"
    variant="btn-outline-secondary btn-sm"
    :title="title"
    :aria-label="ariaLabel"
    :loading="loading"
    :loading-delay="300"
    @click="emit('click')"
  >
    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
    Refresh
  </LoadingButton>
</template>

<script setup lang="ts">
import LoadingButton from './LoadingButton.vue'

withDefaults(
  defineProps<{
    loading: boolean
    title?: string
    ariaLabel?: string
  }>(),
  {
    title: 'Refresh table from server',
    ariaLabel: 'Refresh table',
  },
)

const emit = defineEmits<{
  click: []
}>()
</script>
