<!-- HilosLogsViewPage — the framework Hilos log viewer (HilosPages.LOGS_VIEW):
the lines of ONE file on ONE node, for an administrator who came to work out what
already happened. The node, the source (the live journal or one archived batch)
and the stream together ARE the address of this screen, so choosing another file
rewrites the address in place rather than navigating — it is another file of the
same page, and a navigation would re-subscribe it and drop the catalog. Nothing
is filtered here: the level and the substring are fields of the read request, and
the server answers with what matched. The Follow switch runs the live tail, and
there is no Refresh button on purpose: freshness arrives as a push. Scrolling up
releases only the STICKING — the tail keeps running, what arrives while the
reader is up waits beside the pane, and the return control at the bottom carries
its count; so nothing ever moves under the reader's eyes, and nothing is lost
without saying so. The catalog, the address, the read, the buffer and the row
view-model are the core headless's (hilosLogViewer), including the threshold that
decides "at the tail" and the wording of the notes — this view owns only the
markup and the scrolling, so a project mounts it by passing its
HilosLogViewerContext and the React and Angular ports measure the same edge.
Bootstrap classes only, save the one pre-wrap declaration the pane calls for
because Bootstrap has no utility for it (styling-rules.md, same exception as
HilosActionError). -->
<script setup lang="ts">
import {
  createHilosLogViewer,
  createSignal,
  hasLogViewerNodes,
  isLogViewerPinned,
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
  type HilosLogViewerNotice,
  type PageRouteMatch,
} from '@hilos/core'
import {
  computed,
  inject,
  nextTick,
  onMounted,
  onUnmounted,
  ref,
  watch,
} from 'vue'

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
const rows = useSignal(viewer.rows)
const level = useSignal(viewer.level)
const substring = useSignal(viewer.substring)
const busy = useSignal(viewer.busy)
const readable = useSignal(viewer.readable)
const hasMore = useSignal(viewer.hasMore)
const refusal = useSignal(viewer.refusal)
const followRequested = useSignal(viewer.followRequested)
const following = useSignal(viewer.following)
const canFollow = useSignal(viewer.canFollow)
const pinned = useSignal(viewer.pinned)
const pendingLines = useSignal(viewer.pendingLines)

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
// is the same question in all three view frameworks. The count is of ROWS and
// not of entries: a file with no lines that has just been rotated has something
// to say, and "this file is empty" would hide the note saying it.
const paneState = computed(() =>
  logViewerPaneState(
    catalog.value,
    selection.value,
    rows.value.length,
    readable.value,
    level.value !== '' || substring.value !== '',
  ),
)

