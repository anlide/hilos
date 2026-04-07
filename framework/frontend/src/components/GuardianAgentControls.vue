<template>
  <div class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-sm-end gap-2">
    <span
      class="badge rounded-pill d-inline-flex align-items-center"
      :class="statusMeta.badgeClass"
    >
      <i
        class="bi me-1"
        :class="statusMeta.iconClass"
        aria-hidden="true"
      ></i>
      {{ statusMeta.label }}
    </span>
    <div class="btn-group btn-group-sm" role="group" aria-label="Guardian agent controls">
      <LoadingButton
        type="button"
        :variant="actionButtonClass"
        :loading="loading"
        :disabled="disabled"
        :title="actionLabel"
        :aria-label="actionLabel"
        @click.stop="handleAction"
      >
        <i class="bi" :class="actionIconClass" aria-hidden="true"></i>
        <span class="ms-1">{{ actionLabel }}</span>
      </LoadingButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import LoadingButton from './LoadingButton.vue'
import type { GuardianRunStatus } from '../types/guardianAgentRuns'

const props = withDefaults(defineProps<{
  status: GuardianRunStatus
  disabled?: boolean
  loading?: boolean
}>(), {
  disabled: false,
  loading: false,
})

const emit = defineEmits<{
  start: []
  stop: []
}>()

const statusMetaById: Record<GuardianRunStatus, { label: string; badgeClass: string; iconClass: string }> = {
  not_started: {
    label: 'Not started',
    badgeClass: 'bg-secondary-subtle text-body-secondary',
    iconClass: 'bi-dash-circle-fill',
  },
  in_progress: {
    label: 'In progress',
    badgeClass: 'text-bg-primary',
    iconClass: 'bi-arrow-repeat',
  },
  done: {
    label: 'Done',
    badgeClass: 'text-bg-success',
    iconClass: 'bi-check-circle-fill',
  },
  stopped: {
    label: 'Stopped',
    badgeClass: 'bg-warning text-dark',
    iconClass: 'bi-stop-circle-fill',
  },
  failed: {
    label: 'Failed',
    badgeClass: 'text-bg-danger',
    iconClass: 'bi-exclamation-circle-fill',
  },
}

const isInProgress = computed(() => props.status === 'in_progress')

const statusMeta = computed(() => statusMetaById[props.status])

const actionLabel = computed(() => (isInProgress.value ? 'Stop' : 'Start'))

const actionButtonClass = computed(() => (
  isInProgress.value ? 'btn-outline-warning' : 'btn-outline-success'
))

const actionIconClass = computed(() => (
  isInProgress.value ? 'bi-stop-fill' : 'bi-play-fill'
))

const handleAction = () => {
  if (props.disabled) {
    return
  }

  if (isInProgress.value) {
    emit('stop')
    return
  }

  emit('start')
}
</script>
