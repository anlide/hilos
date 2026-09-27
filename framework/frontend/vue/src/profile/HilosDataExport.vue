<script setup lang="ts">
import { computed } from 'vue'
import {
  DATA_EXPORT_DOWNLOAD_PATH,
  HILOS_DATA_EXPORT_COPY as COPY,
  HILOS_STEP_UP_COPY,
  dataExportStatusText,
  hilosImpersonation,
  type HilosDataExportStore,
  type HilosDataExportFlow,
} from '@hilos/core'
import HilosFormError from '../HilosFormError.vue'
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'
import HilosStepUpStep from '../auth/HilosStepUpStep.vue'
import { useSignal } from '../useSignal.js'

const props = withDefaults(
  defineProps<{
    store: HilosDataExportStore
    flow: HilosDataExportFlow
    lead?: string
    titled?: boolean
  }>(),
  { titled: true, lead: '' },
)
const impersonation = useSignal(hilosImpersonation)
const node = useSignal(props.store.state)
const busy = useSignal(props.flow.busy)
const refusal = useSignal(props.flow.refusal)
const stepRefusal = useSignal(props.flow.stepUp.refusal)
const shown = useSignal(props.flow.open)
const open = computed({
  get: () => shown.value,
  set: (value: boolean) => {
    if (!value) props.flow.close()
  },
})
const status = computed(() => dataExportStatusText(node.value))
const preparingRoom = COPY.preparing.replace(
  '{time}',
  new Date().toLocaleString(),
)
</script>

<template>
  <section
    class="mb-3"
    :class="{ 'border rounded p-3': titled }"
    data-id="data-export"
  >
    <h3 v-if="titled" class="h6 mb-2">{{ COPY.title }}</h3>
    <p class="small text-body-secondary mb-2">
      {{ titled ? COPY.lead : '' }} {{ lead }}
    </p>
    <div class="visually-hidden" role="status" aria-live="polite">
      {{ status }}
    </div>
    <div class="visually-hidden" role="alert" aria-live="assertive">
      {{ shown ? '' : refusal }}
    </div>
    <div class="hilos-stack">
      <p class="small mb-2 invisible" aria-hidden="true" inert>
        {{ preparingRoom }}
      </p>
      <p
        v-if="node !== null"
        class="small mb-2"
        :data-id="`data-export-${node.state}`"
      >
        {{ status }}
      </p>
    </div>
    <HilosFormError
      :message="shown ? null : refusal"
      data-id="data-export-error"
    />
    <div class="hilos-stack">
      <div class="d-flex flex-column gap-2 invisible" aria-hidden="true" inert>
        <span class="btn btn-sm btn-primary">{{ COPY.download }}</span>
        <span class="btn btn-sm btn-outline-secondary">{{
          COPY.prepareNew
        }}</span>
      </div>
      <div class="d-flex flex-column gap-2">
        <template v-if="node?.state === 'ready'">
          <a
            v-if="impersonation === null"
            class="btn btn-sm btn-primary"
            :href="DATA_EXPORT_DOWNLOAD_PATH"
            download
            data-id="data-export-download"
            >{{ COPY.download }}</a
          >
          <LoadingButton
            class="btn-sm btn-outline-secondary"
            :loading="busy"
            data-id="data-export-prepare-new"
            @click="flow.prepare()"
            >{{ COPY.prepareNew }}</LoadingButton
          >
        </template>
        <LoadingButton
          v-else-if="node?.state !== 'preparing'"
          class="btn-sm btn-primary"
          :loading="busy"
          :data-id="
            node?.state === 'failed'
              ? 'data-export-retry'
              : 'data-export-prepare'
          "
          @click="flow.prepare()"
          >{{
            node?.state === 'failed' ? COPY.retry : COPY.prepare
          }}</LoadingButton
        >
      </div>
    </div>
    <HilosModal
      v-model="open"
      :title="HILOS_STEP_UP_COPY.title"
      initial-focus="inner"
    >
      <div class="visually-hidden" role="alert" aria-live="assertive">
        {{ stepRefusal ?? refusal }}
      </div>
      <form
        id="hilos-data-export-proof"
        data-id="data-export-step-up"
        @submit.prevent="flow.confirm()"
      >
        <HilosStepUpStep :controller="flow.stepUp" />
        <HilosFormError :message="refusal" data-id="data-export-order-error" />
      </form>
      <template #actions="{ requestClose }">
        <button
          type="button"
          class="btn btn-outline-secondary"
          @click="requestClose"
        >
          {{ HILOS_STEP_UP_COPY.cancel }}
        </button>
        <LoadingButton
          class="btn-primary"
          type="submit"
          form="hilos-data-export-proof"
          :loading="busy"
          data-id="data-export-confirm"
          >{{ HILOS_STEP_UP_COPY.confirm }}</LoadingButton
        >
      </template>
    </HilosModal>
  </section>
</template>
