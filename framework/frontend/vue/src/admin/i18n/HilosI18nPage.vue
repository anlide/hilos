<!-- HilosI18nPage — the Internationalization overview. The current page's
complete summary supplies both tiles; the shared head owns its title and tabs.
Tiles follow the same resolved tabs as that head. Bootstrap classes only. -->
<script setup lang="ts">
import {
  createHilosI18nOverview,
  type HilosI18nOverviewContext,
} from '@hilos/core/admin/i18n'
import { HilosPages, hilosTabLinks } from '@hilos/core/base'
import { computed, inject } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosLink from '../../HilosLink.vue'
import HilosSkeleton from '../../HilosSkeleton.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project's current page scopes. */
  context: HilosI18nOverviewContext
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nPage requires a provided Hilos router.')
}

const overview = useSignal(createHilosI18nOverview(props.context))
const identity = useSignal(router.pageIdentity)
const route = useSignal(router.currentRoute)
const tabs = computed(() =>
  hilosTabLinks(
    identity.value?.pageTabs ?? [],
    route.value.params,
    router.resolvePath,
  ),
)
const languagesPath = computed(
  () => tabs.value.find((tab) => tab.page === HilosPages.I18N_LANGUAGES)?.to,
)
const countriesPath = computed(
  () => tabs.value.find((tab) => tab.page === HilosPages.I18N_COUNTRIES)?.to,
)
</script>

<template>
  <HilosAdminPage
    :page="HilosPages.I18N"
    head-kind="section"
    tabs-label="Internationalization pages"
  >
    <div data-id="i18n-overview">
      <div
        v-if="overview"
        class="row row-cols-2 row-cols-md-3 row-cols-lg-5 g-3 mb-4"
      >
        <div v-if="languagesPath !== undefined" class="col">
          <div
            class="border rounded-3 p-3 h-100"
            data-id="i18n-overview-languages"
          >
            <div class="small text-body-secondary">Languages</div>
            <div class="fs-4 fw-semibold lh-sm my-1">
              <HilosLink
                :to="languagesPath"
                class="text-decoration-none"
                :aria-label="`Languages: ${overview.languages.enabledCount} of ${overview.languages.totalCount} enabled`"
                data-id="i18n-overview-languages-count"
              >
                {{ overview.languages.enabledCount }} of
                {{ overview.languages.totalCount }}
              </HilosLink>
            </div>
            <div class="small text-body-secondary">
              Enabled · default {{ overview.languages.defaultLanguageName }}
            </div>
          </div>
        </div>
        <div v-if="countriesPath !== undefined" class="col">
          <div
            class="border rounded-3 p-3 h-100"
            data-id="i18n-overview-countries"
          >
            <div class="small text-body-secondary">Countries</div>
            <div class="fs-4 fw-semibold lh-sm my-1">
              <HilosLink
                :to="countriesPath"
                class="text-decoration-none"
                :aria-label="`Countries: ${overview.countries.enabledCount} of ${overview.countries.totalCount} enabled`"
                data-id="i18n-overview-countries-count"
              >
                {{ overview.countries.enabledCount }} of
                {{ overview.countries.totalCount }}
              </HilosLink>
            </div>
            <div class="small text-body-secondary">
              Enabled · {{ overview.countries.builtInCount }} built-in,
              {{ overview.countries.ownCount }} custom
            </div>
          </div>
        </div>
      </div>
      <HilosSkeleton v-else />
    </div>
  </HilosAdminPage>
</template>
