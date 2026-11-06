<!-- The country main card shows its details and state action buttons. Its one
page-scoped browser datum supplies both the initial view and live updates, the
content of the country's block in the shared head among them: the code tile, the
code, the custom-country mark and the currency and default-locale line. The name
in the head is the live owner label this page binds, not drawn here.

A country deleted elsewhere under the open card turns it into the shell's 404
(preserveModalDraftsOnDelete, HIL-1603): head and body go, and the edit window
stays in #modals with its draft, its live source now empty, so it says Deleted. -->
<script setup lang="ts">
import {
  createHilosI18nCountryCard,
  createHilosI18nCountryCardWindow,
  createHilosI18nCountryDelete,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  HILOS_I18N_COUNTRY_DELETE_COPY,
  HILOS_I18N_COUNTRY_MARKS,
  hilosI18nCountryDeleteFromCard,
  hilosI18nCountryDeleteReason,
  hilosI18nCountryDisplayName,
  type HilosI18nCountryContext,
  type HilosI18nCountryDeleteTarget,
} from '@hilos/core/admin/i18n'
import {
  bindPageInstanceName,
  computedSignal,
  HilosPages,
} from '@hilos/core/base'
import { computed, inject, onMounted, onUnmounted, ref, watch } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import HilosLink from '../../../HilosLink.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nCountryDelete from './HilosI18nCountryDelete.vue'
import HilosI18nCountryWindow from './HilosI18nCountryWindow.vue'
import HilosI18nCountrySwitchOff from './HilosI18nCountrySwitchOff.vue'
import HilosI18nCountrySwitchOn from './HilosI18nCountrySwitchOn.vue'

const props = defineProps<{ context: HilosI18nCountryContext }>()
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nCountryPage requires a provided Hilos router.')
}
const routeId = computedSignal(() =>
  String(router.currentRoute.get().params.countryId ?? ''),
)
const rawCard = createHilosI18nCountryCard(props.context)
const cardSignal = computedSignal(() => {
  const current = rawCard.get()
  return current !== null && String(current.countryId) === routeId.get()
    ? current
    : null
})
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
    const current = cardSignal.get()
    return current === null ? undefined : hilosI18nCountryDisplayName(current)
  }),
)
onUnmounted(unbindName)
const card = useSignal(cardSignal)
const currentId = useSignal(routeId)
const window = createHilosI18nCountryCardWindow(cardSignal, props.context)
const copy = HILOS_I18N_COUNTRY_WINDOW_COPY
onMounted(window.start)
onUnmounted(window.dispose)

const loading = useSignal(router.pageLoading)
watch(
  useSignal(routeId),
  () => {
    window.close()
    deleteOpen.value = false
    deleteTarget.value = null
  },
  { flush: 'sync' },
)
const namesPath = computed(() =>
  card.value === null
    ? undefined
    : router.resolvePath(HilosPages.I18N_COUNTRY_NAMES, {
        countryId: String(card.value.countryId),
      }),
)
const enabledLocaleCount = computed(
  () =>
    card.value?.summary.locales.filter((locale) => locale.enabled).length ?? 0,
)
const { deleteCountry } = createHilosI18nCountryDelete(props.context)
const deleteOpen = ref(false)
const deleteTarget = ref<HilosI18nCountryDeleteTarget | null>(null)
const deleteSource = computed(() => hilosI18nCountryDeleteFromCard(card.value))

function openDelete(): void {
  if (deleteSource.value?.canDelete !== true) return
  deleteTarget.value = deleteSource.value
  deleteOpen.value = true
}

