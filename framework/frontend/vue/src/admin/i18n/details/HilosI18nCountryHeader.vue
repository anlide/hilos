<script setup lang="ts">
import { HilosPages, type HilosI18nCountryCard } from '@hilos/core'
import { computed, inject } from 'vue'

import HilosLink from '../../../HilosLink.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const props = defineProps<{
  card: HilosI18nCountryCard
  activePage: string
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nCountryHeader requires a provided Hilos router.')
}

const tabs = computed(() =>
  [
    { page: HilosPages.I18N_COUNTRY, label: 'Main' },
    { page: HilosPages.I18N_COUNTRY_NAMES, label: 'Names' },
  ].map((tab) => ({
    ...tab,
    path: router.resolvePath(tab.page, { countryCode: props.card.code }),
  })),
)
</script>

<template>
  <div class="border rounded-3 p-3 mb-3" data-id="country-card-header">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span
        class="h5 mb-0"
        :class="{ 'text-uppercase': card.summary.name === null }"
        data-id="country-card-title"
        >{{ card.summary.name ?? card.code }}</span
      >
      <span
        v-if="card.summary.name !== null"
        class="text-body-secondary text-uppercase"
        data-id="country-card-code-badge"
      >
        {{ card.code }}
      </span>
      <span
        v-if="card.summary.isOwn"
        class="badge text-bg-secondary"
        data-id="country-card-own"
      >
        Custom country
      </span>
    </div>
    <p class="small text-body-secondary mb-0" data-id="country-card-line">
      Currency {{ card.currencySymbol }} {{ card.currencyCode }} · Default
      locale {{ card.summary.defaultLocaleCode ?? 'not chosen' }}
    </p>
  </div>

  <nav aria-label="Country pages">
    <ul class="nav nav-underline mb-3" data-id="country-card-tabs">
      <li v-for="tab in tabs" :key="tab.page" class="nav-item">
        <HilosLink
          v-if="tab.path"
          :to="tab.path"
          class="nav-link"
          :class="{ active: tab.page === activePage }"
          :aria-current="tab.page === activePage ? 'page' : undefined"
          :data-id="`country-card-tab-${tab.page}`"
        >
          {{ tab.label }}
        </HilosLink>
        <span
          v-else
          class="nav-link text-body-secondary"
          :data-id="`country-card-tab-${tab.page}`"
        >
          {{ tab.label }}
        </span>
      </li>
    </ul>
  </nav>
</template>
