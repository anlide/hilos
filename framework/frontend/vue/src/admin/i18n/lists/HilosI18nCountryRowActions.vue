<!-- Each table/card copy owns its description id; both copies stand in the DOM. -->
<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDeleteLabel,
  hilosI18nCountryDeleteReason,
  type HilosI18nCountryRow,
} from '@hilos/core'
import { useId } from 'vue'

const props = defineProps<{ row: HilosI18nCountryRow }>()
const emit = defineEmits<{ delete: [] }>()
const reasonId = useId()

function openDelete(): void {
  if (props.row.canDelete) emit('delete')
}
</script>

<template>
  <div class="d-flex flex-column align-items-start gap-1">
    <button
      type="button"
      class="btn btn-sm"
      :class="
        row.canDelete
          ? 'btn-outline-danger'
          : HILOS_I18N_COUNTRY_MARKS.deleteBlocked.tone
      "
      :aria-label="`Delete country ${hilosI18nCountryDeleteLabel(row)}`"
      :aria-disabled="!row.canDelete || undefined"
      :aria-describedby="!row.canDelete ? reasonId : undefined"
      :data-id="`i18n-countries-delete-${row.code}`"
      @click="openDelete"
    >
      <i class="bi bi-trash" aria-hidden="true"></i>
    </button>
    <span
      v-if="!row.canDelete"
      :id="reasonId"
      class="small text-body-secondary"
      :data-id="`i18n-countries-delete-reason-${row.code}`"
      >{{ hilosI18nCountryDeleteReason(row) }}</span
    >
  </div>
</template>
