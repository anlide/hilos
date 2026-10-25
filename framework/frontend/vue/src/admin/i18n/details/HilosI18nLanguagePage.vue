<!-- The language main card and its switch controls share one page-scoped
browser datum for both the initial view and live updates. -->
<script setup lang="ts">
import {
  createHilosI18nLanguageCard,
  HILOS_I18N_LANGUAGE_MARKS,
  createHilosI18nLanguageCardWindow,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  hilosI18nLanguageDelete,
  type HilosI18nLanguageContext,
} from '@hilos/core/admin/i18n'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
  hilosTabLinks,
} from '@hilos/core/base'
import { computed, inject, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import HilosLink from '../../../HilosLink.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nLanguageDelete from './HilosI18nLanguageDelete.vue'
import HilosI18nLanguageSwitchOff from './HilosI18nLanguageSwitchOff.vue'
import HilosI18nLanguageSwitchOn from './HilosI18nLanguageSwitchOn.vue'
import HilosI18nLanguageWindow from './HilosI18nLanguageWindow.vue'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const cardSignal = createHilosI18nLanguageCard(props.context)
const unbindName = bindPageInstanceName(
  props.context.scopes,
  HilosPages.I18N_LANGUAGE,
  computedSignal(() => {
    const page = props.context.scopes.page()?.key
    if (
      page !== HilosPages.I18N_LANGUAGE &&
      page !== HilosPages.I18N_LANGUAGE_NAMES &&
      page !== HilosPages.I18N_LANGUAGE_LOCALES
    ) {
      return undefined
    }
    return cardSignal.get()?.nativeName
  }),
)
onUnmounted(unbindName)
const card = useSignal(cardSignal)
const languageWindow = createHilosI18nLanguageCardWindow(
  cardSignal,
  props.context,
)
const copy = HILOS_I18N_LANGUAGE_WINDOW_COPY
const deleteCopy = HILOS_I18N_LANGUAGE_DELETE_COPY
const deleteOpen = ref(false)
const deleteVerdict = computed(() =>
  hilosI18nLanguageDelete(card.value?.summary ?? null),
)
const deleteSource = computed(() =>
  card.value === null
    ? null
    : {
        languageId: card.value.languageId,
        code: card.value.code,
        nativeName: card.value.nativeName,
        canDelete: card.value.summary.canDelete,
        deleteReason: card.value.summary.deleteReason,
      },
)
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nLanguagePage requires a provided Hilos router.')
}
const loading = useSignal(router.pageLoading)
const identity = useSignal(router.pageIdentity)
const tabs = computed(() => {
  const current = card.value
  return current === null
    ? []
    : hilosTabLinks(
        identity.value?.pageTabs ?? [],
        { languageCode: current.code },
        router.resolvePath,
      )
})
const namesPath = computed(
  () =>
    tabs.value.find((tab) => tab.page === HilosPages.I18N_LANGUAGE_NAMES)?.to,
)
const localesPath = computed(
  () =>
    tabs.value.find((tab) => tab.page === HilosPages.I18N_LANGUAGE_LOCALES)?.to,
)
const countrylessState = computed(() => {
  switch (card.value?.summary.countrylessLocale) {
    case 'on':
      return 'Switched on'
    case 'off':
      return 'Switched off'
    default:
      return 'Not created'
  }
})

