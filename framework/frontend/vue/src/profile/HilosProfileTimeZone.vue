<!-- Immediate account choice: core owns the catalog, search and one clock;
the window tracks its write and only the session echo moves the mark. -->
<script setup lang="ts">
import {
  computedSignal,
  createSignal,
  hilosImpersonation,
  hilosSignedIn,
  type HilosTimeZonePicker,
  type HilosTimeZonePickerRow,
} from '@hilos/core/base'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import HilosActionError from '../HilosActionError.vue'
import HilosModal from '../HilosModal.vue'
import { useSignal } from '../useSignal.js'
import { useTrackedAction } from '../useTrackedAction.js'

const props = defineProps<{
  modelValue: boolean
  picker: HilosTimeZonePicker
}>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
const query = useSignal(props.picker.query)
// A mounted, closed window must not make the lazy core previews eager. This
// gate belongs to this browser opening only, like the picker's local clock;
// no worker or node reads it (framework-first.md, runtime/rt-context.md).
const previewOpen = createSignal(false)
const rows = useSignal(
  computedSignal<readonly HilosTimeZonePickerRow[]>(() =>
    previewOpen.get() ? props.picker.rows.get() : [],
  ),
)
const automatic = useSignal(
  computedSignal(() =>
    previewOpen.get() ? props.picker.automatic.get() : null,
  ),
)
// Automatic's presence is core's normalized-search verdict, including spaces
// and underscores. The view groups its rows without searching a second time.
const groups = computed(() => [
  ...(automatic.value ? [{ heading: null, rows: [automatic.value] }] : []),
  {
    heading: automatic.value ? 'All time zones' : 'Found',
    rows: rows.value,
  },
])
const signedIn = useSignal(hilosSignedIn)
const impersonation = useSignal(hilosImpersonation)
const available = computed(() => signedIn.value && impersonation.value === null)
const action = useTrackedAction()
const busy = computed(() => action.busy.value)
const loading = computed(() => action.loading.value)
const submitted = ref<string | null>(null)
const open = computed({
  get: () => props.modelValue && available.value,
  set: (value: boolean) => emit('update:modelValue', value),
})
// A reply belongs to its opening, including when the owner closes or unmounts
// the window while the action is still pending.
let opening = 0
watch(
  [() => props.modelValue, available],
  ([shown, allowed]) => {
    opening++
    if (shown && allowed) {
      action.clearError()
      props.picker.start()
      previewOpen.set(true)
    } else {
      previewOpen.set(false)
      props.picker.stop()
      if (shown) emit('update:modelValue', false)
    }
  },
  { flush: 'sync', immediate: true },
)
onBeforeUnmount(() => {
  opening++
  previewOpen.set(false)
  props.picker.stop()
})

async function choose(row: HilosTimeZonePickerRow): Promise<void> {
  if (
    !props.modelValue ||
    action.busy.value ||
    !row.available ||
    !hilosSignedIn.get() ||
    hilosImpersonation.get() !== null
  ) {
    return
  }
  const sentFrom = opening
  submitted.value = row.timeZone
  const success = await action.run(props.picker.pick(row.timeZone))
  if (success && opening === sentFrom && open.value) {
    emit('update:modelValue', false)
  }
}
</script>

<template>
  <HilosModal v-model="open" title="Time zone" :busy="busy">
    <div data-id="profile-time-zone-modal">
      <HilosActionError
        :action="action"
        details-title="Could not change time zone"
      />
      <input
        type="search"
        class="form-control"
        placeholder="Search time zones"
        aria-label="Search time zones"
        data-id="profile-time-zone-search"
        data-autofocus
        :value="query"
        :disabled="busy"
        @input="picker.setQuery(($event.target as HTMLInputElement).value)"
      />
      <div v-for="(group, index) in groups" :key="index" class="mt-3">
        <h6
          v-if="group.heading"
          class="small fw-semibold text-body-secondary mb-1"
        >
          {{ group.heading }}
        </h6>
        <div class="list-group">
          <button
            v-for="row in group.rows"
            :key="row.timeZone ?? 'automatic'"
            type="button"
            class="list-group-item list-group-item-action d-flex align-items-center gap-2 position-relative"
            :class="{ active: row.selected }"
            :aria-current="row.selected ? 'true' : undefined"
            :disabled="busy || !row.available"
            :aria-busy="busy && submitted === row.timeZone ? true : undefined"
            :data-id="
              row.timeZone === null
                ? 'profile-time-zone-automatic'
                : `profile-time-zone-option-${row.timeZone}`
            "
            @click="choose(row)"
          >
            <span class="flex-grow-1 text-break">
              {{ row.timeZone === null ? 'Automatic' : row.label }}
              <span
                v-if="row.timeZone === null || !row.available"
                class="d-block small"
                :class="{ 'text-body-secondary': !row.selected }"
                >{{
                  row.timeZone === null
                    ? `${row.label} — from this browser`
                    : 'Unavailable in this browser'
                }}</span
              >
            </span>
            <span class="small text-nowrap">{{ row.time ?? '—' }}</span>
            <i
              class="bi bi-check2"
              :class="{
                invisible:
                  !row.selected || (loading && submitted === row.timeZone),
              }"
              aria-hidden="true"
            ></i>
            <span
              v-if="loading && submitted === row.timeZone"
              class="position-absolute top-50 end-0 translate-middle-y me-3 spinner-border spinner-border-sm"
              role="status"
              data-id="profile-time-zone-loading"
              ><span class="visually-hidden">Loading…</span></span
            >
          </button>
        </div>
      </div>
      <p
        v-if="rows.length === 0"
        class="small text-body-secondary mt-3 mb-0"
        data-id="profile-time-zone-empty"
      >
        No time zones match
      </p>
    </div>
    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-outline-secondary"
        :disabled="busy"
        data-id="profile-time-zone-close"
        @click="requestClose"
      >
        Close
      </button>
    </template>
  </HilosModal>
</template>
