<script setup lang="ts">
import {
  computed,
  defineComponent,
  effectScope,
  h,
  nextTick,
  onScopeDispose,
  provide,
  ref,
  shallowRef,
  watch,
  type PropType,
} from 'vue'
import {
  Scope,
  TableViewportController,
  HIDDEN_VALUE,
  hilosTableRemovalReason,
  ingest,
  subscribeSignal,
  type EntityRef,
  type Hideable,
  type HilosTableFrame,
  type HilosTableLineCount,
  type HilosTableRowHeight,
  type HilosTableTextRem,
  type TableRow,
  type TableViewportDescriptor,
} from '@hilos/core/base'
import {
  HilosModal,
  HilosTableText,
  HilosViewportTable,
  hilosTableSelectionEdgeKey,
  useSignal,
} from '@hilos/vue'

interface RecordValue {
  readonly name: string
  readonly paragraph: string
  readonly hiddenParagraph: boolean
  readonly badge: string
  readonly detail: string
  readonly expandable: boolean
  readonly picture: string
}

const KEYS = ['a', 'b', 'c', 'd', 'e', 'f', 'g'] as const
const TARGETS = [...KEYS, 'h', 'i', 'j']
type Key = string
const SOURCE = 'record'
const PARAGRAPH =
  'A paragraph near its wrapping boundary keeps the same lines when the widest name leaves the list. The surviving content must keep exactly these lines throughout every departure.'
