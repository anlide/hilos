<!-- HilosI18nLanguageLocalesPage — the locales of one language
(HilosPages.I18N_LANGUAGE_LOCALES): a row for the language alone and one for every
country of the system, whether the pair has a locale or not (HIL-1476). The table
is opened on the language the address names — its code travels as the table's
filter, and moving to another language on the same page asks for that language's
window. A row shows the country by its code in capitals and its base name in the
card's language, "— no country" on the language's own row; the locale's code or
the "No locale" plate — a plate, not a dash, because a dash reads like a value; and
the locale's switch as a disabled tick. A switched-off locale's text is muted with
the secondary text color, which keeps its contrast, never with opacity. HIL-1491
adds the row's add/edit/view button and one locale window; the card's header and tabs (shared
with the main language page and language names page) stand above the locales table,
with three states matching the main page. Bootstrap classes only, painted with classes
that follow the theme (styling-rules.md). -->
<script setup lang="ts">
import { computedSignal, HilosPages } from '@hilos/core/base'
import {
  createHilosI18nLanguageCard,
  createHilosI18nLanguageLocalesTable,
  createHilosI18nLocaleDelete,
  createHilosI18nLocaleTemplates,
  hilosI18nLocaleCountryLabel,
  HILOS_I18N_LOCALE_COPY,
  type HilosI18nLanguageContext,
  type HilosI18nLocaleDeleteTarget,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleWindowMode,
} from '@hilos/core/admin/i18n'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import HilosViewportTable from '../../../HilosViewportTable.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nLanguageHeader from './HilosI18nLanguageHeader.vue'
import HilosI18nLocaleDelete from './HilosI18nLocaleDelete.vue'
import HilosI18nLocaleWindow from './HilosI18nLocaleWindow.vue'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const liveCard = createHilosI18nLanguageCard(props.context)
const card = useSignal(liveCard)
const templates = useSignal(createHilosI18nLocaleTemplates(props.context))
const opened = ref<{ rowKey: string; mode: HilosI18nLocaleWindowMode } | null>(
  null,
)
const deleteTarget = ref<HilosI18nLocaleDeleteTarget | null>(null)
const { deleteLocale } = createHilosI18nLocaleDelete(props.context)
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosI18nLanguageLocalesPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const loading = useSignal(router.pageLoading)
const language = computedSignal(
  () =>
    (router.currentRoute.get().params.languageCode as string | undefined) ?? '',
)
const locales = createHilosI18nLanguageLocalesTable(
  props.context,
  computedSignal(() =>
    liveCard.get()?.code === language.get()
      ? (liveCard.get()?.languageId ?? null)
      : null,
  ),
)
const focusedRow = useSignal(locales.controller.focusedRow)
const deleteSource = computed<HilosI18nLocaleDeleteTarget | null>(() => {
  const row = focusedRow.value
  if (
    deleteTarget.value === null ||
    card.value?.languageId !== deleteTarget.value.languageId ||
    row?.localeId !== deleteTarget.value.localeId ||
    row.localeCode === null ||
    row.formats === null
  ) {
    return null
  }
  return {
    languageId: row.languageId,
    countryId: row.countryId,
    localeId: row.localeId!,
    languageCode: card.value.code,
    countryCode: row.countryCode,
    localeCode: row.localeCode,
    nativeName: card.value.nativeName,
    countryLabel: hilosI18nLocaleCountryLabel(row),
  }
})
onMounted(() => locales.start())
onUnmounted(() => {
  locales.controller.releaseFocus()
  locales.dispose()
})

function pairCode(row: HilosI18nLocaleRow): string {
  return (
    row.localeCode ??
    `${card.value?.code ?? ''}${row.countryCode === null ? '' : '-' + row.countryCode.toUpperCase()}`
  )
}

/** A plate standing where there is no value of its own, in both themes. */
const PLATE = 'badge bg-body-tertiary text-body-secondary border fw-normal'

/** Whether the row's locale exists and is switched off, which mutes its text. */
function switchedOff(row: HilosI18nLocaleRow): boolean {
  return row.enabled === false
}

function openWindow(
  row: HilosI18nLocaleRow,
  mode: HilosI18nLocaleWindowMode,
): void {
  if (opened.value !== null || deleteTarget.value !== null) return
  opened.value = { rowKey: row.rowKey, mode }
}

