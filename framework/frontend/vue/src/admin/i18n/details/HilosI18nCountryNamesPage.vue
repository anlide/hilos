<!-- HilosI18nCountryNamesPage — the names of one country
(HilosPages.I18N_COUNTRY_NAMES): how the country the address names is called in
every language of the system (HIL-1483). The card's header and tabs (shared
with the main country page) stand above the names table, with three states:
loading skeleton, card with table, and unavailable alert when deleted.
Bootstrap classes only, painted with classes that follow the theme
(styling-rules.md). -->
<script setup lang="ts">
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import {
  createHilosI18nCountryCard,
  createHilosI18nCountryNameWindow,
  createHilosI18nNamesTable,
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_COUNTRY_NAME_COPY,
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  hilosI18nCountryDisplayName,
  type HilosI18nCountryContext,
  type HilosI18nCountryNameDeleteTarget,
  type HilosI18nNameRow,
} from '@hilos/core/admin/i18n'
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
import HilosI18nCountryNameWindow from './HilosI18nCountryNameWindow.vue'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'

const props = defineProps<{ context: HilosI18nCountryContext }>()
const liveCard = createHilosI18nCountryCard(props.context)
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.I18N_COUNTRY,
  computedSignal(() => {
    const page = props.context.scopes.page()?.key
    if (
      page !== HilosPages.I18N_COUNTRY &&
      page !== HilosPages.I18N_COUNTRY_NAMES
    ) {
      return undefined
    }
    const current = liveCard.get()
    return current === null ? undefined : hilosI18nCountryDisplayName(current)
  }),
)
onUnmounted(unbindName)
const card = useSignal(liveCard)
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
  computedSignal(() =>
    liveCard.get()?.code === country.get()
      ? (liveCard.get()?.countryId ?? null)
      : null,
  ),
)
const nameWindow = createHilosI18nCountryNameWindow(
  names.controller,
  liveCard,
  country,
  props.context,
)
const nameCopy = HILOS_I18N_COUNTRY_NAME_COPY
const copy = HILOS_I18N_COUNTRY_NAME_DELETE_COPY
const deleteOpen = ref(false)
const deleteWindow = ref<InstanceType<
  typeof HilosI18nCountryNameDelete
> | null>(null)
const target = shallowRef<HilosI18nCountryNameDeleteTarget | null>(null)
const focused = useSignal(names.controller.focusedRow)
const currentCountry = useSignal(country)
const source = computed(() =>
  card.value?.countryId === target.value?.countryId &&
  currentCountry.value === target.value?.countryCode &&
  focused.value?.inLanguageId === target.value?.inLanguageId
    ? (focused.value ?? null)
    : null,
)
let opening = 0

function closeDelete(): void {
  opening++
  deleteOpen.value = false
  names.controller.releaseFocus()
}

function openName(row: HilosI18nNameRow, localeId: number | null = null): void {
  if (nameWindow.saving.get() || deleteWindow.value?.busy) return
  closeDelete()
  nameWindow.open(row.rowKey, localeId)
}

async function openDelete(
  row: HilosI18nNameRow,
  localeId?: number,
): Promise<void> {
  if (deleteOpen.value || nameWindow.saving.get()) return
  nameWindow.close()
  const attempt = ++opening
  const countryCode = country.get()
  const resolved = await names.controller.focusRow(row.rowKey)
  if (attempt !== opening) return
  const fresh = names.controller.focusedRow.get()
  const subject = card.value
  if (
    resolved === null ||
    country.get() !== countryCode ||
    subject?.code !== countryCode ||
    fresh?.inLanguageId !== row.inLanguageId ||
    fresh.name === null
  ) {
    closeDelete()
    return
  }
  const correction =
    localeId === undefined
      ? null
      : fresh.corrections.find((item) => item.localeId === localeId)
  const expectedName = localeId === undefined ? fresh.name : correction?.name
  const nameId = localeId === undefined ? fresh.nameId : correction?.nameId
  if (expectedName == null || nameId == null) {
    closeDelete()
    return
  }
  const coordinates = {
    countryId: subject.countryId,
    inLanguageId: fresh.inLanguageId,
    nameId,
    countryCode,
    countryName: subject.summary.name || subject.code.toUpperCase(),
    inLanguageCode: fresh.code,
    inLanguageNativeName: fresh.nativeName,
    expectedName,
  }
  target.value =
    localeId === undefined
      ? { ...coordinates, kind: 'base' }
      : {
          ...coordinates,
          kind: 'override',
          localeId,
          localeCode: correction!.localeCode,
        }
  deleteOpen.value = true
}

watch(currentCountry, closeDelete, { flush: 'sync' })
watch(
  deleteOpen,
  (open) => {
    if (!open) names.controller.releaseFocus()
  },
  { flush: 'sync' },
)
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
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :aria-label="
                nameCopy.open(
                  row.name === null,
                  card.summary.name ?? card.code.toUpperCase(),
                  row.nativeName,
                )
              "
              :data-id="`i18n-country-name-${row.name === null ? 'add' : 'edit'}-${row.code}`"
              @click="openName(row)"
            >
              <i
                class="bi"
                :class="row.name === null ? 'bi-plus-lg' : 'bi-pencil'"
                aria-hidden="true"
              ></i>
            </button>
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
            <button
              v-if="row.name !== null"
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :aria-label="
                nameCopy.open(
                  correction.name === null,
                  card.summary.name ?? card.code.toUpperCase(),
                  row.nativeName,
                  correction.localeCode,
                )
              "
              :data-id="`i18n-country-name-locale-${correction.name === null ? 'add' : 'edit'}-${correction.localeCode}`"
              @click="openName(row, correction.localeId)"
            >
              <i
                class="bi"
                :class="correction.name === null ? 'bi-plus-lg' : 'bi-pencil'"
                aria-hidden="true"
              ></i>
            </button>
            <LoadingButton
              v-if="correction.name !== null"
              class="btn-sm btn-outline-danger"
              :aria-label="copy.overrideLabel(correction.localeCode)"
              :data-id="`i18n-names-delete-${correction.localeCode}`"
              @click="openDelete(row, correction.localeId)"
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
      ref="deleteWindow"
      v-model="deleteOpen"
      :context="context"
      :target="target"
      :source="source"
    />
    <HilosI18nCountryNameWindow :window="nameWindow" />
  </HilosAdminPage>
</template>
