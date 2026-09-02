<!-- HilosLogsViewPage — the framework Hilos log viewer (HilosPages.LOGS_VIEW):
the lines of ONE file on ONE node, for an administrator who came to work out what
already happened. The node, the source (the live journal or one archived batch)
and the stream together ARE the address of this screen, so choosing another file
rewrites the address in place rather than navigating — it is another file of the
same page, and a navigation would re-subscribe it and drop the catalog. Nothing
is filtered here: the level and the substring are fields of the read request, and
the server answers with what matched. Following the live tail — the Follow
switch, the sticky bottom, the rotation divider — is HIL-758, and there is no
Refresh button on purpose: freshness arrives as a push. The catalog, the address,
the read and the entry view-model are the core headless's (hilosLogViewer); this
view owns only the markup, so a project mounts it by passing its
HilosLogViewerContext. Bootstrap classes only, save the one pre-wrap declaration
the pane calls for because Bootstrap has no utility for it (styling-rules.md,
same exception as HilosActionError). -->
<script setup lang="ts">
import {
  createHilosLogViewer,
  createSignal,
  hasLogViewerNodes,
  logLevelVariant,
  logViewerNodeOf,
  logViewerPaneState,
  logViewerStreamsOf,
  HILOS_LOG_LEVEL_OPTIONS,
  HilosPages,
  LOG_SOURCE_LIVE,
  type HilosLogViewerAddress,
  type HilosLogViewerContext,
  type HilosLogViewerEntry,
  type PageRouteMatch,
} from '@hilos/core'
import { computed, inject, onMounted, onUnmounted, ref } from 'vue'

import HilosAdminPage from '../../HilosAdminPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'

const props = defineProps<{
  /** The project context: the connection and the action lifecycle. */
  context: HilosLogViewerContext
}>()

const router = inject(hilosRouterKey)
// The address IS what this screen is showing, so the navigator is what it reads
// and writes. A router-less mount (none in practice) still reads files — it just
// keeps the choice out of the location bar.
const address: HilosLogViewerAddress = router ?? {
  currentRoute: createSignal<PageRouteMatch>({
    page: HilosPages.LOGS_VIEW,
    params: {},
    admin: true,
  }),
  replacePath: () => {},
}

const viewer = createHilosLogViewer(props.context, address)
onMounted(() => viewer.start())
onUnmounted(() => viewer.dispose())

const catalog = useSignal(viewer.catalog)
const selection = useSignal(viewer.selection)
const entries = useSignal(viewer.entries)
const level = useSignal(viewer.level)
const substring = useSignal(viewer.substring)
const busy = useSignal(viewer.busy)
const readable = useSignal(viewer.readable)
const hasMore = useSignal(viewer.hasMore)
const refusal = useSignal(viewer.refusal)

// The node select exists only where nodes have names: a picker with one nameless
// option is furniture for a choice that does not exist.
const clustered = computed(() => hasLogViewerNodes(catalog.value))
const node = computed(() =>
  logViewerNodeOf(catalog.value, selection.value.nodeId),
)
const streams = computed(() =>
  logViewerStreamsOf(node.value, selection.value.source),
)

// Which state the pane is in — the discrimination is the headless's, because it
// is the same question in all three view frameworks.
const paneState = computed(() =>
  logViewerPaneState(
    catalog.value,
    selection.value,
    entries.value.length,
    readable.value,
    level.value !== '' || substring.value !== '',
  ),
)

// Newest batch first: an operator opening the archive is looking for what just
// happened far more often than for what happened last month.
const batches = computed(() => [...(node.value?.batches ?? [])].reverse())

/** The value the source select carries for one choice. */
function sourceValue(source: typeof LOG_SOURCE_LIVE | number | null): string {
  return source === null ? '' : String(source)
}

function batchLabel(batch: number): string {
  return new Date(batch * 1000).toLocaleString()
}

function onNode(event: Event): void {
  viewer.select({ nodeId: (event.target as HTMLSelectElement).value })
}

function onSource(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  viewer.select({
    source: value === LOG_SOURCE_LIVE ? LOG_SOURCE_LIVE : Number(value),
  })
}

function onStream(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  viewer.select({ stream: value === '' ? null : value })
}

function onLevel(event: Event): void {
  viewer.setLevel((event.target as HTMLSelectElement).value)
}

function onSubstring(event: Event): void {
  viewer.setSubstring((event.target as HTMLInputElement).value)
}

// Which stacks are open, by entry key. The key survives a page of older lines
// arriving above, so an opened stack stays open when the pane grows upwards.
const opened = ref(new Set<string>())

function isOpen(entry: HilosLogViewerEntry): boolean {
  return opened.value.has(entry.key)
}

function toggle(entry: HilosLogViewerEntry): void {
  const next = new Set(opened.value)
  if (!next.delete(entry.key)) {
    next.add(entry.key)
  }
  opened.value = next
}
</script>

