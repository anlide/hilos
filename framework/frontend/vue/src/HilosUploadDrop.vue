<!-- HilosUploadDrop — the wrapper of a project's own content that takes files
dragged over it or pasted into it, and the voice of the upload field. The field
(@hilos/core primitives/uploadField) owns the state; this tag only feeds it the
two doors and draws what it says (multiframework-core.md, "Primitives").
A drag that carries no files — selected text, a link — is not touched at all,
and a paste without files goes on as an ordinary paste. A file drag is always
taken, even while the field is disabled, so the browser never opens the file in
place of the page; the field itself then refuses it.
The voice lives here and not on the chips: the chips come and go, and a live
region dies with its block, so the two permanent regions stand on the one
surface that outlives them (accessibility.md, "The room belongs to the block,
the voice to the surface"). -->
<script setup lang="ts">
import {
  HILOS_UPLOAD_FIELD_COPY as COPY,
  HILOS_UPLOAD_FIELD_OVERLAY_CLASS,
  type HilosUploadField,
} from '@hilos/core/base'

import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The upload field the page created; the page disposes it, not this tag. */
  field: HilosUploadField
  /** The data-id of the wrapper; the overlay and the two live regions derive theirs from it. */
  dataId: string
}>()

const dragging = useSignal(props.field.dragging)
const alert = useSignal(props.field.alert)
const status = useSignal(props.field.status)

function carriesFiles(event: DragEvent): boolean {
  return event.dataTransfer?.types.includes('Files') ?? false
}

function enter(event: DragEvent): void {
  if (!carriesFiles(event)) {
    return
  }
  event.preventDefault()
  props.field.dragEnter()
}

function over(event: DragEvent): void {
  if (carriesFiles(event)) {
    event.preventDefault()
  }
}

function leave(event: DragEvent): void {
  if (!carriesFiles(event)) {
    return
  }
  event.preventDefault()
  props.field.dragLeave()
}

function drop(event: DragEvent): void {
  if (!carriesFiles(event)) {
    return
  }
  event.preventDefault()
  props.field.drop(event.dataTransfer?.files ?? [])
}

function paste(event: ClipboardEvent): void {
  const files = event.clipboardData?.files
  if (!files || files.length === 0) {
    return
  }
  event.preventDefault()
  props.field.add(files)
}
</script>

<template>
  <div
    class="position-relative"
    :data-id="dataId"
    @dragenter="enter"
    @dragover="over"
    @dragleave="leave"
    @drop="drop"
    @paste="paste"
  >
    <slot />
    <div
      v-if="dragging"
      :class="[HILOS_UPLOAD_FIELD_OVERLAY_CLASS, 'hilos-upload-overlay']"
      :data-id="`${dataId}-overlay`"
    >
      <span class="text-primary fw-semibold"
        ><i class="bi bi-paperclip me-1" aria-hidden="true"></i
        >{{ COPY.overlay }}</span
      >
    </div>
    <div
      class="visually-hidden"
      role="alert"
      aria-live="assertive"
      :data-id="`${dataId}-alert`"
    >
      {{ alert }}
    </div>
    <div
      class="visually-hidden"
      role="status"
      aria-live="polite"
      :data-id="`${dataId}-status`"
    >
      {{ status }}
    </div>
  </div>
</template>
