<!-- The current server alarm, in one reserved line above the state card.
The inert twin holds the same room while clear; the ordinary modal reads the
complete, live diagnostics without issuing an action. -->
<script setup lang="ts">
import {
  HILOS_MAINTENANCE_WATCHDOG_COPY,
  type HilosMaintenanceWatchdogAlert,
} from '@hilos/core/admin/maintenance'
import { ref, watch } from 'vue'

import HilosModal from '../../HilosModal.vue'

const props = defineProps<{
  /** The core projection of the current server verdict, or null while clear. */
  alert: HilosMaintenanceWatchdogAlert | null
}>()

/** The row and its twin share every metric; tone changes no height. */
const ROW_CLASS = 'alert small py-1 px-2 my-2 d-flex align-items-center gap-2'
/** The permanent reading control and its non-interactive twin. */
const DETAILS_CLASS =
  'btn btn-link btn-sm p-0 lh-1 flex-shrink-0 text-decoration-none text-nowrap'

const detailOpen = ref(false)
watch(
  () => props.alert,
  (alert) => {
    if (alert === null) detailOpen.value = false
  },
)
</script>

<template>
  <div
    data-id="hilos-maintenance-watchdog-slot"
    role="status"
    aria-live="polite"
  >
    <div
      v-if="alert"
      :class="[ROW_CLASS, `alert-${alert.tone}`]"
      data-id="hilos-maintenance-watchdog-alert"
    >
      <i
        class="bi bi-exclamation-triangle flex-shrink-0"
        aria-hidden="true"
      ></i>
      <span class="flex-grow-1 text-truncate">
        <strong>{{ alert.title }}</strong> — {{ alert.summary }}
      </span>
      <button
        type="button"
        :class="DETAILS_CLASS"
        aria-label="Show details"
        title="Show details"
        data-id="hilos-maintenance-watchdog-details"
        @click="detailOpen = true"
      >
        {{ HILOS_MAINTENANCE_WATCHDOG_COPY.details }}
      </button>
    </div>
    <div
      v-else
      :class="[ROW_CLASS, 'alert-warning invisible']"
      aria-hidden="true"
      inert
      data-id="hilos-maintenance-watchdog-idle"
    >
      <i
        class="bi bi-exclamation-triangle flex-shrink-0"
        aria-hidden="true"
      ></i>
      <span class="flex-grow-1 text-truncate">&nbsp;</span>
      <span :class="DETAILS_CLASS">
        {{ HILOS_MAINTENANCE_WATCHDOG_COPY.details }}
      </span>
    </div>
  </div>

  <HilosModal
    v-model="detailOpen"
    :title="HILOS_MAINTENANCE_WATCHDOG_COPY.details"
    initial-focus="dialog"
  >
    <div v-if="alert" data-id="hilos-maintenance-watchdog-full">
      <h3 class="h6 text-break">{{ alert.title }}</h3>
      <p class="text-break">{{ alert.summary }}</p>
      <dl class="row mb-0">
        <template v-for="fact in alert.facts" :key="fact.label">
          <dt class="col-sm-4">{{ fact.label }}</dt>
          <dd class="col-sm-8 text-break">{{ fact.value }}</dd>
        </template>
      </dl>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        data-id="hilos-maintenance-watchdog-close"
        @click="requestClose"
      >
        {{ HILOS_MAINTENANCE_WATCHDOG_COPY.close }}
      </button>
    </template>
  </HilosModal>
</template>
