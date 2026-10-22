<!-- The shared public Terms/Privacy reader line; all verdicts and words come from core. -->
<script setup lang="ts">
import { computed } from 'vue'
import type {
  HilosLegalPublicDocumentReader,
  HilosLegalPublicDocumentCopy,
} from '@hilos/core/base'
import HilosFormError from '../HilosFormError.vue'
import LoadingButton from '../LoadingButton.vue'

const props = defineProps<{
  line: HilosLegalPublicDocumentReader
  copy: HilosLegalPublicDocumentCopy
  accepting: boolean
  refusal: string | null
}>()
const emit = defineEmits<{ accept: []; compare: [] }>()
const green = computed(
  () => props.line.state === 'covered' || props.line.state === 'reworded',
)
</script>

<template>
  <div v-if="line.state === 'due'" class="alert alert-warning mb-0">
    <p class="mb-2" data-id="legal-document-reader-line">
      <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
      {{ line.line }}
    </p>
    <div
      v-if="line.plate !== null"
      class="d-flex flex-wrap align-items-center gap-2 rounded px-3 py-2 mb-2 small"
      :class="`bg-${line.plate.tone}-subtle text-${line.plate.tone}-emphasis`"
      data-id="legal-document-reader-plate"
      :data-tone="line.plate.tone"
    >
      <i class="bi" :class="line.plate.icon" aria-hidden="true"></i>
      <strong>{{ line.plate.text }}</strong>
      <span v-if="line.plate.detail !== null">{{ line.plate.detail }}</span>
    </div>
    <p
      v-if="line.onlyPerson !== null"
      class="small mb-2"
      data-id="legal-document-reader-impersonated"
    >
      {{ line.onlyPerson }}
    </p>
    <div class="d-flex flex-wrap gap-2">
      <LoadingButton
        v-if="line.canAccept"
        class="btn-sm btn-primary"
        data-id="legal-document-accept"
        :loading="accepting"
        @click="emit('accept')"
      >
        {{ copy.accept }}
      </LoadingButton>
      <button
        v-if="line.canCompare"
        type="button"
        class="btn btn-sm btn-outline-secondary"
        data-id="legal-document-changes-open"
        @click="emit('compare')"
      >
        {{ copy.whatChanged }}
      </button>
    </div>
    <HilosFormError
      v-if="line.canAccept"
      :message="refusal"
      data-id="legal-document-accept-refusal"
      announce
    />
  </div>
  <div v-else class="d-flex flex-wrap align-items-center gap-2">
    <p
      class="mb-0"
      :class="{
        invisible: line.state === 'loading',
      }"
      data-id="legal-document-reader-line"
      :aria-hidden="line.state === 'loading'"
    >
      <i
        v-if="green"
        class="bi bi-check-circle-fill text-success me-1"
        aria-hidden="true"
      ></i>
      {{ line.line }}
    </p>
    <button
      v-if="line.canCompare"
      type="button"
      class="btn btn-sm btn-outline-secondary"
      data-id="legal-document-changes-open"
      @click="emit('compare')"
    >
      {{ copy.whatChanged }}
    </button>
  </div>
</template>
