<!-- The account's live theme choice (HIL-1437). Each click is one tracked
write, with no draft or Save. Only the server handshake moves the mark. -->
<script setup lang="ts">
import {
  hilosImpersonation,
  hilosSignedIn,
  hilosThemeChoice,
  sendHilosThemePick,
  THEME_COPY,
  THEME_POSITIONS,
  type ThemePick,
} from '@hilos/core/base'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import HilosActionError from '../HilosActionError.vue'
import HilosModal from '../HilosModal.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

const props = defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
const choice = useSignal(hilosThemeChoice)
const signedIn = useSignal(hilosSignedIn)
const impersonation = useSignal(hilosImpersonation)
const available = computed(
  () => choice.value !== null && signedIn.value && impersonation.value === null,
)
const action = useTrackedAction()
const busy = computed(() => action.busy.value)
const loading = computed(() => action.loading.value)
const submitted = ref<ThemePick>(null)
const open = computed({
  get: () => props.modelValue && available.value,
  set: (value: boolean) => emit('update:modelValue', value),
})
// An answer belongs to the opening that sent it. Closing and reopening while
// the write is pending must not let its success close the new opening.
let opening = 0
watch(
  [() => props.modelValue, available],
  ([shown, allowed]) => {
    opening++
    if (shown) {
      action.clearError()
      if (!allowed) emit('update:modelValue', false)
    }
  },
  { flush: 'sync', immediate: true },
)
onBeforeUnmount(() => opening++)

async function choose(pick: ThemePick): Promise<void> {
  if (
    !props.modelValue ||
    action.busy.value ||
    hilosThemeChoice.get() === null ||
    !hilosSignedIn.get() ||
    hilosImpersonation.get() !== null
  ) {
    return
  }
  const sentFrom = opening
  submitted.value = pick
  const success = await action.run(sendHilosThemePick(pick))
  if (success && opening === sentFrom && open.value) {
    emit('update:modelValue', false)
  }
}
</script>

<template>
  <HilosModal v-model="open" :title="THEME_COPY.menu" initial-focus="dialog">
    <div data-id="profile-theme-modal">
      <HilosActionError
        :action="action"
        details-title="Couldn't change theme"
      />
      <div class="list-group">
        <button
          v-for="row in THEME_POSITIONS"
          :key="row.value"
          type="button"
          class="list-group-item list-group-item-action d-flex align-items-center gap-2 position-relative"
          :class="{ active: row.value === choice?.position }"
          :aria-current="row.value === choice?.position ? 'true' : undefined"
          :disabled="busy"
          :aria-busy="busy && submitted === row.value ? true : undefined"
          :data-id="`profile-theme-option-${row.value}`"
          @click="choose(row.value)"
        >
          <i class="bi" :class="row.icon" aria-hidden="true"></i>
          <span class="flex-grow-1">{{ row.label }}</span>
          <span
            v-if="row.value === choice?.position && choice.isDefault"
            class="badge rounded-pill bg-body-tertiary text-body-emphasis border"
            >{{ THEME_COPY.default }}</span
          >
          <i
            class="bi bi-check2"
            :class="{
              invisible:
                row.value !== choice?.position ||
                (loading && submitted === row.value),
            }"
            aria-hidden="true"
          ></i>
          <span
            v-if="loading && submitted === row.value"
            class="position-absolute top-50 end-0 translate-middle-y me-3 spinner-border spinner-border-sm"
            role="status"
            data-id="profile-theme-loading"
            ><span class="visually-hidden">Loading…</span></span
          >
        </button>
      </div>
      <hr />
      <button
        type="button"
        class="btn btn-outline-secondary w-100 position-relative"
        :disabled="busy"
        :aria-busy="busy && submitted === null ? true : undefined"
        data-id="profile-theme-default"
        @click="choose(null)"
      >
        <span :class="{ invisible: loading && submitted === null }">{{
          THEME_COPY.siteDefault
        }}</span>
        <span
          v-if="loading && submitted === null"
          class="position-absolute top-50 start-50 translate-middle spinner-border spinner-border-sm"
          role="status"
          data-id="profile-theme-loading"
          ><span class="visually-hidden">Loading…</span></span
        >
      </button>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-outline-secondary"
        data-id="profile-theme-close"
        @click="requestClose"
      >
        Close
      </button>
    </template>
  </HilosModal>
</template>