function openDelete(row: HilosI18nLocaleRow): void {
  if (opened.value !== null || deleteTarget.value !== null) return
  const fresh = locales.controller.focusRow(row.rowKey)
  if (
    !fresh ||
    fresh.localeCode === null ||
    fresh.formats === null ||
    card.value?.code !== language.get()
  ) {
    locales.controller.releaseFocus()
    return
  }
  deleteTarget.value = {
    languageId: fresh.languageId,
    countryId: fresh.countryId,
    localeId: fresh.localeId!,
    languageCode: card.value.code,
    countryCode: fresh.countryCode,
    localeCode: fresh.localeCode,
    nativeName: card.value.nativeName,
    countryLabel: hilosI18nLocaleCountryLabel(fresh),
  }
}

function closeDelete(open: boolean): void {
  if (open) return
  deleteTarget.value = null
  locales.controller.releaseFocus()
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGE_LOCALES">
    <template v-if="card">
      <HilosI18nLanguageHeader
        :card="card"
        :active-page="HilosPages.I18N_LANGUAGE_LOCALES"
      />
      <HilosViewportTable :controller="locales.controller">
        <template #cell-countryCode="{ row }">
          <span
            v-if="row.countryCode === null"
            class="fst-italic text-body-secondary"
            :data-id="`i18n-locales-country-${pairCode(row)}`"
            >— no country</span
          >
          <span
            v-else
            :class="{ 'text-body-secondary': switchedOff(row) }"
            :data-id="`i18n-locales-country-${pairCode(row)}`"
            ><span class="text-uppercase text-body-secondary me-1">{{
              row.countryCode
            }}</span
            >{{ row.countryName }}</span
          >
        </template>
        <template #cell-localeCode="{ row }">
          <span
            v-if="row.localeCode === null"
            :class="PLATE"
            :data-id="`i18n-locales-none-${pairCode(row)}`"
            ><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>No
            locale</span
          >
          <span
            v-else
            :class="{ 'text-body-secondary': switchedOff(row) }"
            :data-id="`i18n-locales-code-${pairCode(row)}`"
            >{{ row.localeCode }}</span
          >
        </template>
        <template #cell-enabled="{ row }">
          <input
            v-if="row.localeCode !== null"
            class="form-check-input"
            type="checkbox"
            disabled
            :checked="row.enabled === true"
            aria-label="Enabled"
            :data-id="`i18n-locales-enabled-${pairCode(row)}`"
          />
        </template>
        <template #cell-actions="{ row }">
          <div class="hilos-button-row">
            <button
              v-if="row.localeCode === null"
              type="button"
              class="btn btn-sm btn-primary"
              :title="HILOS_I18N_LOCALE_COPY.open.add"
              :aria-label="HILOS_I18N_LOCALE_COPY.open.add"
              :data-id="`i18n-locales-add-${pairCode(row)}`"
              @click="openWindow(row, 'add')"
            >
              <i class="bi bi-plus-lg" aria-hidden="true"></i>
            </button>
            <button
              v-else-if="row.enabled === false"
              type="button"
              class="btn btn-sm btn-outline-primary"
              :title="HILOS_I18N_LOCALE_COPY.open.edit"
              :aria-label="HILOS_I18N_LOCALE_COPY.open.edit"
              :data-id="`i18n-locales-edit-${pairCode(row)}`"
              @click="openWindow(row, 'edit')"
            >
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <button
              v-else
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :title="HILOS_I18N_LOCALE_COPY.open.view"
              :aria-label="HILOS_I18N_LOCALE_COPY.open.view"
              :data-id="`i18n-locales-view-${pairCode(row)}`"
              @click="openWindow(row, 'view')"
            >
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
            <button
              v-if="row.localeCode !== null"
              type="button"
              class="btn btn-sm btn-outline-danger"
              :title="`Delete locale ${row.localeCode}`"
              :aria-label="`Delete locale ${row.localeCode}`"
              :data-id="`i18n-locales-delete-${pairCode(row)}`"
              @click="openDelete(row)"
            >
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </div>
        </template>
      </HilosViewportTable>
    </template>
    <div
      v-else-if="loading"
      class="placeholder-glow"
      role="status"
      aria-label="Loading language"
    >
      <span class="placeholder col-6 d-block mb-2 rounded"></span>
      <span class="placeholder col-4 d-block rounded"></span>
    </div>
    <p
      v-else
      class="alert alert-secondary"
      role="status"
      data-id="language-card-unavailable"
    >
      Language details are no longer available.
    </p>
    <HilosI18nLocaleWindow
      :context="context"
      :controller="locales.controller"
      :card="card"
      :templates="templates"
      :opened="opened"
      @close="opened = null"
    />
    <HilosI18nLocaleDelete
      :model-value="deleteTarget !== null"
      :target="deleteTarget"
      :source="deleteSource"
      :delete-locale="deleteLocale"
      @update:model-value="closeDelete"
    />
  </HilosAdminPage>
</template>
