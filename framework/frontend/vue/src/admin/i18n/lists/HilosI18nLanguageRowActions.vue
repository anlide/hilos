<!-- Each table/card copy owns its description id. The table draws the actions
slot twice, so a language-code id would join two distinct DOM nodes. -->
<script setup lang="ts">
import {
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  hilosI18nLanguageDelete,
  type HilosI18nLanguageRow,
} from '@hilos/core/admin/i18n'
import { computed, useId } from 'vue'

const props = defineProps<{ row: HilosI18nLanguageRow }>()
const emit = defineEmits<{ edit: []; delete: [] }>()
const copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
const deleteCopy = HILOS_I18N_LANGUAGE_DELETE_COPY
const verdict = computed(() => hilosI18nLanguageDelete(props.row))
const reasonId = useId()
</script>

<template>
  <span class="d-inline-flex flex-column align-items-end text-end">
    <span class="d-flex gap-1 justify-content-end">
      <button
        v-if="row.enabled"
        type="button"
        class="btn btn-sm btn-outline-secondary"
        :title="copy.open.view"
        :aria-label="copy.open.view"
        :data-id="`i18n-languages-view-${row.code}`"
        @click="emit('edit')"
      >
        <i class="bi bi-eye" aria-hidden="true"></i>
      </button>
      <button
        v-else
        type="button"
        class="btn btn-sm btn-outline-primary"
        :title="copy.open.edit"
        :aria-label="copy.open.edit"
        :data-id="`i18n-languages-edit-${row.code}`"
        @click="emit('edit')"
      >
        <i class="bi bi-pencil" aria-hidden="true"></i>
      </button>
      <button
        type="button"
        class="btn btn-sm"
        :class="
          verdict.disabled ? 'btn-outline-secondary' : 'btn-outline-danger'
        "
        :disabled="verdict.disabled"
        :aria-label="deleteCopy.rowLabel(row.nativeName)"
        :aria-describedby="verdict.reason ? reasonId : undefined"
        :data-id="`i18n-languages-delete-${row.code}`"
        @click="emit('delete')"
      >
        <i class="bi bi-trash" aria-hidden="true"></i>
      </button>
    </span>
    <span
      v-if="verdict.reason"
      :id="reasonId"
      class="small text-body-secondary text-wrap text-end mt-1 mb-0"
      :data-id="`i18n-languages-delete-reason-${row.code}`"
    >
      {{ verdict.reason }}
    </span>
  </span>
</template>
