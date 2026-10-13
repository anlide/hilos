<!-- HilosI18nCountryNamesPage — the names of one country
(HilosPages.I18N_COUNTRY_NAMES): how the country the address names is called in
every language of the system (HIL-1483). The card's header and tabs (shared
with the main country page) stand above the names table, with three states:
loading skeleton, card with table, and unavailable alert when deleted.
Bootstrap classes only, painted with classes that follow the theme
(styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosI18nCountryCard,
  createHilosI18nNamesTable,
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  HilosPages,
  type HilosI18nCountryContext,
  type HilosI18nCountryNameDeleteTarget,
  type HilosI18nNameRow,
} from '@hilos/core'
import {
  computed,
  inject,
  onMounted,
  onUnmounted,
  ref,
  shallowRef,
  watch,
} from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import LoadingButton from '../../../LoadingButton.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nCountryHeader from './HilosI18nCountryHeader.vue'
import HilosI18nCountryNameDelete from './HilosI18nCountryNameDelete.vue'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'

const props = defineProps<{ context: HilosI18nCountryContext }>()
const card = useSignal(createHilosI18nCountryCard(props.context))
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosI18nCountryNamesPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const loading = useSignal(router.pageLoading)
const country = computedSignal(
  () =>
    (router.currentRoute.get().params.countryCode as string | undefined) ?? '',
)
const names = createHilosI18nNamesTable(
  props.context,
  HILOS_I18N_COUNTRY_NAMES,
  country,
)
const copy = HILOS_I18N_COUNTRY_NAME_DELETE_COPY
const deleteOpen = ref(false)
const target = shallowRef<HilosI18nCountryNameDeleteTarget | null>(null)
const focused = useSignal(names.controller.focusedRow)
const currentCountry = useSignal(country)
const source = computed(() =>
  card.value?.code === target.value?.countryCode &&
  currentCountry.value === target.value?.countryCode &&
  focused.value?.code === target.value?.inLanguageCode
    ? (focused.value ?? null)
    : null,
)
let opening = 0

function closeDelete(): void {
  opening++
  deleteOpen.value = false
  names.controller.releaseFocus()
}

async function openDelete(
  row: HilosI18nNameRow,
  localeCode?: string,
): Promise<void> {
  if (deleteOpen.value) return
  const attempt = ++opening
  const countryCode = country.get()
  const resolved = await names.controller.focusRow(row.code)
  if (attempt !== opening) return
  const fresh = names.controller.focusedRow.get()
  const subject = card.value
  if (
    resolved === null ||
    country.get() !== countryCode ||
    subject?.code !== countryCode ||
    fresh?.code !== row.code ||
    fresh.name === null
  ) {
    closeDelete()
    return
  }
  const expectedName =
    localeCode === undefined
      ? fresh.name
      : fresh.corrections.find((item) => item.localeCode === localeCode)?.name
  if (expectedName == null) {
    closeDelete()
    return
  }
  const coordinates = {
    countryCode,
    countryName: subject.summary.name || subject.code.toUpperCase(),
    inLanguageCode: fresh.code,
    inLanguageNativeName: fresh.nativeName,
    expectedName,
  }
  target.value =
    localeCode === undefined
      ? { ...coordinates, kind: 'base' }
      : { ...coordinates, kind: 'override', localeCode }
  deleteOpen.value = true
}

watch(currentCountry, closeDelete, { flush: 'sync' })
watch(deleteOpen, (open) => {
  if (!open) names.controller.releaseFocus()
})
onMounted(() => names.start())
onUnmounted(() => {
  closeDelete()
  names.dispose()
})
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_COUNTRY_NAMES">
    <template v-if="card">
      <HilosI18nCountryHeader
        :card="card"
        :active-page="HilosPages.I18N_COUNTRY_NAMES"
      />
      <section class="mb-4" data-id="country-names">
        <h2 class="h5 d-flex flex-wrap align-items-center gap-2">
          Names in different languages
          <span
            class="badge bg-body-tertiary text-body-secondary border fw-normal"
            >A row for every language</span
          >
        </h2>
        <HilosI18nNamesTable :controller="names.controller">
          <template #name-actions="{ row }">
            <LoadingButton
              v-if="row.name !== null"
              class="btn-sm btn-outline-danger"
              :aria-label="copy.baseLabel(row.nativeName)"
              :data-id="`i18n-names-delete-${row.code}`"
              @click="openDelete(row)"
              ><i class="bi bi-trash" aria-hidden="true"></i
            ></LoadingButton>
          </template>
          <template #correction-actions="{ row, correction }">
            <LoadingButton
              v-if="correction.name !== null"
              class="btn-sm btn-outline-danger"
              :aria-label="copy.overrideLabel(correction.localeCode)"
              :data-id="`i18n-names-delete-${correction.localeCode}`"
              @click="openDelete(row, correction.localeCode)"
              ><i class="bi bi-trash" aria-hidden="true"></i
            ></LoadingButton>
          </template>
        </HilosI18nNamesTable>
      </section>
    </template>
    <div
      v-else-if="loading"
      class="placeholder-glow"
      role="status"
      aria-label="Loading country"
    >
      <span class="placeholder col-6 d-block mb-2 rounded"></span>
      <span class="placeholder col-4 d-block rounded"></span>
    </div>
    <p
      v-else
      class="alert alert-secondary"
      role="status"
      data-id="country-names-unavailable"
    >
      Country details are no longer available.
    </p>
    <HilosI18nCountryNameDelete
      :key="currentCountry"
      v-model="deleteOpen"
      :context="context"
      :target="target"
      :source="source"
    />
  </HilosAdminPage>
</template>
