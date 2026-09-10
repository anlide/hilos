<!-- HilosPrivacyEraseModal — the confirmation the erase on /privacy asks for: one
question, two answers, no fields, the dangerous answer in red, the shape the
component mockup draws for a destructive confirmation.

Its body is the LIST of what goes, one line per registry entry, taken from each
entry's own label — that is what makes the click worth asking for: the person
agrees to a list rather than to an adjective, and the list is right by
construction because it IS the registry. Under it the three sentences of the
boundary: this device only, irreversible, and the account untouched.

Not exported from the package. It is this page's own confirmation and has no
second caller; a modal in the public surface of the SDK would be a component
projects are invited to reuse, and this one answers for one button.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import HilosModal from '../HilosModal.vue'
import LoadingButton from '../LoadingButton.vue'

defineProps<{
  /** Whether the confirmation is open (v-model). */
  modelValue: boolean
  /** One line per registry entry: what this erase would take. */
  labels: readonly string[]
  /** The erase is in flight: the answers are held and the dialog cannot be dismissed. */
  busy: boolean
  /** Deferred loading of the erase, for the confirming button's spinner. */
  loading: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [open: boolean]
  confirm: []
}>()
</script>

<template>
  <HilosModal
    :model-value="modelValue"
    title="Erase everything this browser keeps?"
    :close-on-backdrop="!busy"
    :close-on-esc="!busy"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <p class="mb-2">This will remove from this browser:</p>
    <ul class="mb-3 ps-3" data-id="privacy-erase-list">
      <li v-for="label in labels" :key="label" class="small">{{ label }}</li>
    </ul>
    <p class="mb-2 small text-body-secondary">
      Only this device is affected, and only this browser on it.
    </p>
    <p class="mb-2 small text-body-secondary">
      It cannot be undone: nothing here is kept anywhere to put back.
    </p>
    <p class="mb-0 small text-body-secondary">
      Your account and everything in it are untouched — this is not account
      deletion.
    </p>

    <template #actions="{ requestClose }">
      <button
        type="button"
        class="btn btn-secondary"
        :disabled="busy"
        data-id="privacy-erase-cancel"
        @click="requestClose"
      >
        Cancel
      </button>
      <LoadingButton
        class="btn-danger"
        :loading="loading"
        data-id="privacy-erase-confirm"
        @click="emit('confirm')"
      >
        Erase and sign out
      </LoadingButton>
    </template>
  </HilosModal>
</template>
