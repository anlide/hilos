<!-- Each table/card copy owns its description id; both copies stand in the DOM.
The controls stand in the page's button group beside the row's edit control, so
the root is the controls themselves rather than an element of their own. -->
<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDeleteLabel,
  hilosI18nCountryRowDeleteReason,
  type HilosI18nCountryRow,
} from '@hilos/core/admin/i18n'
import { computed, useId } from 'vue'

import HilosActionReason from '../../../HilosActionReason.vue'

const props = defineProps<{ row: HilosI18nCountryRow }>()
const emit = defineEmits<{ delete: [] }>()
const reason = computed(() => hilosI18nCountryRowDeleteReason(props.row))
const reasonId = useId()

function openDelete(): void {
  if (props.row.canDelete) emit('delete')
}
</script>

<template>
  <HilosActionReason :reason="reason" />
  <button
    type="button"
    class="btn btn-sm"
    :class="
      row.canDelete
        ? 'btn-outline-danger'
        : HILOS_I18N_COUNTRY_MARKS.deleteBlocked.tone
    "
    :disabled="!row.canDelete"
    :aria-label="`Delete country ${hilosI18nCountryDeleteLabel(row)}`"
    :aria-describedby="reason ? reasonId : undefined"
    :data-id="`i18n-countries-delete-${row.code}`"
    @click="openDelete"
  >
    <i class="bi bi-trash" aria-hidden="true"></i>
  </button>
  <span
    v-if="reason"
    :id="reasonId"
    class="visually-hidden"
    :data-id="`i18n-countries-delete-reason-${row.code}`"
  >
    {{ reason }}
  </span>
</template>
