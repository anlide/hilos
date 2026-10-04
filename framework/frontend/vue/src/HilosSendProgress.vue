<script setup lang="ts">
import {
  SEND_AGAIN_LABEL,
  SEND_PROGRESS_DETAILS_CLASS,
  SEND_PROGRESS_ROW_CLASS,
  sendAgainIn,
  sendAgainLocked,
  sendProgressLine,
  type CodeSendProgress,
} from '@hilos/core'
import { computed, onUnmounted, ref, watch } from 'vue'

import HilosLongText from './HilosLongText.vue'
import HilosModal from './HilosModal.vue'

const props = defineProps<{
  /** The session's send line for this window, or null before the first send. */
  progress: CodeSendProgress | null
  /** Address or phone number receiving the code. */
  to: string
  /** Local-scale moment another send is allowed, or null. */
  resendAt: number | null
  /** Whether this window is ordering a code now. */
  busy: boolean
  /** Data-id of the whole block; the row and controls derive theirs from it. */
  dataId: string
}>()

const emit = defineEmits<{ 'send-again': [] }>()
const now = ref(Date.now())
const detailOpen = ref(false)
const line = computed(() => sendProgressLine(props.progress, props.to))
const countdown = computed(() => sendAgainIn(props.resendAt, now.value))
const locked = computed(() =>
  sendAgainLocked(props.progress, props.busy, props.resendAt, now.value),
)
const hasSend = computed(
  () => props.progress !== null || props.resendAt !== null,
)
let clock: ReturnType<typeof setInterval> | null = null

watch(
  () => props.resendAt,
  (moment) => {
    if (clock !== null) {
      clearInterval(clock)
      clock = null
    }
    now.value = Date.now()
    if (moment !== null && now.value < moment) {
      clock = setInterval(() => {
        now.value = Date.now()
        if (now.value >= moment && clock !== null) {
          clearInterval(clock)
          clock = null
        }
      }, 1000)
    }
  },
  { immediate: true },
)

watch(line, (value) => {
  if (value === null) {
    detailOpen.value = false
  }
})

onUnmounted(() => {
  if (clock !== null) {
    clearInterval(clock)
  }
})
</script>

<template>
  <div :data-id="dataId">
    <div
      v-if="line !== null"
      :class="[SEND_PROGRESS_ROW_CLASS, line.tone]"
      :data-id="`${dataId}-line`"
    >
      <i :class="['bi', line.icon, 'flex-shrink-0']" aria-hidden="true"></i>
      <span class="flex-grow-1 text-truncate">{{ line.text }}</span>
      <button
        type="button"
        :class="SEND_PROGRESS_DETAILS_CLASS"
        aria-label="Show send details"
        title="Show send details"
        :data-id="`${dataId}-details`"
        @click="detailOpen = true"
      >
        <i class="bi bi-info-circle" aria-hidden="true"></i>
      </button>
    </div>
    <div
      v-else
      :class="[SEND_PROGRESS_ROW_CLASS, 'invisible']"
      aria-hidden="true"
      :data-id="`${dataId}-idle`"
    >
      <i class="bi bi-hourglass-split flex-shrink-0" aria-hidden="true"></i>
      <span class="flex-grow-1 text-truncate">&nbsp;</span>
      <span :class="SEND_PROGRESS_DETAILS_CLASS"
        ><i class="bi bi-info-circle" aria-hidden="true"></i
      ></span>
    </div>

    <div class="mb-3">
      <span
        v-if="countdown !== null"
        class="btn btn-link btn-sm p-0 disabled"
        :data-id="`${dataId}-again-in`"
      >
        {{ countdown }}
      </span>
      <button
        v-else-if="hasSend"
        type="button"
        class="btn btn-link btn-sm p-0"
        :disabled="locked"
        :aria-busy="busy || undefined"
        :data-id="`${dataId}-again`"
        @click="emit('send-again')"
      >
        {{ SEND_AGAIN_LABEL }}
      </button>
      <span
        v-else
        class="btn btn-link btn-sm p-0 invisible"
        aria-hidden="true"
        >{{ SEND_AGAIN_LABEL }}</span
      >
    </div>
  </div>

  <HilosModal v-model="detailOpen" title="Send details" initial-focus="dialog">
    <HilosLongText
      kind="prose"
      :text="line?.text ?? ''"
      :data-id="`${dataId}-full`"
    />
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :data-id="`${dataId}-close`"
        @click="requestClose"
      >
        Close
      </button>
    </template>
  </HilosModal>
</template>
