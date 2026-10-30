<!-- Core owns this mount's complete local inventory window and package reader. -->
<script setup lang="ts">
import { computed, onScopeDispose, watch } from 'vue'
import {
  createHilosLicenseDependencies,
  licenseLanguageLabel,
  type HilosLicenseInventory,
  type HilosStaticPageNavigation,
} from '@hilos/core/base'

import HilosLongText from '../HilosLongText.vue'
import HilosModal from '../HilosModal.vue'
import HilosStaticPage from '../HilosStaticPage.vue'
import HilosViewportTable from '../HilosViewportTable.vue'
import { useSignal } from '../useSignal.js'

const props = defineProps<{
  /** The complete build-time dependency snapshot. */
  inventory: HilosLicenseInventory
  /** Optional navigation across sibling public pages. */
  navigation?: HilosStaticPageNavigation
}>()

// Setup also runs on the server: the factory supplies the first full window
// synchronously, while browser effects wait for the bar's actions.
const dependencies = createHilosLicenseDependencies(props.inventory)
const entry = useSignal(dependencies.entry)
const copyStatus = useSignal(dependencies.copyStatus)
watch(() => props.inventory, dependencies.setInventory, { flush: 'sync' })
onScopeDispose(dependencies.dispose)

const modalOpen = computed({
  get: () => entry.value !== null,
  set: (open: boolean) => {
    if (!open) dependencies.close()
  },
})
const modalTitle = computed(() =>
  entry.value === null
    ? ''
    : `${entry.value.name} ${entry.value.version} · ${entry.value.license}`,
)
</script>

<template>
  <HilosStaticPage
    title="License"
    icon="bi-journal-code"
    subtitle="The license of this project, and of everything it is built on."
    :navigation="props.navigation"
  >
    <HilosViewportTable :controller="dependencies.table">
      <template #cell-name="{ row }">
        <button
          type="button"
          class="btn btn-link link-body-emphasis p-0 text-start"
          data-id="license-package-open"
          @click="dependencies.open(row)"
        >
          {{ row.name }}
        </button>
        <span class="text-body-secondary ms-1">{{ row.version }}</span>
      </template>
      <template #cell-language="{ row }">
        {{ licenseLanguageLabel(row.language) }}
      </template>
      <template #cell-license="{ row }">{{ row.license }}</template>
    </HilosViewportTable>

    <div class="hilos-stack small text-body-secondary mt-2">
      <span class="invisible" aria-hidden="true">Copied</span>
      <span class="invisible" aria-hidden="true"
        >The browser did not allow copying — use Download</span
      >
      <span role="status" aria-live="polite" data-id="license-copy-status">{{
        copyStatus
      }}</span>
    </div>

    <HilosModal
      v-model="modalOpen"
      :title="modalTitle"
      initial-focus="dialog"
      size="wide"
    >
      <HilosLongText
        v-if="entry && entry.licenseText !== null"
        kind="output"
        :text="entry.licenseText"
        data-id="license-text"
      />
      <p v-else class="small mb-0" data-id="license-text-missing">
        This package ships no license file; the type above comes from its
        manifest
      </p>
      <template #actions="{ requestClose }">
        <a
          v-if="entry && entry.repository !== null"
          :href="entry.repository"
          class="btn"
          :class="
            entry.licenseText === null ? 'btn-primary' : 'btn-outline-secondary'
          "
          target="_blank"
          rel="noopener noreferrer"
          data-id="license-repository"
        >
          <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>
          Repository
        </a>
        <button type="button" class="btn btn-secondary" @click="requestClose">
          Close
        </button>
      </template>
    </HilosModal>
  </HilosStaticPage>
</template>