<template>
  <HilosAdminPage :page="HilosPages.LOGS_VIEW">
    <div class="border rounded-3 p-3 mb-3">
      <div class="row g-2 align-items-end">
        <div v-if="clustered" class="col-6 col-md-2">
          <label class="form-label small fw-semibold mb-1" for="hilos-log-node">
            Node
          </label>
          <select
            id="hilos-log-node"
            class="form-select form-select-sm"
            :value="selection.nodeId ?? ''"
            data-id="hilos-log-node"
            @change="onNode"
          >
            <option value="" disabled>Choose a node</option>
            <option
              v-for="entry in catalog?.nodes ?? []"
              :key="entry.nodeId"
              :value="entry.nodeId"
            >
              {{ entry.nodeId }}
            </option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label
            class="form-label small fw-semibold mb-1"
            for="hilos-log-source"
          >
            Source
          </label>
          <select
            id="hilos-log-source"
            class="form-select form-select-sm"
            :value="sourceValue(selection.source)"
            data-id="hilos-log-source"
            @change="onSource"
          >
            <option :value="LOG_SOURCE_LIVE">Live journal</option>
            <option v-for="batch in batches" :key="batch" :value="batch">
              Batch — {{ batchLabel(batch) }}
            </option>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label
            class="form-label small fw-semibold mb-1"
            for="hilos-log-stream"
          >
            Stream
          </label>
          <select
            id="hilos-log-stream"
            class="form-select form-select-sm"
            :value="selection.stream ?? ''"
            data-id="hilos-log-stream"
            @change="onStream"
          >
            <option value="">Choose a stream</option>
            <option
              v-for="stream in streams"
              :key="stream.key"
              :value="stream.key"
            >
              {{ stream.key }}
            </option>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label
            class="form-label small fw-semibold mb-1"
            for="hilos-log-level"
          >
            Level
          </label>
          <select
            id="hilos-log-level"
            class="form-select form-select-sm"
            :value="level"
            data-id="hilos-log-level"
            @change="onLevel"
          >
            <option
              v-for="option in HILOS_LOG_LEVEL_OPTIONS"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </option>
          </select>
        </div>
        <div class="col-12">
          <label class="visually-hidden" for="hilos-log-substring">
            Search inside the lines
          </label>
          <input
            id="hilos-log-substring"
            type="search"
            class="form-control form-control-sm"
            placeholder="Search inside the lines"
            :value="substring"
            data-id="hilos-log-substring"
            @change="onSubstring"
          />
        </div>
      </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <button
        type="button"
        class="btn btn-sm btn-outline-secondary"
        :disabled="!hasMore || busy"
        data-id="hilos-log-earlier"
        @click="viewer.readOlder()"
      >
        <i class="bi bi-arrow-up me-1" aria-hidden="true"></i>Earlier
      </button>
      <span class="small text-body-secondary" data-id="hilos-log-count">
        {{ entries.length }} entries shown
      </span>
    </div>

    <div
      class="border rounded-3 bg-body-tertiary py-2 overflow-auto"
      style="max-height: 26rem; white-space: pre-wrap"
      data-id="hilos-log-pane"
    >
      <p
        v-if="refusal"
        class="px-3 mb-0 small text-danger"
        data-id="hilos-log-refusal"
      >
        {{ refusal }}
      </p>
      <p
        v-else-if="paneState === 'unknown'"
        class="px-3 mb-0 small text-body-secondary"
        data-id="hilos-log-empty-unknown"
      >
        The cluster picture has not arrived yet, so there is nothing to choose
        between — not nothing to read.
      </p>
      <p
        v-else-if="paneState === 'unreadable'"
        class="px-3 mb-0 small text-body-secondary"
        data-id="hilos-log-empty-unreadable"
      >
        No node could read its log store.
      </p>
      <p
        v-else-if="paneState === 'empty'"
        class="px-3 mb-0 small text-body-secondary"
        data-id="hilos-log-empty-catalog"
      >
        No streams have been reported yet.
      </p>
      <p
        v-else-if="paneState === 'unchosen'"
        class="px-3 mb-0 small text-body-secondary"
        data-id="hilos-log-empty-unchosen"
      >
        Choose a stream to read.
      </p>
      <p
        v-else-if="paneState === 'missing'"
        class="px-3 mb-0 small text-body-secondary"
        data-id="hilos-log-empty-missing"
      >
        This file cannot be read. The rotation may have carried it off, or
        nothing has written to it yet.
      </p>
      <p
        v-else-if="paneState === 'nomatch'"
        class="px-3 mb-0 small text-body-secondary"
        data-id="hilos-log-empty-nomatch"
      >
        Nothing in this file matched.
      </p>
      <p
        v-else-if="paneState === 'silent'"
        class="px-3 mb-0 small text-body-secondary"
        data-id="hilos-log-empty-silent"
      >
        This file is empty.
      </p>
      <template v-else>
        <div
          v-for="entry in entries"
          :key="entry.key"
          data-id="hilos-log-entry"
        >
          <div
            class="d-flex gap-2 px-3 py-1 font-monospace small text-break border-start border-4"
            :class="[
              `border-${logLevelVariant(entry.level)}`,
              { 'opacity-75': entry.orphan },
            ]"
          >
            <span
              class="fw-semibold text-nowrap"
              :class="`text-${logLevelVariant(entry.level)}`"
            >
              {{ entry.level }}
            </span>
            <span class="text-body-secondary text-nowrap">{{
              entry.time
            }}</span>
            <span class="flex-grow-1">{{ entry.text }}</span>
            <button
              v-if="entry.frames.length > 0"
              type="button"
              class="btn btn-sm btn-link p-0 text-decoration-none text-nowrap"
              :aria-expanded="isOpen(entry)"
              :aria-label="`Call stack of this entry, ${entry.frames.length} frames`"
              data-id="hilos-log-stack-toggle"
              @click="toggle(entry)"
            >
              <i class="bi bi-info-circle me-1" aria-hidden="true"></i
              >{{ entry.frames.length }}
            </button>
          </div>
          <div
            v-if="entry.frames.length > 0 && isOpen(entry)"
            class="bg-body"
            data-id="hilos-log-stack"
          >
            <div
              v-for="(frame, index) in entry.frames"
              :key="index"
              class="d-flex gap-2 px-3 py-1 font-monospace small text-break opacity-75 border-start border-4"
              :class="`border-${logLevelVariant(entry.level)}`"
            >
              <span class="flex-grow-1">{{ frame.text }}</span>
            </div>
          </div>
        </div>
      </template>
    </div>
  </HilosAdminPage>
</template>
