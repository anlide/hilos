<!-- HilosUploadPicker — the paperclip door of the upload field: a button that
opens the browser's file chooser over a hidden input. The chosen files go to the
field, and the input is emptied at once, or the same file could not be chosen
twice in a row. The accept filter is a hint to the chooser only: a drop or a
paste passes it by, and the server decides. -->
<script setup lang="ts">
import {
  HILOS_UPLOAD_FIELD_COPY as COPY,
  type HilosUploadField,
} from '@hilos/core'
import { ref } from 'vue'

import { useSignal } from './useSignal.js'

const props = withDefaults(
  defineProps<{
    /** The upload field the page created; the page disposes it, not this tag. */
    field: HilosUploadField
    /** The data-id of the button; the hidden input derives its own from it. */
    dataId: string
    /** The chooser's file filter, as the input's accept attribute; empty takes any file. */
    accept?: string
  }>(),
  { accept: '' },
)

const disabled = useSignal(props.field.disabled)
const input = ref<HTMLInputElement | null>(null)

function choose(): void {
  input.value?.click()
}

function picked(event: Event): void {
  const target = event.target as HTMLInputElement
  props.field.add(target.files ?? [])
  target.value = ''
}
</script>

<template>
  <button
    type="button"
    class="btn btn-outline-secondary flex-shrink-0"
    :aria-label="COPY.attach"
    :disabled="disabled"
    :data-id="dataId"
    @click="choose"
  >
    <i class="bi bi-paperclip" aria-hidden="true"></i>
  </button>
  <input
    ref="input"
    type="file"
    multiple
    class="d-none"
    :accept="accept"
    :data-id="`${dataId}-input`"
    @change="picked"
  />
</template>
