<template>
  <LoadingButton
    type="button"
    variant="btn-outline-secondary btn-sm"
    :title="title"
    :aria-label="ariaLabel"
    :loading="toValue(loading)"
    :loading-delay="300"
    @click="emit('click')"
  >
    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
    Refresh
  </LoadingButton>
</template>

<script setup lang="ts">
import type { MaybeRef } from 'vue'
import { toValue } from 'vue'
import LoadingButton from './LoadingButton.vue'

withDefaults(
  defineProps<{
    /** Supports Ref/ComputedRef from useTableRefresh(). */
    loading: MaybeRef<boolean>
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
