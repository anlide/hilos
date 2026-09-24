<!-- HilosBackupCodes — a set of backup codes of the second factor shown once,
the way the design asks (HIL-494, Design п.6): the list, a download of it as a
text file, a copy of it, and "I have saved these codes", which the screen under
it waits for. The codes are the only instant way back in when the app is lost, so
the screen pushes the person to keep them rather than flash them past. -->
<script setup lang="ts">
import {
  copyToClipboard,
  downloadTextFile,
  isClipboardAvailable,
} from '@hilos/core'
import { ref, useId } from 'vue'

const props = defineProps<{
  /** The codes, in display form. */
  codes: readonly string[]
  /** Whether "I have saved these codes" is ticked. */
  saved: boolean
}>()

const emit = defineEmits<{
  /** The checkbox moved. */
  'update:saved': [saved: boolean]
}>()

/** The name the downloaded list is offered under. */
const FILE_NAME = 'backup-codes.txt'

/** The checkbox's id, unique per instance so its label finds it. */
const savedId = useId()

const canCopy = isClipboardAvailable()
const copied = ref(false)

/** The list as a file and as the clipboard hold it: one code a line. */
function asText(): string {
  return `${props.codes.join('\n')}\n`
}

function download(): void {
  downloadTextFile(FILE_NAME, asText(), 'text/plain;charset=utf-8')
}

async function copy(): Promise<void> {
  copied.value = await copyToClipboard(asText())
}

/**
 * Mirror the checkbox into the owner.
 *
 * @param event The change event.
 */
function updateSaved(event: Event): void {
  emit('update:saved', (event.target as HTMLInputElement).checked)
}
</script>

<template>
  <div data-id="backup-codes">
    <ul
      class="list-unstyled row row-cols-2 g-2 font-monospace mb-3"
      data-id="backup-codes-list"
    >
      <li v-for="code in codes" :key="code" class="col text-center">
        <span class="d-block border rounded py-1">{{ code }}</span>
      </li>
    </ul>
    <div class="d-flex gap-2 mb-3">
      <button
        type="button"
        class="btn btn-outline-secondary btn-sm flex-fill"
        data-id="backup-codes-download"
        @click="download()"
      >
        <i class="bi bi-download me-1" aria-hidden="true" />
        Download
      </button>
      <button
        v-if="canCopy"
        type="button"
        class="btn btn-outline-secondary btn-sm flex-fill"
        data-id="backup-codes-copy"
        @click="copy()"
      >
        <i
          class="bi me-1"
          :class="copied ? 'bi-check2' : 'bi-clipboard'"
          aria-hidden="true"
        />
        {{ copied ? 'Copied' : 'Copy' }}
      </button>
    </div>
    <div class="form-check mb-3">
      <input
        :id="savedId"
        class="form-check-input"
        type="checkbox"
        data-id="backup-codes-saved"
        :checked="saved"
        @change="updateSaved($event)"
      />
      <label class="form-check-label small" :for="savedId"
        >I have saved these codes</label
      >
    </div>
  </div>
</template>
