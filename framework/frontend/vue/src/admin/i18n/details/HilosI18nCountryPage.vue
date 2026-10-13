<!-- The country main card shows its details and state action buttons. Its one
page-scoped browser datum supplies both the initial view and live updates. -->
<script setup lang="ts">
import {
  createHilosI18nCountryCard,
  createHilosI18nCountryCardWindow,
  createHilosI18nCountryDelete,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  HILOS_I18N_COUNTRY_DELETE_COPY,
  hilosI18nCountryDeleteFromCard,
  hilosI18nCountryDeleteReason,
  HilosPages,
  type HilosI18nCountryContext,
  type HilosI18nCountryDeleteTarget,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nCountryDelete from './HilosI18nCountryDelete.vue'
import HilosI18nCountryHeader from './HilosI18nCountryHeader.vue'
import HilosI18nCountryWindow from './HilosI18nCountryWindow.vue'
import HilosI18nCountrySwitchOff from './HilosI18nCountrySwitchOff.vue'
import HilosI18nCountrySwitchOn from './HilosI18nCountrySwitchOn.vue'

const props = defineProps<{ context: HilosI18nCountryContext }>()
const cardSignal = createHilosI18nCountryCard(props.context)
const card = useSignal(cardSignal)
const window = createHilosI18nCountryCardWindow(cardSignal, props.context)
const copy = HILOS_I18N_COUNTRY_WINDOW_COPY
onMounted(window.start)
onUnmounted(window.dispose)
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nCountryPage requires a provided Hilos router.')
}
const loading = useSignal(router.pageLoading)
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
  <HilosAdminPage :page="HilosPages.I18N_COUNTRY">
    <template v-if="card">
      <HilosI18nCountryHeader
        :card="card"
        :active-page="HilosPages.I18N_COUNTRY"
      />

      <section class="mb-4" data-id="country-card-main">
        <div class="d-flex align-items-center gap-2 mb-2">
          <h2 class="h5 mb-0">Main</h2>
          <button
            type="button"
            class="btn btn-sm ms-auto"
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
        <div class="border rounded-3 p-3">
          <p
            v-if="card.enabled"
            class="badge text-bg-warning mb-3"
            data-id="country-card-frozen"
          >
            Country enabled — frozen
          </p>
          <dl class="row mb-0">
            <dt class="col-sm-4">Code</dt>
            <dd class="col-sm-8">
              <span class="text-uppercase" data-id="country-card-code">{{
                card.code
              }}</span>
              <span class="d-block small text-body-secondary">
                ISO 3166-1 alpha-2, unique. Never changes: locales and names
                refer to it.
              </span>
            </dd>
            <dt class="col-sm-4">Currency</dt>
            <dd class="col-sm-8">
              <span data-id="country-card-currency"
                >{{ card.currencySymbol }} {{ card.currencyCode }}</span
              >
              <span class="d-block small text-body-secondary">
                The symbol is for display, the ISO 4217 code is for
                calculations.
              </span>
            </dd>
            <dt class="col-sm-4">Default locale</dt>
            <dd class="col-sm-8 mb-0">
              <span data-id="country-card-default-locale">{{
                card.summary.defaultLocaleCode ?? 'not chosen'
              }}</span>
              <span class="d-block small text-body-secondary">
                Formats used when the country is known but the language is not.
                Empty takes the formats from the language.
              </span>
            </dd>
          </dl>
        </div>
      </section>

      <section class="mb-4" data-id="country-card-state">
        <h2 class="h5">State</h2>
        <dl class="row mb-0 border rounded-3 p-3">
          <dt class="col-sm-4">Availability</dt>
          <dd class="col-sm-8">
            <span data-id="country-card-enabled">{{
              card.enabled ? 'Enabled' : 'Disabled'
            }}</span>
            <span class="d-block small text-body-secondary">
              <template v-if="card.enabled">
                Values are frozen; switch the country off to edit them.
              </template>
              <template v-else>
                Values can be edited; the code never changes.
              </template>
            </span>
            <div class="mt-2">
              <HilosI18nCountrySwitchOn :context="context" :card="card" />
              <HilosI18nCountrySwitchOff :context="context" :card="card" />
            </div>
          </dd>
          <dt class="col-sm-4">Delete country</dt>
          <dd class="col-sm-8 mb-0" data-id="country-card-delete-verdict">
            <span id="country-card-delete-reason">{{
              hilosI18nCountryDeleteReason(deleteSource)
            }}</span>
            <div class="mt-2">
              <button
                type="button"
                class="btn btn-sm"
                :class="
                  card.summary.canDelete
                    ? 'btn-outline-danger'
                    : 'border border-secondary text-body-secondary'
                "
                :aria-disabled="!card.summary.canDelete || undefined"
                aria-describedby="country-card-delete-reason"
                data-id="country-card-delete"
                @click="openDelete"
              >
                {{ HILOS_I18N_COUNTRY_DELETE_COPY.open }}
              </button>
            </div>
          </dd>
        </dl>
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
    <HilosI18nCountryWindow :window="window" />
    <HilosI18nCountryDelete
      v-model="deleteOpen"
      :target="deleteTarget"
      :source="deleteSource"
      :delete-country="deleteCountry"
      @deleted="deleted"
    />
  </HilosAdminPage>
</template>
