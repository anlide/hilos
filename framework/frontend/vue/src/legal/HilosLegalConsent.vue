<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import {
  hilosLegalClauseIcon,
  hilosLegalConsentDeviations,
  hilosLegalDocumentLabel,
  type HilosLegalConsentTerms,
  type HilosLegalDocumentKey,
} from '@hilos/core'
import HilosLegalRevisionText from './HilosLegalRevisionText.vue'

const props = defineProps<{
  terms: HilosLegalConsentTerms
  accepted: boolean
  reading: HilosLegalDocumentKey | null
  disabled?: boolean
}>()
const emit = defineEmits<{
  'update:accepted': [accepted: boolean]
  'update:reading': [document: HilosLegalDocumentKey | null]
}>()
const expanded = ref(false)
const checkboxId = useId()
const readingHeading = ref<HTMLHeadingElement | null>(null)
const acceptInput = ref<HTMLInputElement | null>(null)
const standardToggle = ref<HTMLButtonElement | null>(null)
const clauses = computed(() =>
  props.terms.documents.flatMap((document) => document.clauses),
)
const deviations = computed(() => hilosLegalConsentDeviations(props.terms))
const document = computed(() =>
  props.terms.documents.find((item) => item.document === props.reading),
)

defineExpose({
  focus: () => {
    if (props.reading !== null) readingHeading.value?.focus()
    else (acceptInput.value ?? standardToggle.value)?.focus()
  },
})
watch(
  () => props.reading,
  async (reading) => {
    await nextTick()
    if (reading !== null) readingHeading.value?.focus()
    else standardToggle.value?.focus()
  },
)
</script>

<template>
  <div data-id="legal-consent">
    <div v-show="!document">
      <p class="small text-body-secondary mb-3">
        This project runs on the standard Hilos terms. They are the same in
        every project built on the framework — you may have read them before.
        <template v-if="deviations.length">
          Below is only what this project does differently.</template
        >
      </p>
      <div class="border rounded mb-3">
        <button
          ref="standardToggle"
          type="button"
          class="btn w-100 d-flex align-items-center gap-2 px-3 py-2 small text-start"
          data-id="legal-consent-standard-toggle"
          :aria-expanded="expanded"
          @click="expanded = !expanded"
        >
          <i class="bi bi-shield-check text-success" aria-hidden="true" />
          <span class="flex-grow-1"
            >Standard Hilos terms · {{ clauses.length }} clauses</span
          >
          <i
            :class="expanded ? 'bi bi-chevron-up' : 'bi bi-chevron-down'"
            aria-hidden="true"
          />
        </button>
        <div v-if="expanded" class="border-top">
          <div
            v-for="clause in clauses"
            :key="clause.clauseKey"
            class="px-3 py-2 border-bottom small text-body-secondary text-break"
            data-id="legal-consent-standard-item"
          >
            {{ clause.standardStatement }}
          </div>
        </div>
      </div>
      <section
        v-if="deviations.length"
        class="border border-warning-subtle rounded mb-3"
      >
        <h3
          class="h6 d-flex align-items-center gap-2 px-3 py-2 mb-0 border-bottom bg-warning-subtle text-warning-emphasis"
        >
          <i class="bi bi-exclamation-triangle" aria-hidden="true" />
          Different in this project · {{ deviations.length }}
        </h3>
        <ul class="list-unstyled mb-0 px-3">
          <li
            v-for="clause in deviations"
            :key="clause.clauseKey"
            class="d-flex gap-2 py-2 border-bottom text-break"
            data-id="legal-consent-deviation"
            :data-clause="clause.clauseKey"
          >
            <i
              class="bi text-body-secondary mt-1"
              :class="hilosLegalClauseIcon(clause.clauseKey)"
              aria-hidden="true"
            />
            <div class="flex-grow-1">
              <div class="small fw-semibold">
                {{ clause.statement }}
                <span
                  class="badge border ms-1"
                  :class="
                    clause.direction === 'stricter'
                      ? 'text-bg-warning-subtle bg-warning-subtle text-warning-emphasis border-warning-subtle'
                      : 'text-bg-success-subtle bg-success-subtle text-success-emphasis border-success-subtle'
                  "
                  data-id="legal-consent-direction"
                  >{{ clause.direction }}</span
                >
              </div>
              <div class="small text-body-secondary">
                Hilos standard: {{ clause.standardStatement }}
              </div>
            </div>
          </li>
        </ul>
      </section>
      <div
        v-else
        class="alert alert-success small py-2 mb-3"
        data-id="legal-consent-no-deviations"
      >
        <i class="bi bi-check-circle me-1" aria-hidden="true" />
        This project does not differ from the standard — neither stricter nor
        looser. Only the standard terms need accepting.
      </div>
      <template v-if="terms.form === 'checkbox'">
        <div class="form-check mb-1">
          <input
            :id="checkboxId"
            ref="acceptInput"
            type="checkbox"
            class="form-check-input"
            data-id="auth-consent-accept"
            :checked="accepted"
            :disabled="disabled"
            @change="
              emit(
                'update:accepted',
                ($event.target as HTMLInputElement).checked,
              )
            "
          />
          <label :for="checkboxId" class="form-check-label small">
            {{
              deviations.length
                ? 'I have read the differences and accept the terms'
                : 'I accept the terms'
            }}
          </label>
        </div>
        <div class="small mb-3">
          <template
            v-for="(item, index) in terms.documents"
            :key="item.document"
          >
            <span v-if="index"> · </span>
            <button
              type="button"
              class="btn btn-link btn-sm p-0"
              data-id="legal-consent-read"
              :data-document="item.document"
              @click="emit('update:reading', item.document)"
            >
              {{ hilosLegalDocumentLabel(item.document) }}
            </button>
          </template>
        </div>
      </template>
    </div>
    <div v-if="document" data-id="legal-consent-reading">
      <h3 ref="readingHeading" tabindex="-1" class="h6">
        {{ hilosLegalDocumentLabel(document.document) }}
      </h3>
      <HilosLegalRevisionText :clauses="document.clauses" />
      <button
        type="button"
        class="btn btn-link btn-sm px-0 mb-3"
        data-id="legal-consent-back"
        @click="emit('update:reading', null)"
      >
        Back to the differences
      </button>
    </div>
  </div>
</template>
