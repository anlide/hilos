<!-- HilosI18nLanguageNamesPage — the names of one language
(HilosPages.I18N_LANGUAGE_NAMES): how the language the address names is called in
every other language of the system (HIL-1477). The card's header and tabs (shared
with the main language page and locales page) stand above the names table, with
three states matching the main page: loading skeleton, card with table, and
unavailable alert when deleted (HIL-1479). Bootstrap classes only, painted with
classes that follow the theme (styling-rules.md). -->
<script setup lang="ts">
import {
  computedSignal,
  createHilosI18nLanguageCard,
  createHilosI18nNamesTable,
  HILOS_I18N_LANGUAGE_NAMES,
  HILOS_I18N_LANGUAGE_NAME_DELETE_COPY,
  HilosPages,
  type HilosI18nLanguageContext,
  type HilosI18nLanguageNameDeleteTarget,
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
import HilosI18nLanguageHeader from './HilosI18nLanguageHeader.vue'
import HilosI18nLanguageNameDelete from './HilosI18nLanguageNameDelete.vue'
import HilosI18nNamesTable from './HilosI18nNamesTable.vue'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const card = useSignal(createHilosI18nLanguageCard(props.context))
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error(
    'HilosI18nLanguageNamesPage requires a provided router: app.provide(hilosRouterKey, router).',
  )
}
const loading = useSignal(router.pageLoading)
const language = computedSignal(
  () =>
    (router.currentRoute.get().params.languageCode as string | undefined) ?? '',
)
const names = createHilosI18nNamesTable(
  props.context,
  HILOS_I18N_LANGUAGE_NAMES,
  language,
)
const copy = HILOS_I18N_LANGUAGE_NAME_DELETE_COPY
const deleteOpen = ref(false)
const target = shallowRef<HilosI18nLanguageNameDeleteTarget | null>(null)
const focused = useSignal(names.controller.focusedRow)
const currentLanguage = useSignal(language)
const source = computed(() =>
  card.value?.code === target.value?.languageCode &&
  currentLanguage.value === target.value?.languageCode &&
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
  const languageCode = language.get()
  const resolved = await names.controller.focusRow(row.code)
  if (attempt !== opening) return
  const fresh = names.controller.focusedRow.get()
  const subject = card.value
  if (
    resolved === null ||
    language.get() !== languageCode ||
    subject?.code !== languageCode ||
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
    languageCode,
    languageNativeName: subject.nativeName,
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

watch(currentLanguage, closeDelete, { flush: 'sync' })
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
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGE_NAMES">
    <template v-if="card">
      <HilosI18nLanguageHeader
        :card="card"
        :active-page="HilosPages.I18N_LANGUAGE_NAMES"
      />
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
    <HilosI18nLanguageNameDelete
      :key="currentLanguage"
      v-model="deleteOpen"
      :context="context"
      :target="target"
      :source="source"
    />
  </HilosAdminPage>
</template>