// The counter beside the Earlier button counts entries, because that is the word
// it uses; a note is not one.
const entryCount = computed(
  () => rows.value.filter((row) => row.kind === 'entry').length,
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

function onFollow(event: Event): void {
  viewer.setFollow((event.target as HTMLInputElement).checked)
}

/** The icon one note is drawn with; its wording belongs to the headless. */
const NOTICE_ICONS: Record<HilosLogViewerNotice, string> = {
  rotated: 'bi-arrow-repeat',
  skipped: 'bi-fast-forward',
  dropped: 'bi-scissors',
  stopped: 'bi-stop-circle',
}

// The pane is the scrolling element, so it is the one whose position answers
// "is the reader at the tail" — the threshold itself is the headless's, so that
// the React and Angular ports do not each invent their own.
const pane = ref<HTMLElement | null>(null)

function onScroll(): void {
  const element = pane.value
  if (element === null) {
    return
  }

  viewer.setPinned(
    isLogViewerPinned(
      element.scrollTop,
      element.scrollHeight,
      element.clientHeight,
    ),
  )
}

// Sticking to the bottom happens after the rows are drawn, because the height to
// scroll to does not exist until then (the same move as demo/chat's Main.vue).
watch(rows, async () => {
  if (!pinned.value) {
    return
  }

  await nextTick()
  const element = pane.value
  if (element !== null) {
    element.scrollTop = element.scrollHeight
  }
})

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
        <div class="col-6 col-md-2">
          <span class="form-label small fw-semibold mb-1 d-block">Follow</span>
          <div class="form-check form-switch mb-1">
            <input
              id="hilos-log-follow"
              class="form-check-input"
              type="checkbox"
              :checked="followRequested"
              :disabled="!canFollow"
              data-id="hilos-log-follow"
              @change="onFollow"
            />
            <label class="form-check-label small" for="hilos-log-follow">
              tail
            </label>
          </div>
          <span
            v-if="!canFollow"
            class="small text-body-secondary"
            data-id="hilos-log-follow-off"
          >
            An archived batch has no tail.
          </span>
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
        {{ entryCount }} entries shown
      </span>
      <span
        v-if="following"
        class="ms-auto badge text-bg-success-subtle text-success-emphasis border border-success-subtle"
        data-id="hilos-log-tail-badge"
      >
        <i class="bi bi-broadcast me-1" aria-hidden="true"></i>Tail is running
      </span>
    </div>

    <div class="position-relative">
      <div
        ref="pane"
        class="border rounded-3 bg-body-tertiary py-2 overflow-auto"
        style="max-height: 26rem; white-space: pre-wrap"
        data-id="hilos-log-pane"
        @scroll="onScroll"
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
          <template v-for="row in rows" :key="row.key">
            <div
              v-if="row.kind === 'notice'"
              class="d-flex align-items-center justify-content-center gap-2 px-3 py-1 small text-body-secondary"
              data-id="hilos-log-notice"
            >
              <i
                :class="`bi ${NOTICE_ICONS[row.notice]}`"
                aria-hidden="true"
              ></i>
              <span>{{ row.text }}</span>
            </div>
            <div v-else data-id="hilos-log-entry">
              <div
                class="d-flex gap-2 px-3 py-1 font-monospace small text-break border-start border-4"
                :class="[
                  `border-${logLevelVariant(row.level)}`,
                  { 'opacity-75': row.orphan },
                ]"
              >
                <span
                  class="fw-semibold text-nowrap"
                  :class="`text-${logLevelVariant(row.level)}`"
                >
                  {{ row.level }}
                </span>
                <span class="text-body-secondary text-nowrap">{{
                  row.time
                }}</span>
                <span class="flex-grow-1">{{ row.text }}</span>
                <button
                  v-if="row.frames.length > 0"
                  type="button"
                  class="btn btn-sm btn-link p-0 text-decoration-none text-nowrap"
                  :aria-expanded="isOpen(row)"
                  :aria-label="`Call stack of this entry, ${row.frames.length} frames`"
                  data-id="hilos-log-stack-toggle"
                  @click="toggle(row)"
                >
                  <i class="bi bi-info-circle me-1" aria-hidden="true"></i
                  >{{ row.frames.length }}
                </button>
              </div>
              <div
                v-if="row.frames.length > 0 && isOpen(row)"
                class="bg-body"
                data-id="hilos-log-stack"
              >
                <div
                  v-for="(frame, index) in row.frames"
                  :key="index"
                  class="d-flex gap-2 px-3 py-1 font-monospace small text-break opacity-75 border-start border-4"
                  :class="`border-${logLevelVariant(row.level)}`"
                >
                  <span class="flex-grow-1">{{ frame.text }}</span>
                </div>
              </div>
            </div>
          </template>
        </template>
      </div>
      <button
        v-if="!pinned"
        type="button"
        class="btn btn-sm btn-primary position-absolute bottom-0 start-50 translate-middle-x mb-2"
        data-id="hilos-log-back-to-tail"
        @click="viewer.returnToTail()"
      >
        Back to the tail{{ pendingLines > 0 ? ` · ${pendingLines} new` : '' }}
      </button>
    </div>
  </HilosAdminPage>
</template>