const EXPANDED = `${PARAGRAPH} ${PARAGRAPH} ${PARAGRAPH}`
const target = ref<Key>('a')
const reason = ref('deleted')
const stale = ref(false)
const pending = ref<'none' | 'move' | 'remove'>('none')
const detail = ref<'absent' | 'collapsed' | 'expanded'>('collapsed')
const expandedText = ref(false)
const heightMode = ref<'default' | 'content' | 'lines' | 'height'>('default')
const lineCount = ref<HilosTableLineCount>(3)
const textRem = ref<HilosTableTextRem>(4.5)
const heightContent = ref<'text' | 'parts'>('text')
const mountedHeightContent = ref<'text' | 'parts'>('text')
const readingSamples = ref(false)
const payload = ref('long')
const LINE_COUNTS: readonly HilosTableLineCount[] = [
  1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12,
]
const TEXT_REMS: readonly HilosTableTextRem[] = [2, 3, 4.5, 6, 9, 12]
const TEXT_SAMPLES = {
  short: 'Short value',
  empty: '',
  long: `${Array.from({ length: 24 }, () => PARAGRAPH).join(' ')} LAST READABLE LINE`,
  longer: `${Array.from({ length: 48 }, () => PARAGRAPH).join(' ')} LAST READABLE LINE`,
  newline: `${Array.from({ length: 16 }, (_, index) => `Explicit line ${index + 1}`).join('\n')}\nLAST READABLE LINE`,
  token: `${'UnbrokenToken'.repeat(200)}ENDTOKEN`,
  hidden: '',
} as const
const edge = ref<'start' | 'end'>('start')
const SelectionEdge = defineComponent({
  props: {
    selectedEdge: { type: String as PropType<'start' | 'end'>, required: true },
  },
  setup(props, { slots }) {
    provide(hilosTableSelectionEdgeKey, props.selectedEdge)
    return () => h('div', slots.default?.())
  },
})
const scope = new Scope('page', 'table-geometry')
const revision = ref(0)
const syncSeen = ref(false)
const tableMounted = ref(true)
const requests = ref<readonly TableViewportDescriptor[]>([])
const navigation = ref('search')
const snapshot = ref('short')
const position = ref(0)
const iconActions = ref(false)
const focusModalOpen = ref(false)
const tilesEnabled = ref(false)
const ambiguousFocus = ref(false)
// Self-contained pictures exercise native image decoding without a file service.
const PICTURES = ['circle', 'rect', 'ellipse'].map(
  (shape) =>
    `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="160" height="120" viewBox="0 0 160 120"><${shape} fill="steelblue" x="20" y="20" width="120" height="80" cx="80" cy="60" r="40" rx="60" ry="40"/></svg>`)}`,
)
const ARCHIVE_COLUMNS = [
  { key: 'environment', label: 'Environment', value: 'Production' },
  { key: 'scope', label: 'Scope', value: 'Full' },
  { key: 'size', label: 'Size', value: '25.6 MB' },
  { key: 'checksum', label: 'Checksum', value: 'Verified' },
  { key: 'copy', label: 'Copy', value: 'Copied' },
  { key: 'duration', label: 'Duration', value: '2.7 s' },
  { key: 'restore', label: 'Restore', value: 'No restore' },
] as const
const actionLog = ref<string[]>([])
let previousController: TableViewportController<RecordValue> | null = null

const frame: HilosTableFrame = {
  title: 'Geometry records',
  subtitle: 'Synthetic controller ingress; no server delivery is claimed.',
  search: { placeholder: 'Find geometry records' },
  filters: [{ kind: 'toggle', key: 'status', label: 'Paused', on: 'paused' }],
  columns: () => [
    {
      key: 'name',
      label: 'Name',
      source: SOURCE,
      sortable: true,
      card: 'title',
    },
    {
      key: 'paragraph',
      label: 'Description',
      heightContent: mountedHeightContent.value,
      progress: true,
      source: SOURCE,
    },
    { key: 'badge', label: 'Status', card: 'badge', source: SOURCE },
    ...(iconActions.value
      ? ARCHIVE_COLUMNS.map(({ key, label }) => ({
          key,
          label,
          cellClass: 'text-nowrap',
        }))
      : []),
    {
      key: 'actions',
      label: iconActions.value ? '' : 'Actions',
      cellClass: iconActions.value ? 'text-end' : undefined,
      reads: [],
      card: 'field',
    },
    { key: 'detail', label: 'Expanded detail', detail: true, detailWide: true },
  ],
  bulkActions: [
    {
      key: 'inspect',
      label: 'Inspect',
      refusalTitle: 'Could not inspect',
      run: () => {
        throw new Error('This fixture does not send bulk actions')
      },
    },
  ],
}

function entityRef(key: Key): EntityRef {
  return { type: SOURCE, id: key }
}

function raw(key: Key): TableRow {
  return { rowKey: key, slots: { [SOURCE]: entityRef(key) } }
}

function resolve(row: TableRow): RecordValue {
  const fields = scope.entities
    .signal(row.slots[SOURCE] as EntityRef)
    .get()?.fields
  return {
    name: String(fields?.name ?? ''),
    paragraph: String(fields?.paragraph ?? ''),
    hiddenParagraph: fields?.hiddenParagraph === true,
    badge: String(fields?.badge ?? ''),
    detail: String(fields?.detail ?? ''),
    expandable: fields?.expandable !== false,
    picture: String(fields?.picture ?? ''),
  }
}

function makeController(search = true): TableViewportController<RecordValue> {
  const withTiles = tilesEnabled.value
  const rowHeight: HilosTableRowHeight | undefined =
    heightMode.value === 'default'
      ? undefined
      : heightMode.value === 'lines'
        ? { mode: 'lines', count: lineCount.value }
        : heightMode.value === 'height'
          ? { mode: 'height', rem: textRem.value }
          : { mode: 'content' }
  return new TableViewportController<RecordValue>({
    frame: {
      ...frame,
      rowHeight,
      search: search ? frame.search : undefined,
      ...(withTiles
        ? {
            views: {
              tableKey: 'geometry-records',
              order: ['table', 'tiles'],
              imageColumn: 'picture',
            },
            columns: () => [
              { key: 'picture', label: 'Picture', source: SOURCE },
              ...(typeof frame.columns === 'function'
                ? frame.columns()
                : frame.columns
              ).map((column) =>
                column.key === 'badge' ? { ...column, sortable: true } : column,
              ),
            ],
          }
        : {}),
    },
    declaredOrders: withTiles
      ? [
          {
            key: 'name-status',
            components: [
              { field: 'name', direction: 'asc' },
              { field: 'badge', direction: 'desc' },
            ],
          },
          {
            key: 'status-name',
            components: [
              { field: 'badge', direction: 'asc' },
              { field: 'name', direction: 'desc' },
            ],
          },
        ]
      : undefined,
    resolve,
    expandable: (row) => row.expandable,
    sendViewport: (descriptor) => {
      requests.value = [...requests.value, descriptor]
    },
  })
}
const controller = shallowRef(makeController())
function mirrors() {
  return {
    rows: useSignal(controller.value.rows),
    footer: useSignal(controller.value.frame.footer),
    body: useSignal(controller.value.frame.body),
    selected: useSignal(controller.value.selection.target),
    expandedText: useSignal(controller.value.expandedText),
    tableWork: useSignal(controller.value.progress.table),
    bulkWork: useSignal(controller.value.progress.bulk),
    report: useSignal(controller.value.bulk.report),
    view: useSignal(controller.value.frame.view),
  }
}
let mirrorScope = effectScope()
const current = shallowRef(mirrorScope.run(mirrors)!)
watch(controller, () => {
  mirrorScope.stop()
  mirrorScope = effectScope()
  current.value = mirrorScope.run(mirrors)!
})
const rows = computed(() => current.value.rows.value)
const footer = computed(() => current.value.footer.value)
const body = computed(() => current.value.body.value)
const selected = computed(() => current.value.selected.value)
const tableView = computed(() => current.value.view.value)
const identities = computed(() =>
  rows.value.map((row) => `${row.rowKey}:${row.occurrence}`).join(','),
)
const summary = computed(() =>
  rows.value
    .map(
      (row) =>
        `${row.rowKey}:${row.placeholder ? 'departed' : (row.pending ?? 'live')}`,
    )
    .join(','),
)

function write(key: Key, fields: Partial<RecordValue>): void {
  ingest(scope, { entities: { [SOURCE]: { id: key, ...fields } } })
}

function reset(): void {
  for (const key of TARGETS) {
    write(key, {
      name:
        key === 'b'
          ? 'The widest unbroken name WWWWWWWWWWWWWWWW'
          : `Record ${key}`,
      paragraph: key === 'c' ? EXPANDED : PARAGRAPH,
      hiddenParagraph: false,
      badge: 'Available',
      detail: `Private detail for ${key}. ${EXPANDED}`,
      expandable: true,
      picture: key === 'd' ? '' : PICTURES[TARGETS.indexOf(key) % 3]!,
    })
  }
  if (readingSamples.value) {
    for (const [key, sample] of [
      ['a', 'long'],
      ['b', 'short'],
      ['c', 'empty'],
      ['d', 'newline'],
      ['e', 'token'],
      ['f', mountedHeightContent.value === 'parts' ? 'hidden' : 'short'],
      ['g', 'short'],
    ] as const) {
      write(key, {
        name: `Record ${key}`,
        paragraph: TEXT_SAMPLES[sample],
        hiddenParagraph: sample === 'hidden',
      })
    }
  }
  controller.value.ingestWindow(
    KEYS.map(raw),
    KEYS.length,
    true,
    null,
    null,
    10,
  )
  if (tilesEnabled.value) {
    controller.value.ingestSubscriptionWindow(
      KEYS.map(raw),
      KEYS.length,
      true,
      null,
      null,
      10,
      [{ field: 'name', direction: 'asc' }],
      [],
    )
  }
  stale.value = false
  pending.value = 'none'
  detail.value = 'collapsed'
  expandedText.value = false
  syncSeen.value = false
  revision.value++
}

function configure(): void {
  const key = target.value
  controller.value.withPresentationUpdate(
    { kind: 'rows', rowKeys: [key] },
    () => {
      write(key, {
        expandable: detail.value !== 'absent',
        paragraph: expandedText.value ? EXPANDED : PARAGRAPH,
      })
      controller.value.ingestDelta({
        kind: 'row_stale',
        rowKey: key,
        staleSources: stale.value ? [SOURCE] : [],
      })
      controller.value.expandRow(key, detail.value === 'expanded')
      if (pending.value === 'move') {
        controller.value.ingestDelta({
          kind: 'row_moved',
          rowKey: key,
          row: raw(key),
          position: 1,
        })
      } else if (pending.value === 'remove') {
        depart(false)
      }
    },
  )
  revision.value++
}

function work(ended = false): void {
  controller.value.ingestProgress({
    scope: 'row',
    rowKey: target.value,
    progressKey: `work-${target.value}`,
    current: 2,
    total: 7,
    detail: { label: `Private progress for ${target.value}. ${PARAGRAPH}` },
    ended,
  })
  revision.value++
}

function depart(own: boolean): void {
  controller.value.ingestDelta({
    kind: 'row_removed',
    rowKey: target.value,
    reason: hilosTableRemovalReason(reason.value),
    own,
  })
  revision.value++
}

function batch(): void {
  controller.value.withPresentationUpdate({ kind: 'window' }, () => {
    for (const rowKey of ['a', 'b', 'c', 'g']) {
      controller.value.ingestDelta({
        kind: 'row_removed',
        rowKey,
        reason: hilosTableRemovalReason(reason.value),
      })
    }
    controller.value.apply()
  })
  revision.value++
}

function apply(): void {
  controller.value.apply()
  revision.value++
}

function badge(): void {
  controller.value.withPresentationUpdate(
    { kind: 'rows', rowKeys: [target.value] },
    () => {
      write(target.value, { badge: 'Paused' })
      controller.value.ingestDelta({
        kind: 'row_updated',
        rowKey: target.value,
        row: {
          ...raw(target.value),
          staleSources: stale.value ? [SOURCE] : [],
        },
        own: true,
      })
    },
  )
  revision.value++
}

function reuse(): void {
  const key = target.value
  controller.value.withPresentationUpdate({ kind: 'window' }, () => {
    depart(true)
    write(key, {
      name: `Replacement ${key}`,
      paragraph: 'New short value',
      detail: 'New detail',
      expandable: true,
    })
    controller.value.ingestOwnCreate(raw(key), 0, KEYS.length, true, null, null)
  })
  revision.value++
}

// The real normalizer publishes to a real SDK entity signal synchronously.
// Its subscriber closes the detail and removes the row inside the existing
// presentation boundary, before Vue's queued patch replaces the connected DOM.
function normalizedDeparture(): void {
  const key = target.value
  const stop = subscribeSignal(scope.entities.signal(entityRef(key)), () => {
    syncSeen.value = true
    controller.value.expandRow(key, false)
    depart(true)
  })
  try {
    controller.value.withPresentationUpdate(
      { kind: 'rows', rowKeys: [key] },
      () => {
        write(key, {
          name: 'Normalized replacement',
          paragraph: 'short',
          detail: '',
        })
      },
    )
  } finally {
    stop()
  }
  revision.value++
}

function count(value: number): void {
  controller.value.ingestCount(value, true, value > 7)
  revision.value++
}

function append(): void {
  controller.value.ingestAppend(
    raw(target.value),
    footer.value.totalCount + 1,
    true,
    { name: 'a' },
    { name: target.value },
  )
  revision.value++
}

function ownCreate(): void {
  controller.value.ingestOwnCreate(
    raw(target.value),
    position.value,
    footer.value.totalCount + 1,
    true,
    { name: 'a' },
    { name: target.value },
  )
  revision.value++
}

function move(own: boolean): void {
  controller.value.ingestDelta({
    kind: 'row_moved',
    rowKey: target.value,
    row: raw(target.value),
    position: position.value,
    own,
  })
  revision.value++
}

function changeWindow(): void {
  const owner = controller.value
  switch (navigation.value) {
    case 'search':
      owner.setSearch('needle')
      break
    case 'filter':
      owner.setFilter('status', 'paused')
      break
    case 'order':
      owner.setOrder([{ field: 'name', direction: 'desc' }])
      break
    case 'page':
      owner.setPage(1)
      break
    case 'show':
      owner.show()
      break
    case 'refresh':
      owner.refresh()
      break
    case 'backward':
      owner.prevPage()
      break
  }
  revision.value++
}

function deliver(reconnect = false): void {
  const keys =
    snapshot.value === 'empty'
      ? []
      : snapshot.value === 'capacity'
        ? ['a', 'b', 'c']
        : KEYS
  if (snapshot.value === 'short') {
    for (const key of keys)
      write(key, {
        name: `Fresh ${key}`,
        paragraph: 'Fresh short value',
        detail: 'Fresh detail',
        expandable: true,
      })
  }
  const limit = snapshot.value === 'capacity' ? 3 : 10
  const total = snapshot.value === 'capacity' ? 30 : keys.length
  if (reconnect) {
    controller.value.ingestSubscriptionWindow(
      keys.map(raw),
      total,
      true,
      { name: 'a' },
      { name: 'g' },
      limit,
      [{ field: 'name', direction: 'asc' }],
      [],
      0,
    )
  } else {
    // A requested window completes only with its request id. Later synthetic
    // snapshots remain unsolicited once that request has already completed.
    const requestId = requests.value.at(-1)?.requestId
    controller.value.ingestWindow(
      keys.map(raw),
      total,
      true,
      { name: 'a' },
      { name: 'g' },
      limit,
      snapshot.value === 'capacity' ? 6 : 0,
      controller.value.acceptsWindowResponse(requestId) ? requestId : undefined,
    )
  }
  revision.value++
}

function replace(search = true): void {
  previousController?.dispose()
  previousController = controller.value
  mountedHeightContent.value = heightContent.value
  controller.value = makeController(search)
  reset()
}

function startReading(): void {
  readingSamples.value = true
  replace()
}

function readingValue(row: RecordValue): Hideable<string> {
  return row.hiddenParagraph ? HIDDEN_VALUE : row.paragraph
}

function changeText(): void {
  const sample = payload.value as keyof typeof TEXT_SAMPLES
  controller.value.withPresentationUpdate(
    { kind: 'rows', rowKeys: [target.value] },
    () => {
      write(target.value, {
        paragraph: TEXT_SAMPLES[sample],
        hiddenParagraph: sample === 'hidden',
      })
      controller.value.ingestDelta({
        kind: 'row_updated',
        rowKey: target.value,
        row: raw(target.value),
      })
    },
  )
  revision.value++
}

async function immediateDeparture(): Promise<void> {
  // Change connected full text, flush Vue, and remove in the same task before
  // ResizeObserver's next delivery. The SDK's sync capture must read this DOM.
  changeText()
  await nextTick()
  depart(true)
}

function updateLive(): void {
  controller.value.withPresentationUpdate(
    { kind: 'rows', rowKeys: [target.value] },
    () => {
      write(target.value, {
        name: `Updated ${target.value}`,
        paragraph: EXPANDED,
        detail: `Updated detail. ${EXPANDED} ${EXPANDED}`,
      })
      controller.value.ingestDelta({
        kind: 'row_updated',
        rowKey: target.value,
        row: raw(target.value),
      })
    },
  )
  revision.value++
}

function announce(): void {
  controller.value.ingestAnnounce(
    'j',
    'inside',
    footer.value.totalCount + 1,
    true,
  )
  revision.value++
}

function setWork(): void {
  for (const scope of ['table', 'bulk'] as const)
    controller.value.ingestProgress({
      scope,
      progressKey: `set-${scope}`,
      current: 1,
      total: 3,
    })
  controller.value.ingestBulkReport({
    progressKey: 'finished',
    touched: 2,
    untouched: [],
    untouchedOmitted: 0,
  })
  revision.value++
}

async function churn(): Promise<void> {
  for (let index = 0; index < 24; index++) {
    const key = `cycle-${index}`
    write(key, {
      name: key,
      paragraph: 'Short value',
      badge: 'Available',
      detail: '',
      expandable: false,
    })
    controller.value.ingestAppend(
      raw(key),
      1,
      true,
      { name: key },
      { name: key },
    )
    await nextTick()
    await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()))
    controller.value.ingestDelta({
      kind: 'row_removed',
      rowKey: key,
      reason: 'deleted',
      own: true,
    })
    await nextTick()
    await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()))
  }
  controller.value.ingestCount(0, true)
  revision.value++
}

reset()
onScopeDispose(() => {
  mirrorScope.stop()
  previousController?.dispose()
  controller.value.dispose()
})
</script>

<template>
  <main class="container-fluid py-3" data-id="geometry-fixture">
    <h1 class="h4">Table geometry fixture</h1>
    <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
      <label class="form-check"
        ><input
          v-model="tilesEnabled"
          class="form-check-input"
          type="checkbox"
          data-id="geometry-tiles"
          @change="replace()"
        />Declare table views and pictures</label
      >
      <label class="form-check"
        ><input
          v-model="ambiguousFocus"
          class="form-check-input"
          type="checkbox"
          data-id="geometry-ambiguous-focus"
        />Duplicate marked action</label
      >
      <label
        >Target
        <select v-model="target" class="form-select" data-id="geometry-target">
          <option v-for="key in TARGETS" :key="key" :value="key">
            {{ key }}
          </option>
        </select>
      </label>
      <label
        >Reason
        <select v-model="reason" class="form-select" data-id="geometry-reason">
          <option value="deleted">Deleted</option>
          <option value="left_set">Left set</option>
          <option value="moved_out">Moved out</option>
          <option value="future_reason">Unknown</option>
        </select>
      </label>
      <label
        >Pending
        <select
          v-model="pending"
          class="form-select"
          data-id="geometry-pending"
        >
          <option value="none">None</option>
          <option value="move">Move</option>
          <option value="remove">Remove</option>
        </select>
      </label>
      <label
        >Detail
        <select v-model="detail" class="form-select" data-id="geometry-detail">
          <option value="absent">Absent</option>
          <option value="collapsed">Collapsed</option>
          <option value="expanded">Expanded</option>
        </select>
      </label>
      <label class="form-check"
        ><input
          v-model="stale"
          class="form-check-input"
          type="checkbox"
          data-id="geometry-stale"
        />Stale</label
      >
      <label class="form-check"
        ><input
          v-model="expandedText"
          class="form-check-input"
          type="checkbox"
          data-id="geometry-text"
        />Long synthetic paragraph</label
      >
      <label
        >Selection edge
        <select v-model="edge" class="form-select" data-id="geometry-edge">
          <option value="start">Start</option>
          <option value="end">End</option>
        </select>
      </label>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
      <label
        >Row height
        <select
          v-model="heightMode"
          class="form-select"
          data-id="geometry-height-mode"
        >
          <option
            v-for="mode in ['default', 'content', 'lines', 'height']"
            :key="mode"
            :value="mode"
          >
            {{ mode }}
          </option>
        </select>
      </label>
      <label
        >Lines
        <select
          v-model="lineCount"
          class="form-select"
          data-id="geometry-lines"
        >
          <option v-for="value in LINE_COUNTS" :key="value" :value="value">
            {{ value }}
          </option>
        </select>
      </label>
      <label
        >Rem
        <select v-model="textRem" class="form-select" data-id="geometry-rem">
          <option v-for="value in TEXT_REMS" :key="value" :value="value">
            {{ value }}
          </option>
        </select>
      </label>
      <label
        >Height content
        <select
          v-model="heightContent"
          class="form-select"
          data-id="geometry-height-content"
        >
          <option value="text">text</option>
          <option value="parts">parts</option>
        </select>
      </label>
      <button
        type="button"
        class="btn btn-secondary"
        data-id="geometry-reading"
        @click="startReading"
      >
        Create reading controller
      </button>
      <label
        >Live text
        <select
          v-model="payload"
          class="form-select"
          data-id="geometry-payload"
        >
          <option
            v-for="sample in Object.keys(TEXT_SAMPLES)"
            :key="sample"
            :value="sample"
          >
            {{ sample }}
          </option>
        </select>
      </label>
      <button
        type="button"
        class="btn btn-secondary"
        data-id="geometry-value"
        @click="changeText"
      >
        Publish text
      </button>
      <button
        type="button"
        class="btn btn-secondary"
        data-id="geometry-immediate"
        @click="immediateDeparture"
      >
        Publish and remove before observer
      </button>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button class="btn btn-secondary" data-id="geometry-reset" @click="reset">
        Reset
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-configure"
        @click="configure"
      >
        Configure
      </button>
      <button class="btn btn-secondary" data-id="geometry-work" @click="work()">
        Start work
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-complete"
        @click="work(true)"
      >
        Complete work
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-remove"
        @click="depart(false)"
      >
        Foreign removal
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-own"
        @click="depart(true)"
      >
        Own removal
      </button>
      <button class="btn btn-secondary" data-id="geometry-batch" @click="batch">
        Batch and Apply
      </button>
      <button class="btn btn-secondary" data-id="geometry-apply" @click="apply">
        Apply
      </button>
      <button class="btn btn-secondary" data-id="geometry-reuse" @click="reuse">
        Own key reuse
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-normalize"
        @click="normalizedDeparture"
      >
        Normalize and remove
      </button>
      <button class="btn btn-secondary" data-id="geometry-badge" @click="badge">
        Change badge and highlight
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-unmount"
        @click="tableMounted = false"
      >
        Unmount table
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-mount"
        @click="tableMounted = true"
      >
        Mount table
      </button>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <label class="form-check"
        ><input
          v-model="iconActions"
          class="form-check-input"
          type="checkbox"
          data-id="geometry-icon-actions"
        />Icon action group</label
      >
      <output data-id="geometry-actions">{{ actionLog.join(',') }}</output>
      <label
        >Window request<select
          v-model="navigation"
          class="form-select"
          data-id="geometry-navigation"
        >
          <option
            v-for="kind in [
              'search',
              'filter',
              'order',
              'page',
              'show',
              'refresh',
              'backward',
            ]"
            :key="kind"
            :value="kind"
          >
            {{ kind }}
          </option>
        </select></label
      >
      <label
        >Snapshot<select
          v-model="snapshot"
          class="form-select"
          data-id="geometry-snapshot"
        >
          <option
            v-for="kind in ['short', 'initial', 'empty', 'capacity']"
            :key="kind"
            :value="kind"
          >
            {{ kind }}
          </option>
        </select></label
      >
      <label
        >Position<input
          v-model.number="position"
          class="form-control"
          type="number"
          data-id="geometry-position"
      /></label>
      <button
        class="btn btn-secondary"
        data-id="geometry-count-zero"
        @click="count(0)"
      >
        Count zero
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-count-positive"
        @click="count(30)"
      >
        Count positive
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-append"
        @click="append"
      >
        Append
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-create"
        @click="ownCreate"
      >
        Own create
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-move"
        @click="move(false)"
      >
        Move
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-own-move"
        @click="move(true)"
      >
        Own move
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-window"
        @click="changeWindow"
      >
        Request window
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-deliver"
        @click="deliver()"
      >
        Deliver snapshot
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-reconnect"
        @click="deliver(true)"
      >
        Reconnect snapshot
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-refuse"
        @click="controller.ingestRefusal('forbidden')"
      >
        Refuse
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-replace"
        @click="replace()"
      >
        Replace controller
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-no-search"
        @click="replace(false)"
      >
        Controller without search
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-old"
        @click="previousController?.ingestRefusal('old')"
      >
        Old controller reply
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-live"
        @click="updateLive"
      >
        Live value and detail
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-announce"
        @click="announce"
      >
        Announce
      </button>
      <button class="btn btn-secondary" data-id="geometry-churn" @click="churn">
        Append/delete churn
      </button>
      <button
        class="btn btn-secondary"
        data-id="geometry-set-work"
        @click="setWork"
      >
        Set work and report
      </button>
    </div>
    <output
      class="d-block mb-2"
      data-id="geometry-state"
      :data-revision="revision"
      :data-sync="syncSeen"
      :data-count="footer.totalCount"
      :data-range="`${footer.firstRow}:${footer.lastRow}`"
      :data-body="body"
      :data-selected="JSON.stringify(selected)"
      :data-view="tableView"
      :data-expanded="
        JSON.stringify(
          rows.filter((row) => row.expanded).map((row) => row.rowKey),
        )
      "
      :data-text-intent="
        JSON.stringify(
          [...current.expandedText.value].map(([key, columns]) => [
            key,
            [...columns],
          ]),
        )
      "
      :data-identities="identities"
      :data-requests="requests.length"
      :data-descriptor="
        JSON.stringify(requests.at(-1) ?? controller.descriptor())
      "
      :data-set-work="`${current.tableWork.value?.progressKey}:${current.bulkWork.value?.progressKey}:${current.report.value?.progressKey}`"
      >{{ summary }}</output
    >
    <div data-id="geometry-body">
      <SelectionEdge v-if="tableMounted" :key="edge" :selected-edge="edge">
        <HilosViewportTable :controller="controller">
          <template #cell-picture="{ row, rowKey }">
            <img
              v-if="row.picture"
              :src="row.picture"
              :alt="`Test picture ${rowKey}`"
              class="w-100 h-100 object-fit-cover"
              :data-id="`geometry-picture-${rowKey}`"
            />
          </template>
          <template #cell-name="{ row, rowKey }"
            ><span :data-id="`geometry-name-${rowKey}`">{{
              iconActions ? `2026-10-21 11:13 ${rowKey}` : row.name
            }}</span></template
          >
          <template #cell-paragraph="{ row, rowKey }"
            ><div
              v-if="mountedHeightContent === 'parts'"
              :data-id="`geometry-text-${rowKey}`"
            >
              <HilosTableText :value="readingValue(row)" />
              <span class="badge text-bg-info" data-id="geometry-text-badge"
                >Plain text metadata</span
              >
              <a href="#geometry-reading-note" data-id="geometry-text-link"
                >Reading note</a
              >
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-id="geometry-text-action"
                @click="actionLog.push(`${rowKey}:text-action`)"
              >
                Inspect text
              </button>
            </div>
            <span v-else :data-id="`geometry-text-${rowKey}`">{{
              iconActions ? 'Completed archive' : row.paragraph
            }}</span></template
          >
          <template #cell-badge="{ row, rowKey }"
            ><span
              class="badge text-bg-success"
              :data-id="`geometry-badge-${rowKey}`"
              >{{ row.badge }}</span
            ></template
          >
          <template
            v-for="column in ARCHIVE_COLUMNS"
            :key="column.key"
            #[`cell-${column.key}`]
            >{{ column.value }}</template
          >
          <template #cell-actions="{ rowKey }">
            <template v-if="iconActions">
              <button
                v-for="[action, icon] in [
                  ['details', 'exclamation-circle'],
                  ['restore', 'arrow-counterclockwise'],
                  ['delete', 'trash'],
                ]"
                :key="action"
                type="button"
                class="btn btn-sm btn-outline-secondary"
                :aria-label="action"
                :data-id="`geometry-${action}-${rowKey}`"
                @click="actionLog.push(`${rowKey}:${action}`)"
              >
                <i :class="`bi bi-${icon}`" aria-hidden="true"></i>
              </button>
            </template>
            <button
              v-else
              class="btn btn-sm btn-outline-secondary"
              :data-id="`geometry-action-${rowKey}`"
              data-hilos-focus-return="inspect-geometry"
              @click="focusModalOpen = true"
            >
              Inspect
            </button>
            <button
              v-if="ambiguousFocus && !iconActions"
              type="button"
              class="btn btn-sm btn-outline-secondary"
              :data-id="`geometry-action-copy-${rowKey}`"
              data-hilos-focus-return="inspect-geometry"
              @click="focusModalOpen = true"
            >
              Inspect again
            </button>
          </template>
          <template #detail-detail="{ row, rowKey }"
            ><p class="mb-0" :data-id="`geometry-detail-${rowKey}`">
              {{ row.detail }}
            </p>
            <button
              class="btn btn-sm btn-outline-secondary"
              :data-id="`geometry-detail-action-${rowKey}`"
            >
              Detail action
            </button></template
          >
          <template #row-progress="{ progress, rowKey }"
            ><p class="mb-0" :data-id="`geometry-work-${rowKey}`">
              {{ progress.detail.label }}
            </p></template
          >
        </HilosViewportTable>
      </SelectionEdge>
    </div>
    <p id="geometry-reading-note" class="small">
      Synthetic text samples exercise the public SDK; they do not prove server
      delivery.
    </p>
    <HilosModal
      v-model="focusModalOpen"
      title="Inspect geometry record"
      initial-focus="dialog"
    />
  </main>
</template>
