<script setup lang="ts">
import {
  HILOS_I18N_COUNTRY_ADD_COPY,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  type HilosI18nCountryLocaleOption,
} from '@hilos/core/admin/i18n'

defineProps<{
  idPrefix: string
  mode: 'add' | 'edit' | 'view'
  code: string
  currencySymbol: string
  currencyCode: string
  defaultLocaleId?: number | null
  localeOptions?: readonly HilosI18nCountryLocaleOption[]
  localeHint?: string
  describedBy?: string
}>()
const emit = defineEmits<{
  'update:code': [value: string]
  'update:currencySymbol': [value: string]
  'update:currencyCode': [value: string]
  'update:defaultLocaleId': [value: string | null]
}>()
const copy = HILOS_I18N_COUNTRY_ADD_COPY
const windowCopy = HILOS_I18N_COUNTRY_WINDOW_COPY

function changeCode(event: Event): void {
  if (event.target instanceof HTMLInputElement)
    emit('update:code', event.target.value)
}
function changeCurrencySymbol(event: Event): void {
  if (event.target instanceof HTMLInputElement)
    emit('update:currencySymbol', event.target.value)
}
function changeCurrencyCode(event: Event): void {
  if (event.target instanceof HTMLInputElement)
    emit('update:currencyCode', event.target.value)
}
function changeDefaultLocale(event: Event): void {
  if (event.target instanceof HTMLSelectElement)
    emit('update:defaultLocaleId', event.target.value || null)
}
</script>

<template>
  <div class="row g-2">
    <div class="col-12 col-sm-4">
      <label class="form-label small fw-semibold" :for="`${idPrefix}-code`">
        {{ copy.codeLabel }}
      </label>
      <input
        :id="`${idPrefix}-code`"
        class="form-control form-control-sm"
        :value="code"
        :placeholder="copy.codePlaceholder"
        maxlength="2"
        :aria-describedby="
          mode === 'view' ? describedBy : `${idPrefix}-code-hint`
        "
        :data-id="`${idPrefix}-code`"
        :disabled="mode !== 'add'"
        :data-autofocus="mode === 'add' ? '' : undefined"
        @input="changeCode"
      />
      <div
        v-if="mode !== 'view'"
        :id="`${idPrefix}-code-hint`"
        :data-id="`${idPrefix}-code-hint`"
        class="form-text"
      >
        <i
          v-if="mode === 'edit'"
          class="bi bi-database-check me-1"
          aria-hidden="true"
        ></i>
        {{ mode === 'add' ? copy.codeHint : windowCopy.codeHint }}
      </div>
    </div>
    <div class="col-12 col-sm-4">
      <label
        class="form-label small fw-semibold"
        :for="`${idPrefix}-currency-symbol`"
      >
        {{ copy.currencySymbolLabel }}
      </label>
      <input
        :id="`${idPrefix}-currency-symbol`"
        class="form-control form-control-sm"
        :value="currencySymbol"
        :placeholder="copy.currencySymbolPlaceholder"
        maxlength="8"
        :aria-describedby="
          mode === 'view' ? describedBy : `${idPrefix}-currency-symbol-hint`
        "
        :data-id="`${idPrefix}-currency-symbol`"
        :disabled="mode === 'view'"
        :data-autofocus="mode === 'edit' ? '' : undefined"
        @input="changeCurrencySymbol"
      />
      <div
        v-if="mode !== 'view'"
        :id="`${idPrefix}-currency-symbol-hint`"
        class="form-text"
      >
        {{ copy.currencySymbolHint }}
      </div>
    </div>
    <div class="col-12 col-sm-4">
      <label
        class="form-label small fw-semibold"
        :for="`${idPrefix}-currency-code`"
      >
        {{ copy.currencyCodeLabel }}
      </label>
      <input
        :id="`${idPrefix}-currency-code`"
        class="form-control form-control-sm"
        :value="currencyCode"
        :placeholder="copy.currencyCodePlaceholder"
        maxlength="3"
        :aria-describedby="
          mode === 'view' ? describedBy : `${idPrefix}-currency-code-hint`
        "
        :data-id="`${idPrefix}-currency-code`"
        :disabled="mode === 'view'"
        @input="changeCurrencyCode"
      />
      <div
        v-if="mode !== 'view'"
        :id="`${idPrefix}-currency-code-hint`"
        class="form-text"
      >
        {{ copy.currencyCodeHint }}
      </div>
    </div>
    <div class="col-12">
      <label
        class="form-label small fw-semibold"
        :for="`${idPrefix}-default-locale`"
      >
        {{ copy.defaultLocaleLabel }}
      </label>
      <select
        :id="`${idPrefix}-default-locale`"
        class="form-select form-select-sm"
        :disabled="mode !== 'edit'"
        :value="defaultLocaleId == null ? '' : String(defaultLocaleId)"
        :aria-describedby="
          mode === 'view' ? describedBy : `${idPrefix}-default-locale-hint`
        "
        :data-id="`${idPrefix}-default-locale`"
        @change="changeDefaultLocale"
      >
        <option v-if="mode === 'add'" value="">
          {{ copy.defaultLocaleChoice }}
        </option>
        <template v-else>
          <option
            v-for="option in localeOptions"
            :key="option.value"
            :value="option.value"
          >
            {{ option.label }}
          </option>
        </template>
      </select>
      <div
        v-if="mode === 'add'"
        :id="`${idPrefix}-default-locale-hint`"
        class="form-text"
        :data-id="`${idPrefix}-default-locale-hint`"
      >
        <i class="bi bi-database-check me-1" aria-hidden="true"></i>
        {{ copy.defaultLocaleHint }}
      </div>
      <div v-else-if="mode === 'edit'" class="form-text hilos-stack">
        <div class="hilos-stack invisible" aria-hidden="true" inert>
          <span v-for="hint in windowCopy.hints" :key="hint">{{ hint }}</span>
        </div>
        <div
          :id="`${idPrefix}-default-locale-hint`"
          :data-id="`${idPrefix}-default-locale-hint`"
        >
          {{ localeHint }}
        </div>
      </div>
    </div>
  </div>
</template>
