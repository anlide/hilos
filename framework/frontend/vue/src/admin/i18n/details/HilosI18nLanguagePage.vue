<!-- The language main card is read-only until the action leaves land. Its one
page-scoped browser datum supplies both the initial view and live updates. -->
<script setup lang="ts">
import {
  createHilosI18nLanguageCard,
  HilosPages,
  type HilosI18nLanguageContext,
} from '@hilos/core'
import { inject } from 'vue'

import HilosAdminPage from '../../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'
import { useSignal } from '../../../useSignal.js'
import HilosI18nLanguageHeader from './HilosI18nLanguageHeader.vue'

const props = defineProps<{ context: HilosI18nLanguageContext }>()
const card = useSignal(createHilosI18nLanguageCard(props.context))
const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nLanguagePage requires a provided Hilos router.')
}
const loading = useSignal(router.pageLoading)
</script>

<template>
  <HilosAdminPage :page="HilosPages.I18N_LANGUAGE">
    <template v-if="card">
      <HilosI18nLanguageHeader
        :card="card"
        :active-page="HilosPages.I18N_LANGUAGE"
      />

      <section class="mb-4" data-id="language-card-main">
        <h2 class="h5">Main</h2>
        <dl class="row mb-0 border rounded-3 p-3">
          <dt class="col-sm-4">Code</dt>
          <dd class="col-sm-8 text-uppercase" data-id="language-card-code">
            {{ card.code }}
          </dd>
          <dt class="col-sm-4">Native name</dt>
          <dd class="col-sm-8" data-id="language-card-name">
            {{ card.nativeName }}
          </dd>
          <dt class="col-sm-4">Writing direction</dt>
          <dd class="col-sm-8" data-id="language-card-direction">
            {{ card.rtl ? 'Right to left' : 'Left to right' }}
          </dd>
        </dl>
      </section>

      <section class="mb-4" data-id="language-card-state">
        <h2 class="h5">State</h2>
        <div class="border rounded-3 p-3">
          <p
            v-if="card.enabled"
            class="badge text-bg-warning mb-3"
            data-id="language-card-frozen"
          >
            Language enabled — frozen
          </p>
          <dl class="row mb-0">
            <dt class="col-sm-4">Availability</dt>
            <dd class="col-sm-8" data-id="language-card-enabled">
              {{ card.enabled ? 'Enabled' : 'Disabled' }}
            </dd>
            <dt class="col-sm-4">Delete language</dt>
            <dd class="col-sm-8" data-id="language-card-delete-verdict">
              <template v-if="card.summary.canDelete">Can be deleted</template>
              <template v-else-if="card.summary.deleteReason === 'default'">
                Cannot delete: this is the default language
              </template>
              <template v-else-if="card.summary.deleteReason === 'locales'">
                Cannot delete: the language has locales
              </template>
              <template v-else
                >Cannot delete: manual names reference the language</template
              >
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
      v-else
      class="alert alert-secondary"
      role="status"
      data-id="language-card-unavailable"
    >
      Language details are no longer available.
    </p>
  </HilosAdminPage>
</template>