const deleted = (): void => {
  router.navigate(router.resolvePath(HilosPages.I18N_LANGUAGES, {})!)
}
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.I18N_LANGUAGE"
    head-kind="entity"
    tabs-label="Language pages"
  >
    <template v-if="card" #head-visual>
      <span
        class="hilos-avatar-md rounded-3 bg-secondary-subtle text-body-emphasis d-inline-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-uppercase"
        aria-hidden="true"
        data-id="language-head-tile"
        >{{ card.code }}</span
      >
    </template>
    <template v-if="card" #head-badges>
      <span
        v-if="card.summary.isDefault"
        class="badge"
        :class="HILOS_I18N_LANGUAGE_MARKS.default.tone"
        :title="HILOS_I18N_LANGUAGE_MARKS.default.label"
        data-id="language-head-default"
      >
        <i
          class="bi"
          :class="HILOS_I18N_LANGUAGE_MARKS.default.icon"
          aria-hidden="true"
        ></i>
        <span class="visually-hidden">{{
          HILOS_I18N_LANGUAGE_MARKS.default.label
        }}</span>
      </span>
      <span
        v-if="card.summary.isOwn"
        class="badge"
        :class="HILOS_I18N_LANGUAGE_MARKS.own.tone"
        :title="HILOS_I18N_LANGUAGE_MARKS.own.label"
        data-id="language-head-own"
      >
        <i
          class="bi"
          :class="HILOS_I18N_LANGUAGE_MARKS.own.icon"
          aria-hidden="true"
        ></i>
        <span class="visually-hidden">{{
          HILOS_I18N_LANGUAGE_MARKS.own.label
        }}</span>
      </span>
    </template>
    <template v-if="card" #head-description>
      <p class="text-body-secondary mb-0" data-id="language-head-direction">
        {{ card.rtl ? 'Right to left' : 'Left to right' }}
      </p>
    </template>
    <template v-if="card">
      <div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="language-overview-names"
          >
            <h2 class="small text-body-secondary">Names</h2>
            <div class="fs-4 fw-semibold lh-sm my-1">
              <HilosLink
                v-if="namesPath !== undefined"
                :to="namesPath"
                :aria-label="`Names: ${card.summary.nameCount}`"
                data-id="language-overview-name-count"
                >{{ card.summary.nameCount }}</HilosLink
              >
              <span v-else data-id="language-overview-name-count">{{
                card.summary.nameCount
              }}</span>
            </div>
            <p class="small text-body-secondary mb-0">
              Non-empty base names in other languages; corrections do not count.
            </p>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="language-overview-locales"
          >
            <h2 class="small text-body-secondary">Locales</h2>
            <div class="fs-4 fw-semibold lh-sm my-1">
              <HilosLink
                v-if="localesPath !== undefined"
                :to="localesPath"
                :aria-label="`Locales: ${card.summary.localeCount}`"
                data-id="language-overview-locale-count"
                >{{ card.summary.localeCount }}</HilosLink
              >
              <span v-else data-id="language-overview-locale-count">{{
                card.summary.localeCount
              }}</span>
            </div>
            <p class="small text-body-secondary mb-0">
              <span data-id="language-overview-enabled-locale-count">{{
                card.summary.enabledLocaleCount
              }}</span>
              switched on, including the locale without a country.
              <span v-if="card.summary.localeCount === 0">None created.</span>
            </p>
          </section>
        </div>
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="language-overview-countryless"
          >
            <h2 class="small text-body-secondary">Locale without a country</h2>
            <div class="fs-4 fw-semibold lh-sm my-1">
              <HilosLink
                v-if="localesPath !== undefined"
                :to="localesPath"
                :aria-label="`Locale without a country: ${countrylessState}`"
                data-id="language-overview-countryless-state"
                >{{ countrylessState }}</HilosLink
              >
              <span v-else data-id="language-overview-countryless-state">{{
                countrylessState
              }}</span>
            </div>
            <p class="small text-body-secondary mb-0">
              Dates and numbers when the country is unknown.
            </p>
          </section>
        </div>
      </div>
      <section class="mb-4" data-id="language-card-main">
        <div class="d-flex align-items-center gap-2 mb-2">
          <h2 class="h5 mb-0 flex-grow-1">Main</h2>
          <p
            v-if="card.enabled"
            class="badge mb-0"
            :class="HILOS_I18N_LANGUAGE_MARKS.frozen.tone"
            data-id="language-card-frozen"
          >
            <i
              class="bi me-1"
              :class="HILOS_I18N_LANGUAGE_MARKS.frozen.icon"
              aria-hidden="true"
            ></i>
            Language enabled — frozen
          </p>
          <p
            v-else
            class="badge mb-0"
            :class="HILOS_I18N_LANGUAGE_MARKS.editable.tone"
            data-id="language-card-editable"
          >
            <i
              class="bi me-1"
              :class="HILOS_I18N_LANGUAGE_MARKS.editable.icon"
              aria-hidden="true"
            ></i>
            Language switched off — edit freely
          </p>
          <button
            v-if="card.enabled"
            type="button"
            class="btn btn-sm btn-outline-secondary"
            :title="copy.open.view"
            :aria-label="copy.open.view"
            data-id="language-card-view"
            @click="languageWindow.open()"
          >
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
          <button
            v-else
            type="button"
            class="btn btn-sm btn-outline-primary"
            :title="copy.open.edit"
            :aria-label="copy.open.edit"
            data-id="language-card-edit"
            @click="languageWindow.open()"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
          </button>
        </div>
        <dl class="row mb-0 border rounded-3 p-3">
          <dt class="col-sm-4">Code</dt>
          <dd class="col-sm-8">
            <span class="text-uppercase" data-id="language-card-code">{{
              card.code
            }}</span>
            <span class="d-block small text-body-secondary"
              >Unique language code. Never changes: locale and name addresses
              refer to it.</span
            >
          </dd>
          <dt class="col-sm-4">Native name</dt>
          <dd class="col-sm-8">
            <span data-id="language-card-name">{{ card.nativeName }}</span>
            <span class="d-block small text-body-secondary"
              >How the language calls itself.</span
            >
          </dd>
          <dt class="col-sm-4">
            <label for="language-card-rtl">Writing direction</label>
          </dt>
          <dd class="col-sm-8" data-id="language-card-direction">
            <input
              id="language-card-rtl"
              class="form-check-input me-2"
              type="checkbox"
              disabled
              :checked="card.rtl"
              aria-label="Right to left"
              aria-describedby="language-card-rtl-hint"
            />
            {{ card.rtl ? 'Right to left' : 'Left to right' }}
            <span
              id="language-card-rtl-hint"
              class="d-block small text-body-secondary"
              >Right-to-left writing reverses the product layout.</span
            >
          </dd>
        </dl>
      </section>

      <section class="mb-4" data-id="language-card-state">
        <h2 class="h5">State</h2>
        <div class="border rounded-3 p-3">
          <dl class="row mb-0">
            <dt class="col-sm-4">Availability</dt>
            <dd class="col-sm-8">
              <span data-id="language-card-enabled">
                {{
                  card.enabled
                    ? 'Language switched on'
                    : 'Language switched off'
                }}
              </span>
              <div class="mt-2">
                <HilosI18nLanguageSwitchOff :context="context" :card="card" />
                <HilosI18nLanguageSwitchOn :context="context" :card="card" />
              </div>
            </dd>
            <dt class="col-sm-4">Delete language</dt>
            <dd class="col-sm-8" data-id="language-card-delete-verdict">
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                :disabled="deleteVerdict.disabled"
                :aria-describedby="
                  deleteVerdict.reason
                    ? 'language-card-delete-reason'
                    : undefined
                "
                data-id="language-card-delete"
                @click="deleteOpen = true"
              >
                {{ deleteCopy.open }}
              </button>
              <p
                v-if="deleteVerdict.reason"
                id="language-card-delete-reason"
                class="small text-body-secondary mt-1 mb-0"
                data-id="language-card-delete-reason"
              >
                {{ deleteVerdict.reason }}
              </p>
            </dd>
          </dl>
        </div>
      </section>
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
      v-else-if="!deleteOpen"
      class="alert alert-secondary"
      role="status"
      data-id="language-card-unavailable"
    >
      Language details are no longer available.
    </p>
    <HilosI18nLanguageWindow :window="languageWindow" />
    <HilosI18nLanguageDelete
      v-model="deleteOpen"
      :context="context"
      :source="deleteSource"
      :action-name="HILOS_I18N_LANGUAGE_DELETE_ACTION"
      @deleted="deleted"
    />
  </HilosAdminPage>
</template>
