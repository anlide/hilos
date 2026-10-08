<!-- The country main card is read-only until the action leaves land. Its one
page-scoped browser datum supplies both the initial view and live updates. -->
<script setup lang="ts">
import {
  createHilosI18nCountryCard,
  HilosPages,
  type HilosI18nCountryContext,
} from '@hilos/core'
import { inject } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nCountryHeader from './HilosI18nCountryHeader.vue'

const props = defineProps<{ context: HilosI18nCountryContext }>()
const card = useSignal(createHilosI18nCountryCard(props.context))
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nCountryPage requires a provided Hilos router.')
}
const loading = useSignal(router.pageLoading)
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_COUNTRY">
    <template v-if="card">
      <HilosI18nCountryHeader
        :card="card"
        :active-page="HilosPages.I18N_COUNTRY"
      />

      <section class="mb-4" data-id="country-card-main">
        <h2 class="h5">Main</h2>
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
          </dd>
          <dt class="col-sm-4">Delete country</dt>
          <dd class="col-sm-8 mb-0" data-id="country-card-delete-verdict">
            <template v-if="card.summary.deleteReason === 'known'">
              Cannot delete: the built-in catalog knows this country and the
              reflow would bring it back; switch it off instead
            </template>
            <template v-else-if="card.summary.deleteReason === 'locales'">
              Cannot delete: locales refer to this country
            </template>
            <template v-else-if="card.summary.deleteReason === 'names'">
              Cannot delete: names refer to this country
            </template>
            <template v-else>Can be deleted: nothing refers to it yet</template>
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
  </HilosAdminPage>
</template>
