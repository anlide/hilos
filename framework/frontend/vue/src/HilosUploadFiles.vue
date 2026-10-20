<!-- HilosUploadFiles — the chips of the upload field's files and the line of its
last refusal above them. With no files it draws nothing and takes no room; from
the first file to the last the refusal's room is held by HilosFormError's
invisible twin, so a refusal moves nothing (styling-rules.md, "The room a live
message takes"). The line carries no voice: HilosUploadDrop says it aloud, and
an announcement here would be heard twice.
A chip's reading lives on data-state, its icon and tone show it, and a hidden
word says it — an icon and a color reach no ear. -->
<script setup lang="ts">
import {
  HILOS_UPLOAD_FIELD_CHIP_CALM_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_CLASS,
  HILOS_UPLOAD_FIELD_CHIP_FAILED_CLASS,
  HILOS_UPLOAD_FIELD_COPY as COPY,
  type HilosUploadField,
  type HilosUploadFieldFile,
} from '@hilos/core/base'

import HilosFormError from './HilosFormError.vue'
import { useSignal } from './useSignal.js'

const props = defineProps<{
  /** The upload field the page created; the page disposes it, not this tag. */
  field: HilosUploadField
  /** The data-id of the block; the refusal line and the chips derive theirs from it. */
  dataId: string
}>()

const files = useSignal(props.field.files)
const refusal = useSignal(props.field.refusal)
const disabled = useSignal(props.field.disabled)

function chipClass(file: HilosUploadFieldFile): string[] {
  return [
    HILOS_UPLOAD_FIELD_CHIP_CLASS,
    file.reading === 'failed'
      ? HILOS_UPLOAD_FIELD_CHIP_FAILED_CLASS
      : HILOS_UPLOAD_FIELD_CHIP_CALM_CLASS,
    file.canceling ? HILOS_UPLOAD_FIELD_CHIP_CANCELING_CLASS : '',
  ]
}
</script>

<template>
  <div v-if="files.length > 0" class="mb-2" :data-id="dataId">
    <HilosFormError :message="refusal" :data-id="`${dataId}-error`" />
    <div class="d-flex flex-wrap gap-2">
      <span
        v-for="file in files"
        :key="file.clientUploadId"
        :class="chipClass(file)"
        :data-id="`${dataId}-file`"
        :data-state="file.reading"
      >
        <template v-if="file.reading === 'waiting'">
          <i class="bi bi-clock" aria-hidden="true"></i>
          <span class="visually-hidden">{{ COPY.waiting }}</span>
        </template>
        <template v-else-if="file.reading === 'uploading'">
          <span class="visually-hidden">{{ COPY.uploading }}</span>
          <span class="hilos-upload-percent d-inline-block text-end"
            >{{ file.percent }}%</span
          >
        </template>
        <i
          v-else-if="file.reading === 'received'"
          class="bi bi-paperclip"
          aria-hidden="true"
        ></i>
        <template v-else>
          <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
          <span class="visually-hidden">{{ COPY.failed }}</span>
        </template>
        <span
          class="text-truncate hilos-upload-name"
          :title="file.filename"
          :data-id="`${dataId}-file-name`"
          >{{ file.filename }}</span
        >
        <button
          type="button"
          class="btn-close hilos-upload-remove"
          :aria-label="`${COPY.remove} ${file.filename}`"
          :disabled="disabled || file.canceling"
          :data-id="`${dataId}-file-remove`"
          @click="field.remove(file.clientUploadId)"
        ></button>
      </span>
    </div>
  </div>
</template>
