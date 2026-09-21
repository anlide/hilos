<!-- HilosLicensePage — the tier-2 public /license page: the project's own license
prose (the default slot) and, under it, the inventory of everything the build
actually stands on. The list is not typed by hand and not fetched: it is the
build-time snapshot generated from the project's own two lockfiles
(framework/frontend/scripts/generate-license-inventory.mjs), handed over in one
prop, so the page renders whole on a machine with no browser and no server
behind it (build-and-docker.md, the SSG section).

The page holds no collection logic of its own — filtering, the option lists, the
label and the CSV rendering are @hilos/core, so the three view layers cannot
drift apart. It carries no `title` prop either: the heading moves with the frame,
and the project's file holds prose alone.

The clipboard is read on the CLICK and never during render: this page is executed
at build time by a server renderer where `navigator` does not exist at all, so a
render-time probe would decide the static file's contents by the absence of a
browser on the build machine, and the button would appear only after the SPA
mounts. The two export buttons are therefore always rendered and never disabled —
on an empty result they hand over the header row alone. Bootstrap classes only,
no CSS of its own (styling-rules.md). -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  type HilosLicenseEntry,
  type HilosLicenseInventory,
  copyToClipboard,
  downloadTextFile,
  filterLicenseEntries,
  licenseCsvFileName,
  licenseFilterOptions,
  licenseLanguageLabel,
  renderLicenseCsv,
} from '@hilos/core'

import HilosLongText from '../HilosLongText.vue'
import HilosModal from '../HilosModal.vue'
import HilosStaticPage from '../HilosStaticPage.vue'

const props = defineProps<{
  /** The build-time snapshot of everything this build stands on. */
  inventory: HilosLicenseInventory
}>()

/** The type the export is offered under. */
const DOWNLOAD_MIME_TYPE = 'text/csv;charset=utf-8'

const search = ref('')
const license = ref('')
const language = ref('')

// Both selects offer what the snapshot actually holds, never a fixed enum: a
// hard-coded list would silently hide a license nobody expected, which is the
// one thing this page exists to surface.
const options = computed(() => licenseFilterOptions(props.inventory.entries))

const visible = computed(() =>
  filterLicenseEntries(props.inventory.entries, {
    search: search.value,
    license: license.value,
    language: language.value,
  }),
)

const openEntry = ref<HilosLicenseEntry | null>(null)
const modalOpen = computed({
  get: () => openEntry.value !== null,
  set: (open: boolean) => {
    if (!open) openEntry.value = null
  },
})

const modalTitle = computed(() =>
  openEntry.value === null
    ? ''
    : `${openEntry.value.name} ${openEntry.value.version} · ${openEntry.value.license}`,
)

const copyStatus = ref('')

// The status speaks about the list that was handed over, so it goes as soon as
// the list underneath it changes — the same reason a reopened modal does not
// keep reporting the copy of its last visit.
watch(visible, () => {
  copyStatus.value = ''
})

async function onCopy(): Promise<void> {
  const copied = await copyToClipboard(renderLicenseCsv(visible.value))
  copyStatus.value = copied
    ? 'Copied'
    : 'The browser did not allow copying — use Download'
}

function onDownload(): void {
  downloadTextFile(
    licenseCsvFileName(props.inventory.project),
    renderLicenseCsv(visible.value),
    DOWNLOAD_MIME_TYPE,
  )
}
</script>

<template>
  <HilosStaticPage title="License">
    <slot />

    <h2 class="h6 text-uppercase text-body-secondary mb-2 mt-4">
      What this build stands on
    </h2>

    <div class="d-flex flex-wrap gap-2 mb-3">
      <div class="input-group input-group-sm w-auto flex-grow-1 flex-md-grow-0">
        <span class="input-group-text">
          <i class="bi bi-search" aria-hidden="true"></i>
        </span>
        <!-- The placeholder doubles as the field's accessible name, as it does
        on the table bar: the field carries no visible label, and two different
        strings would name it twice. The two selects are named by their own "all"
        option for the same reason. -->
        <input
          v-model="search"
          type="search"
          class="form-control"
          placeholder="Search a package or a license"
          aria-label="Search a package or a license"
          data-id="license-search"
        />
      </div>
      <select
        v-model="license"
        class="form-select form-select-sm w-auto"
        aria-label="All licenses"
        data-id="license-filter-license"
      >
        <option value="">All licenses</option>
        <option
          v-for="option in options.licenses"
          :key="option"
          :value="option"
        >
          {{ option }}
        </option>
      </select>
      <select
        v-model="language"
        class="form-select form-select-sm w-auto"
        aria-label="All languages"
        data-id="license-filter-language"
      >
        <option value="">All languages</option>
        <option
          v-for="option in options.languages"
          :key="option"
          :value="option"
        >
          {{ licenseLanguageLabel(option) }}
        </option>
      </select>
    </div>

    <div data-id="license-inventory">
      <button
        v-for="entry in visible"
        :key="`${entry.language}/${entry.name}/${entry.version}`"
        type="button"
        class="d-flex align-items-center gap-2 py-1 small w-100 text-start border-0 border-bottom bg-transparent"
        data-id="license-row"
        @click="openEntry = entry"
      >
        <span class="flex-grow-1">
          {{ entry.name }}
          <span class="text-body-secondary">{{ entry.version }}</span>
        </span>
        <span class="badge text-bg-light border">
          {{ licenseLanguageLabel(entry.language) }}
        </span>
        <span class="badge text-bg-light border">{{ entry.license }}</span>
      </button>
      <p
        v-if="visible.length === 0"
        class="small text-body-secondary mb-0"
        data-id="license-empty"
      >
        Nothing in this build matches these filters
      </p>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        data-id="license-copy"
        @click="onCopy"
      >
        <i class="bi bi-clipboard me-1" aria-hidden="true"></i>
        Copy the list
      </button>
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        data-id="license-download"
        @click="onDownload"
      >
        <i class="bi bi-download me-1" aria-hidden="true"></i>
        Download as a file
      </button>
      <!-- Rendered even while empty: a live region has to be in the document
      before its text changes, or the change is never announced. -->
      <span
        class="small text-body-secondary"
        role="status"
        aria-live="polite"
        data-id="license-copy-status"
        >{{ copyStatus }}</span
      >
    </div>

    <HilosModal v-model="modalOpen" :title="modalTitle" initial-focus="dialog">
      <HilosLongText
        v-if="openEntry && openEntry.licenseText !== null"
        kind="output"
        :text="openEntry.licenseText"
        data-id="license-text"
      />
      <p v-else class="small mb-0" data-id="license-text-missing">
        This package ships no license file; the type above comes from its
        manifest
      </p>
      <template #actions="{ requestClose }">
        <a
          v-if="openEntry && openEntry.repository !== null"
          :href="openEntry.repository"
          class="btn btn-outline-secondary"
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