const deleted = (): void => {
  const path = router.resolvePath(HilosPages.I18N_COUNTRIES)
  if (path !== undefined) router.navigate(path)
}
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.I18N_COUNTRY"
    head-kind="entity"
    tabs-label="Country pages"
    preserve-modal-drafts-on-delete
  >
    <template v-if="card" #head-visual>
      <span
        class="hilos-avatar-md rounded-3 bg-secondary-subtle text-body-emphasis d-inline-flex align-items-center justify-content-center flex-shrink-0 fw-semibold text-uppercase"
        aria-hidden="true"
        data-id="country-head-tile"
        >{{ card.code }}</span
      >
    </template>
    <template v-if="card" #head-meta>
      <span
        class="font-monospace small text-body-secondary text-uppercase"
        data-id="country-head-code"
        >{{ card.code }}</span
      >
    </template>
    <template v-if="card?.summary.isOwn" #head-badges>
      <span
        class="badge"
        :class="HILOS_I18N_COUNTRY_MARKS.own.tone"
        title="This country is absent from the catalog and source."
        data-id="country-head-own"
      >
        <i
          class="bi"
          :class="HILOS_I18N_COUNTRY_MARKS.own.icon"
          aria-hidden="true"
        ></i>
        <span class="visually-hidden"
          >{{ HILOS_I18N_COUNTRY_MARKS.own.label }}. This country is absent from
          the catalog and source.</span
        >
      </span>
    </template>
    <template v-if="card" #head-description>
      <p class="text-body-secondary mb-0" data-id="country-head-description">
        Currency {{ card.currencySymbol }} {{ card.currencyCode }} · Default
        locale {{ card.summary.defaultLocaleCode ?? 'not chosen' }}
      </p>
    </template>
    <template v-if="card">
      <div class="row row-cols-1 row-cols-sm-2 g-3 mb-4">
        <div class="col">
          <section
            class="border rounded-3 p-3 h-100"
            data-id="country-overview-names"
          >
            <h2 class="small text-body-secondary">Names</h2>
            <div class="fs-4 fw-semibold lh-sm my-1">
              <HilosLink
                v-if="namesPath !== undefined"
                :to="namesPath"
                :aria-label="`Names: ${card.summary.nameCount}`"
                data-id="country-overview-name-count"
                >{{ card.summary.nameCount }}</HilosLink
              >
              <span v-else data-id="country-overview-name-count">{{
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
            data-id="country-overview-locales"
          >
            <h2 class="small text-body-secondary">Locales</h2>
            <div
              class="fs-4 fw-semibold lh-sm my-1"
              data-id="country-overview-locale-count"
            >
              {{ card.summary.localeCount }}
            </div>
            <p class="small text-body-secondary mb-0">
              Switched on:
              <span data-id="country-overview-enabled-locale-count">{{
                enabledLocaleCount
              }}</span>
              · Edited in their language cards.
            </p>
          </section>
        </div>
      </div>

      <section class="mb-4" data-id="country-card-main">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <h2 class="h5 mb-0 me-auto">Main</h2>
          <span
            v-if="card.enabled"
            class="badge bg-warning-subtle text-warning-emphasis"
            data-id="country-card-frozen"
          >
            <i class="bi bi-lock me-1" aria-hidden="true"></i>
            Country switched on — frozen
          </span>
          <span
            v-else
            class="badge bg-info-subtle text-info-emphasis"
            data-id="country-card-editable"
          >
            <i class="bi bi-pencil me-1" aria-hidden="true"></i>
            Country switched off — edit freely
          </span>
          <button
            type="button"
            class="btn btn-sm"
            :class="
              card.enabled ? 'btn-outline-secondary' : 'btn-outline-primary'
            "
            :title="card.enabled ? copy.open.view : copy.open.edit"
            :aria-label="card.enabled ? copy.open.view : copy.open.edit"
            :data-id="card.enabled ? 'country-card-view' : 'country-card-edit'"
            @click="window.open(card.enabled ? 'view' : 'edit')"
          >
            <i
              class="bi"
              :class="card.enabled ? 'bi-eye' : 'bi-pencil'"
              aria-hidden="true"
            ></i>
          </button>
        </div>
        <dl class="border rounded-3 p-3 mb-0">
          <div class="row align-items-baseline g-2 py-2 border-bottom">
            <dt class="col-sm-3 small text-body-secondary fw-normal">Code</dt>
            <dd class="col-sm-3 small fw-semibold mb-0">
              <span
                class="font-monospace text-uppercase"
                data-id="country-card-code"
                >{{ card.code }}</span
              >
            </dd>
            <dd class="col-sm-6 small text-body-secondary mb-0">
              ISO 3166-1 alpha-2, unique. Never changes: locales and names refer
              to it.
            </dd>
          </div>
          <div class="row align-items-baseline g-2 py-2 border-bottom">
            <dt class="col-sm-3 small text-body-secondary fw-normal">
              Currency
            </dt>
            <dd
              class="col-sm-3 small fw-semibold mb-0"
              data-id="country-card-currency"
            >
              {{ card.currencySymbol }}
              <span class="font-monospace">{{ card.currencyCode }}</span>
            </dd>
            <dd class="col-sm-6 small text-body-secondary mb-0">
              The symbol is for display, the ISO 4217 code is for calculations.
            </dd>
          </div>
          <div class="row align-items-baseline g-2 py-2">
            <dt class="col-sm-3 small text-body-secondary fw-normal">
              Default locale
            </dt>
            <dd
              class="col-sm-3 small fw-semibold mb-0"
              data-id="country-card-default-locale"
            >
              {{ card.summary.defaultLocaleCode ?? 'not chosen' }}
            </dd>
            <dd class="col-sm-6 small text-body-secondary mb-0">
              Formats used when the country is known but the language is not.
              You choose the locale.
            </dd>
          </div>
        </dl>
      </section>

      <section class="mb-4" data-id="country-card-state">
        <h2 class="h5">State</h2>
        <div class="border rounded-3 p-3">
          <div
            class="d-flex flex-wrap align-items-center gap-2 py-2 border-bottom"
          >
            <div class="flex-grow-1">
              <div class="fw-semibold small" data-id="country-card-enabled">
                {{
                  card.enabled ? 'Country switched on' : 'Country switched off'
                }}
              </div>
              <p class="small text-body-secondary mb-0">
                <template v-if="card.enabled">
                  Values are frozen; switch the country off to edit them.
                </template>
                <template v-else>
                  Values can be edited; the code never changes.
                </template>
              </p>
            </div>
            <div class="text-nowrap">
              <HilosI18nCountrySwitchOn
                :key="`on:${currentId}`"
                :context="context"
                :card="card"
              />
              <HilosI18nCountrySwitchOff
                :key="`off:${currentId}`"
                :context="context"
                :card="card"
              />
            </div>
          </div>
          <div
            class="d-flex flex-wrap align-items-center gap-2 py-2"
            data-id="country-card-delete-verdict"
          >
            <div class="flex-grow-1">
              <div class="fw-semibold small">Delete country</div>
              <p
                id="country-card-delete-reason"
                class="small text-body-secondary mb-0"
              >
                {{ hilosI18nCountryDeleteReason(deleteSource) }}
              </p>
            </div>
            <button
              type="button"
              class="btn btn-sm"
              :class="
                card.summary.canDelete
                  ? 'btn-outline-danger'
                  : 'border border-secondary text-body-secondary'
              "
              :aria-label="HILOS_I18N_COUNTRY_DELETE_COPY.open"
              :aria-disabled="!card.summary.canDelete || undefined"
              aria-describedby="country-card-delete-reason"
              data-id="country-card-delete"
              @click="openDelete"
            >
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </div>
        </div>
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
      data-id="country-card-unavailable"
    >
      Country details are no longer available.
    </p>
    <HilosI18nCountryDelete
      :key="currentId"
      v-model="deleteOpen"
      :target="deleteTarget"
      :source="deleteSource"
      :delete-country="deleteCountry"
      @deleted="deleted"
    />
    <template #modals>
      <HilosI18nCountryWindow :window="window" />
    </template>
  </HilosAdminPage>
</template>
