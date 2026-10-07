<script setup lang="ts">
import { HilosPages, type HilosI18nLanguageCard } from '@hilos/core'
import { computed, inject } from 'vue'

import HilosLink from '../../../HilosLink.vue'
import { hilosRouterKey } from '../../../hilosRouterKey.js'

const props = defineProps<{
  card: HilosI18nLanguageCard
  activePage: string
}>()

const router = inject(hilosRouterKey)
if (!router) {
  throw new Error('HilosI18nLanguageHeader requires a provided Hilos router.')
}

const tabs = computed(() =>
  [
    { page: HilosPages.I18N_LANGUAGE, label: 'Main' },
    { page: HilosPages.I18N_LANGUAGE_NAMES, label: 'Names' },
    { page: HilosPages.I18N_LANGUAGE_LOCALES, label: 'Locales' },
  ].map((tab) => ({
    ...tab,
    path: router.resolvePath(tab.page, { languageCode: props.card.code }),
  })),
)
</script>

<template>
  <div
    class="border rounded-3 p-3 mb-3 d-flex flex-wrap align-items-center gap-3"
    data-id="language-card-header"
  >
    <span
      class="rounded-3 bg-body-tertiary d-inline-flex align-items-center justify-content-center fw-semibold text-uppercase p-3"
      data-id="language-card-code-tile"
    >
      {{ card.code }}
    </span>
    <div class="flex-grow-1">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <span class="h5 mb-0" data-id="language-card-native-name">{{
          card.nativeName
        }}</span>
        <span
          v-if="card.summary.isDefault"
          class="badge text-bg-info"
          data-id="language-card-default"
        >
          Default language
        </span>
        <span
          v-if="card.summary.isOwn"
          class="badge text-bg-secondary"
          data-id="language-card-own"
        >
          Custom language
        </span>
      </div>
      <p class="small text-body-secondary mb-0" data-id="language-card-counts">
        Locales: {{ card.summary.localeCount }} · Names:
        {{ card.summary.nameCount }}
      </p>
    </div>
  </div>

  <nav aria-label="Language pages">
    <ul class="nav nav-underline mb-3" data-id="language-card-tabs">
      <li v-for="tab in tabs" :key="tab.page" class="nav-item">
        <HilosLink
          v-if="tab.path"
          :to="tab.path"
          class="nav-link"
          :class="{ active: tab.page === activePage }"
          :aria-current="tab.page === activePage ? 'page' : undefined"
          :data-id="`language-card-tab-${tab.page}`"
        >
          {{ tab.label }}
        </HilosLink>
        <span
          v-else
          class="nav-link text-body-secondary"
          :data-id="`language-card-tab-${tab.page}`"
        >
          {{ tab.label }}
        </span>
      </li>
    </ul>
  </nav>
</template>
