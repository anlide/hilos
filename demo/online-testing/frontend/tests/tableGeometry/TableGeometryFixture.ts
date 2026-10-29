import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core'
import {
  HilosHiddenMark,
  HilosModal,
  HilosTableCell,
  HilosTableDetail,
  HilosTableText,
  HilosViewportTable,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import {
  HIDDEN_VALUE,
  Scope,
  TableViewportController,
  hilosTableRemovalReason,
  ingest,
  isHiddenValue,
  subscribeSignal,
  type EntityRef,
  type HilosTableFrame,
  type HilosTableLineCount,
  type HilosTableProgress,
  type HilosTableRowHeight,
  type HilosTableTextRem,
  type Hideable,
  type TableRow,
  type TableViewportDescriptor,
} from '@hilos/core/base'

interface RecordValue {
  readonly name: string
  readonly paragraph: string
  readonly hiddenParagraph: boolean
  readonly badge: string
  readonly detail: string
  readonly expandable: boolean
}

const KEYS = ['a', 'b', 'c', 'd', 'e', 'f', 'g'] as const
const TARGETS = [...KEYS, 'h', 'i', 'j']
const SOURCE = 'record'
const PARAGRAPH =
  'A paragraph near its wrapping boundary keeps the same lines when the widest name leaves the list. The surviving content must keep exactly these lines throughout every departure.'
const EXPANDED = `${PARAGRAPH} ${PARAGRAPH} ${PARAGRAPH}`
type HeightMode = 'default' | 'content' | 'lines' | 'height'
type HeightContent = 'text' | 'parts'
const HEIGHT_MODES = ['default', 'content', 'lines', 'height'] as const
const HEIGHT_CONTENTS = ['text', 'parts'] as const
const LINE_COUNTS: readonly HilosTableLineCount[] = [
  1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12,
]
const TEXT_REMS: readonly HilosTableTextRem[] = [2, 3, 4.5, 6, 9, 12]
const TEXT_SAMPLES = {
  short: 'Short value',
  empty: '',
  long: `${Array.from({ length: 24 }, () => PARAGRAPH).join(' ')} LAST READABLE LINE`,
  newlines: `${Array.from({ length: 16 }, (_, index) => `Explicit line ${index + 1}`).join('\n')}\nLAST READABLE LINE`,
  token: `${'UnbrokenToken'.repeat(200)}ENDTOKEN`,
  hidden: '',
} as const
type TextSample = keyof typeof TEXT_SAMPLES
const TEXT_SAMPLE_NAMES = Object.keys(TEXT_SAMPLES) as TextSample[]
const READING_SAMPLES = {
  a: 'long',
  b: 'short',
  c: 'empty',
  d: 'newlines',
  e: 'token',
  g: 'short',
} as const satisfies Partial<Record<(typeof KEYS)[number], TextSample>>
const ARCHIVE_COLUMNS = [
  { key: 'environment', label: 'Environment', value: 'Production' },
  { key: 'scope', label: 'Scope', value: 'Full' },
  { key: 'size', label: 'Size', value: '25.6 MB' },
  { key: 'checksum', label: 'Checksum', value: 'Verified' },
  { key: 'copy', label: 'Copy', value: 'Copied' },
  { key: 'duration', label: 'Duration', value: '2.7 s' },
  { key: 'restore', label: 'Restore', value: 'No restore' },
] as const

function entityRef(key: string): EntityRef {
  return { type: SOURCE, id: key }
}

function raw(key: string): TableRow {
  return { rowKey: key, slots: { [SOURCE]: entityRef(key) } }
}

/** Declared SDK consumer with synthetic ingress scoped to this browser mount. */
@Component({
  selector: 'table-geometry-fixture',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosViewportTable,
    HilosTableCell,
    HilosTableDetail,
    HilosTableText,
    HilosHiddenMark,
    HilosModal,
  ],
  template: `
    <main
      class="container-fluid py-3"
      data-id="geometry-fixture"
      (keydown)="onKeydown($event)"
    >
      <h1 class="h4">Angular table geometry fixture</h1>
      <p id="geometry-reading-note" class="small text-body-secondary">
        Synthetic controller ingress; no server delivery is claimed. F8 delivers
        an own removal and F9 publishes the selected text sample without moving
        focus. Both SDK projections stay mounted; Bootstrap chooses which one is
        visible.
      </p>
      <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
        <label>
          Target
          <select
            #targetInput
            class="form-select"
            data-id="geometry-target"
            (change)="target = targetInput.value"
          >
            @for (key of targets; track key) {
              <option [value]="key" [selected]="key === target">
                {{ key }}
              </option>
            }
          </select>
        </label>
        <label>
          Reason
          <select
            #reasonInput
            class="form-select"
            data-id="geometry-reason"
            (change)="reason = reasonInput.value"
          >
            @for (value of reasons; track value) {
              <option [value]="value" [selected]="value === reason">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <label>
          Pending
          <select
            #pendingInput
            class="form-select"
            data-id="geometry-pending"
            (change)="pending = choice(pendingInput.value, pendingOptions)"
          >
            @for (value of pendingOptions; track value) {
              <option [value]="value" [selected]="value === pending">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <label>
          Detail
          <select
            #detailInput
            class="form-select"
            data-id="geometry-detail"
            (change)="detail = choice(detailInput.value, detailOptions)"
          >
            @for (value of detailOptions; track value) {
              <option [value]="value" [selected]="value === detail">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <label>
          Window request
          <select
            #navigationInput
            class="form-select"
            data-id="geometry-navigation"
            (change)="navigation = choice(navigationInput.value, navigations)"
          >
            @for (value of navigations; track value) {
              <option [value]="value" [selected]="value === navigation">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <label>
          Snapshot
          <select
            #snapshotInput
            class="form-select"
            data-id="geometry-snapshot"
            (change)="snapshot = choice(snapshotInput.value, snapshots)"
          >
            @for (value of snapshots; track value) {
              <option [value]="value" [selected]="value === snapshot">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <label>
          Position
          <input
            #positionInput
            class="form-control"
            type="number"
            min="0"
            step="1"
            data-id="geometry-position"
            [value]="position"
            (input)="setPosition(positionInput.valueAsNumber)"
          />
        </label>
        <label class="form-check">
          <input
            #staleInput
            class="form-check-input"
            type="checkbox"
            data-id="geometry-stale"
            [checked]="stale"
            (change)="stale = staleInput.checked"
          />
          Stale
        </label>
        @if (!readingMode) {
          <label class="form-check">
            <input
              #textInput
              class="form-check-input"
              type="checkbox"
              data-id="geometry-text"
              [checked]="expandedText"
              (change)="expandedText = textInput.checked"
            />
            Expanded text
          </label>
        }
        <label>
          Row height
          <select
            #heightModeInput
            class="form-select"
            data-id="geometry-height-mode"
            (change)="setHeightMode(heightModeInput.value)"
          >
            @for (value of heightModes; track value) {
              <option [value]="value" [selected]="value === heightMode">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <label>
          Lines
          <select
            #lineCountInput
            class="form-select"
            data-id="geometry-lines"
            (change)="setLineCount(lineCountInput.value)"
          >
            @for (value of lineCounts; track value) {
              <option [value]="value" [selected]="value === lineCount">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <label>
          Rem
          <select
            #textRemInput
            class="form-select"
            data-id="geometry-rem"
            (change)="setTextRem(textRemInput.value)"
          >
            @for (value of textRems; track value) {
              <option [value]="value" [selected]="value === textRem">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <label>
          Height content
          <select
            #heightContentInput
            class="form-select"
            data-id="geometry-height-content"
            (change)="setHeightContent(heightContentInput.value)"
          >
            @for (value of heightContents; track value) {
              <option [value]="value" [selected]="value === heightContent">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <button
          type="button"
          class="btn btn-secondary"
          data-id="geometry-reading"
          (click)="startReading()"
        >
          Create reading controller
        </button>
        <label>
          Live sample for target
          <select
            #sampleInput
            class="form-select"
            data-id="geometry-payload"
            (change)="sample = choice(sampleInput.value, textSampleNames)"
          >
            @for (value of textSampleNames; track value) {
              <option [value]="value" [selected]="value === sample">
                {{ value }}
              </option>
            }
          </select>
        </label>
        <button
          type="button"
          class="btn btn-secondary"
          data-id="geometry-value"
          (click)="publishText()"
        >
          Publish text sample
        </button>
        <label class="form-check">
          <input
            #iconsInput
            class="form-check-input"
            type="checkbox"
            data-id="geometry-icon-actions"
            [checked]="iconActions"
            (change)="iconActions = iconsInput.checked"
          />
          Archive columns and icon actions on controller replacement
        </label>
      </div>
      <div class="d-flex flex-wrap gap-2 mb-3">
        @for (command of commands; track command.id) {
          <button
            type="button"
            class="btn btn-secondary"
            [attr.data-id]="'geometry-' + command.id"
            (click)="command.run()"
          >
            {{ command.label }}
          </button>
        }
      </div>
      <output
        class="d-block text-nowrap overflow-x-auto"
        data-id="geometry-actions"
        >{{ actionLog().join(',') || 'No actions' }}</output
      >
      <output
        class="d-block text-nowrap overflow-x-auto mb-2"
        data-id="geometry-state"
        [attr.data-sync]="syncSeen()"
        [attr.data-count]="footer().totalCount"
        [attr.data-range]="footer().firstRow + ':' + footer().lastRow"
        [attr.data-body]="body()"
        [attr.data-selected]="json(selected())"
        [attr.data-identities]="identities()"
        [attr.data-requests]="requests().length"
        [attr.data-descriptor]="
          json(requests().at(-1) ?? controller().descriptor())
        "
        [attr.data-set-work]="setWorkKeys()"
      >
        {{ rowStates() || 'No rows' }}
      </output>
      <div
        data-id="geometry-body"
        [attr.data-controller]="controllerIdentity()"
      >
        @if (mounted()) {
          <hilos-viewport-table [controller]="controller()">
            <ng-template hilosTableCell="name" let-row let-rowKey="rowKey">
              <span [attr.data-id]="'geometry-name-' + rowKey">{{
                archive() ? '2026-10-21 11:13 ' + rowKey : row.name
              }}</span>
            </ng-template>
            <ng-template hilosTableCell="paragraph" let-row let-rowKey="rowKey">
              @if (readingMode && heightContent === 'parts') {
                <div
                  class="d-flex flex-wrap align-items-start gap-2"
                  [attr.data-id]="'geometry-text-' + rowKey"
                >
                  <hilos-table-text class="w-100" [value]="readingValue(row)" />
                  <span
                    class="badge text-bg-info"
                    [attr.data-id]="'geometry-text-badge-' + rowKey"
                    >Plain text metadata</span
                  >
                  <a
                    href="index.html#geometry-reading-note"
                    [attr.data-id]="'geometry-text-link-' + rowKey"
                    >Reading note</a
                  >
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    [attr.data-id]="'geometry-text-action-' + rowKey"
                    (click)="recordAction(rowKey, 'text')"
                  >
                    Inspect text
                  </button>
                </div>
              } @else if (readingMode && isHiddenValue(readingValue(row))) {
                <span [attr.data-id]="'geometry-text-' + rowKey">
                  <hilos-hidden-mark />
                </span>
              } @else if (readingMode) {
                <span [attr.data-id]="'geometry-text-' + rowKey">{{
                  row.paragraph
                }}</span>
              } @else {
                <p class="mb-0" [attr.data-id]="'geometry-text-' + rowKey">
                  {{ archive() ? 'Completed archive' : row.paragraph }}
                </p>
              }
            </ng-template>
            <ng-template hilosTableCell="badge" let-row let-rowKey="rowKey">
              <span
                class="badge text-bg-success"
                [attr.data-id]="'geometry-badge-' + rowKey"
                >{{ row.badge }}</span
              >
            </ng-template>
            @for (column of archiveColumns; track column.key) {
              <ng-template [hilosTableCell]="column.key">{{
                column.value
              }}</ng-template>
            }
            <ng-template hilosTableCell="actions" let-rowKey="rowKey">
              @if (archive()) {
                @for (action of iconButtons; track action.key) {
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    [attr.aria-label]="action.label"
                    [attr.data-id]="'geometry-' + action.key + '-' + rowKey"
                    (click)="recordAction(rowKey, action.key)"
                  >
                    <i [class]="'bi bi-' + action.icon" aria-hidden="true"></i>
                  </button>
                }
              } @else {
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary"
                  [attr.data-id]="'geometry-action-' + rowKey"
                  data-hilos-focus-return="inspect-geometry"
                  (click)="openModal(rowKey)"
                >
                  Inspect
                </button>
              }
            </ng-template>
            <ng-template hilosTableDetail="detail" let-row let-rowKey="rowKey">
              <p class="mb-0" [attr.data-id]="'geometry-detail-' + rowKey">
                {{ row.detail }}
              </p>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                [attr.data-id]="'geometry-detail-action-' + rowKey"
                (click)="recordAction(rowKey, 'detail')"
              >
                Detail action
              </button>
            </ng-template>
            <ng-template #rowProgress let-progress let-rowKey="rowKey">
              <p class="mb-0" [attr.data-id]="'geometry-work-' + rowKey">
                {{ progressLabel(progress) }}
              </p>
            </ng-template>
          </hilos-viewport-table>
        }
      </div>
      <hilos-modal
        [(open)]="modalOpen"
        title="Inspect geometry record"
        initialFocus="dialog"
      >
        <div class="d-flex flex-wrap gap-2">
          <button
            type="button"
            class="btn btn-secondary"
            data-id="geometry-modal-remove"
            (click)="depart(true)"
          >
            Deliver removal
          </button>
          <button
            type="button"
            class="btn btn-secondary"
            data-id="geometry-modal-snapshot"
            (click)="deliver()"
          >
            Deliver snapshot
          </button>
        </div>
      </hilos-modal>
    </main>
  `,
})
export class TableGeometryFixture {
  protected readonly targets = TARGETS
  protected readonly reasons = [
    'deleted',
    'left_set',
    'moved_out',
    'future_reason',
  ]
  protected readonly pendingOptions = ['none', 'move', 'remove'] as const
  protected readonly detailOptions = [
    'absent',
    'collapsed',
    'expanded',
  ] as const
  protected readonly navigations = [
    'search',
    'filter',
    'order',
    'page',
    'show',
    'refresh',
    'backward',
    'reset',
  ] as const
  protected readonly snapshots = [
    'short',
    'initial',
    'empty',
    'capacity',
  ] as const
  protected readonly heightModes = HEIGHT_MODES
  protected readonly lineCounts = LINE_COUNTS
  protected readonly textRems = TEXT_REMS
  protected readonly heightContents = HEIGHT_CONTENTS
  protected readonly textSampleNames = TEXT_SAMPLE_NAMES
  protected readonly archiveColumns = ARCHIVE_COLUMNS
  protected readonly iconButtons = [
    { key: 'details', label: 'Details', icon: 'exclamation-circle' },
    { key: 'restore', label: 'Restore', icon: 'arrow-counterclockwise' },
    { key: 'delete', label: 'Delete', icon: 'trash' },
  ] as const
  protected target = 'a'
  protected reason = 'deleted'
  protected pending: (typeof this.pendingOptions)[number] = 'none'
  protected detail: (typeof this.detailOptions)[number] = 'collapsed'
  protected navigation: (typeof this.navigations)[number] = 'search'
  protected snapshot: (typeof this.snapshots)[number] = 'short'
  protected position = 0
  protected stale = false
  protected expandedText = false
  protected heightMode: HeightMode = 'default'
  protected lineCount: HilosTableLineCount = 3
  protected textRem: HilosTableTextRem = 4.5
  protected heightContent: HeightContent = 'text'
  protected readingMode = false
  protected sample: TextSample = 'short'
  protected iconActions = false
  protected readonly archive = signal(false)
  protected readonly mounted = signal(true)
  protected readonly modalOpen = signal(false)
  protected readonly syncSeen = signal(false)
  protected readonly actionLog = signal<readonly string[]>([])
  protected readonly requests = signal<readonly TableViewportDescriptor[]>([])

  // Browser-local fixture input and handles have no backend RT readers. Numeric
  // retention remains solely in the shipped controller.geometry owner.
  private readonly scope = new Scope('page', 'angular-table-geometry')
  private searchEnabled = true
  private previous: TableViewportController<RecordValue> | null = null
  protected readonly controller = signal(this.makeController())
  // Test-only identity derived from the actual object, never row contents.
  // Optimized Angular has no public component-inspection API in the browser.
  private readonly controllerIds = new WeakMap<object, number>()
  private controllerSequence = 0
  protected readonly controllerIdentity = computed(() => {
    const owner = this.controller()
    if (!this.controllerIds.has(owner))
      this.controllerIds.set(owner, ++this.controllerSequence)
    return this.controllerIds.get(owner)!
  })
  protected readonly rows = signal(this.controller().rows.get())
  protected readonly footer = signal(this.controller().frame.footer.get())
  protected readonly body = signal(this.controller().frame.body.get())
  protected readonly selected = signal(this.controller().selection.target.get())
  private readonly tableWork = signal(this.controller().progress.table.get())
  private readonly bulkWork = signal(this.controller().progress.bulk.get())
  private readonly report = signal(this.controller().bulk.report.get())
  protected readonly identities = computed(() =>
    this.rows()
      .map((row) => `${row.rowKey}:${row.occurrence}`)
      .join(','),
  )
  protected readonly rowStates = computed(() =>
    this.rows()
      .map(
        (row) =>
          `${row.rowKey}:${row.placeholder ? 'departed' : (row.pending ?? 'live')}`,
      )
      .join(','),
  )
  protected readonly setWorkKeys = computed(
    () =>
      `${this.tableWork()?.progressKey}:${this.bulkWork()?.progressKey}:${this.report()?.progressKey}`,
  )
  protected readonly json = JSON.stringify
  protected readonly commands: readonly {
    id: string
    label: string
    run: () => void
  }[] = [
    { id: 'reset', label: 'Reset fixture', run: () => this.reset() },
    { id: 'configure', label: 'Configure', run: () => this.configure() },
    { id: 'work', label: 'Start work', run: () => this.work() },
    { id: 'complete', label: 'Complete work', run: () => this.work(true) },
    { id: 'remove', label: 'Foreign removal', run: () => this.depart(false) },
    { id: 'own', label: 'Own removal', run: () => this.depart(true) },
    { id: 'batch', label: 'Batch and Apply', run: () => this.batch() },
    { id: 'apply', label: 'Apply', run: () => this.controller().apply() },
    { id: 'reuse', label: 'Own key reuse', run: () => this.reuse() },
    {
      id: 'normalize',
      label: 'Normalize and remove',
      run: () => this.normalizedDeparture(),
    },
    {
      id: 'badge',
      label: 'Change badge and highlight',
      run: () => this.badge(),
    },
    {
      id: 'unmount',
      label: 'Unmount table',
      run: () => this.mounted.set(false),
    },
    { id: 'mount', label: 'Mount table', run: () => this.mounted.set(true) },
    {
      id: 'count-zero',
      label: 'Count zero',
      run: () => this.controller().ingestCount(0, true, false),
    },
    {
      id: 'count-positive',
      label: 'Count positive',
      run: () => this.controller().ingestCount(30, true, true),
    },
    { id: 'append', label: 'Append', run: () => this.append() },
    { id: 'create', label: 'Own create', run: () => this.ownCreate() },
    { id: 'move', label: 'Move', run: () => this.move(false) },
    { id: 'own-move', label: 'Own move', run: () => this.move(true) },
    { id: 'window', label: 'Request window', run: () => this.changeWindow() },
    { id: 'deliver', label: 'Deliver snapshot', run: () => this.deliver() },
    {
      id: 'reconnect',
      label: 'Reconnect snapshot',
      run: () => this.deliver(true),
    },
    { id: 'refuse', label: 'Refuse', run: () => this.refuse() },
    { id: 'replace', label: 'Replace controller', run: () => this.replace() },
    {
      id: 'no-search',
      label: 'Controller without search',
      run: () => this.replace(false),
    },
    {
      id: 'old',
      label: 'Old controller reply',
      run: () => this.previous?.ingestRefusal('old'),
    },
    {
      id: 'live',
      label: 'Live value and detail',
      run: () => this.updateLive(),
    },
    { id: 'set-work', label: 'Set work and report', run: () => this.setWork() },
    {
      id: 'announce',
      label: 'Announce',
      run: () =>
        this.controller().ingestAnnounce(
          'j',
          'inside',
          this.footer().totalCount + 1,
          true,
        ),
    },
  ]

  constructor() {
    this.seed(this.controller())
    effect((onCleanup) => {
      const owner = this.controller()
      const stops = [
        mirrorHilosSignal(owner.rows, this.rows),
        mirrorHilosSignal(owner.frame.footer, this.footer),
        mirrorHilosSignal(owner.frame.body, this.body),
        mirrorHilosSignal(owner.selection.target, this.selected),
        mirrorHilosSignal(owner.progress.table, this.tableWork),
        mirrorHilosSignal(owner.progress.bulk, this.bulkWork),
        mirrorHilosSignal(owner.bulk.report, this.report),
      ]
      onCleanup(() => stops.forEach((stop) => stop()))
    })
    inject(DestroyRef).onDestroy(() => {
      this.controller().dispose()
      this.previous?.dispose()
    })
  }

  protected choice<T extends string | number>(
    value: string,
    options: readonly T[],
  ): T {
    const chosen = options.find((option) => String(option) === value)
    if (chosen === undefined) throw new Error('Unknown fixture option')
    return chosen
  }

  protected setHeightMode(value: string): void {
    this.heightMode = this.choice(value, this.heightModes)
    this.replaceReading()
  }

  protected setLineCount(value: string): void {
    this.lineCount = this.choice(value, this.lineCounts)
    if (this.readingMode && this.heightMode === 'lines')
      this.replace(this.searchEnabled)
  }

  protected setTextRem(value: string): void {
    this.textRem = this.choice(value, this.textRems)
    if (this.readingMode && this.heightMode === 'height')
      this.replace(this.searchEnabled)
  }

  protected setHeightContent(value: string): void {
    this.heightContent = this.choice(value, this.heightContents)
    this.replaceReading()
  }

  protected startReading(): void {
    this.readingMode = true
    this.replace()
  }

  private replaceReading(): void {
    if (this.readingMode) this.replace(this.searchEnabled)
  }

  protected readingValue(row: RecordValue): Hideable<string> {
    return row.hiddenParagraph ? HIDDEN_VALUE : row.paragraph
  }

  protected readonly isHiddenValue = isHiddenValue

  protected publishText(): void {
    const owner = this.controller()
    owner.withPresentationUpdate(
      { kind: 'rows', rowKeys: [this.target] },
      () => {
        this.write(this.target, {
          paragraph: TEXT_SAMPLES[this.sample],
          hiddenParagraph: this.sample === 'hidden',
        })
        owner.ingestDelta({
          kind: 'row_updated',
          rowKey: this.target,
          row: raw(this.target),
        })
      },
    )
  }

  protected setPosition(value: number): void {
    this.position = Number.isFinite(value) ? Math.max(0, Math.trunc(value)) : 0
  }

  protected progressLabel(progress: HilosTableProgress): string {
    return String(progress.detail['label'] ?? '')
  }

  protected recordAction(key: string, action: string): void {
    this.actionLog.update((log) => [...log, `${key}:${action}`])
  }

  protected openModal(key: string): void {
    this.target = key
    this.modalOpen.set(true)
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'F8') {
      event.preventDefault()
      this.depart(true)
    } else if (event.key === 'F9') {
      event.preventDefault()
      this.publishText()
    }
  }

  private write(key: string, fields: Partial<RecordValue>): void {
    ingest(this.scope, { entities: { [SOURCE]: { id: key, ...fields } } })
  }

  private makeController(
    search = true,
    archive = false,
    reading = this.readingMode,
  ): TableViewportController<RecordValue> {
    const rowHeight: HilosTableRowHeight | undefined =
      !reading || this.heightMode === 'default'
        ? undefined
        : this.heightMode === 'content'
          ? { mode: 'content' }
          : this.heightMode === 'lines'
            ? { mode: 'lines', count: this.lineCount }
            : { mode: 'height', rem: this.textRem }
    const frame: HilosTableFrame = {
      title: 'Geometry records',
      subtitle: 'Synthetic controller ingress; no server delivery is claimed.',
      search: search ? { placeholder: 'Find geometry records' } : undefined,
      filters: [
        { kind: 'toggle', key: 'status', label: 'Paused', on: 'paused' },
      ],
      columns: [
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
          source: SOURCE,
          ...(reading ? { heightContent: this.heightContent } : {}),
          progress: true,
        },
        { key: 'badge', label: 'Status', source: SOURCE, card: 'badge' },
        ...(archive
          ? ARCHIVE_COLUMNS.map(({ key, label }) => ({
              key,
              label,
              cellClass: 'text-nowrap',
            }))
          : []),
        {
          key: 'actions',
          label: archive ? '' : 'Actions',
          reads: [],
          cellClass: archive ? 'text-end' : undefined,
        },
        {
          key: 'detail',
          label: 'Expanded detail',
          detail: true,
          detailWide: true,
        },
      ],
      ...(rowHeight ? { rowHeight } : {}),
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
    return new TableViewportController<RecordValue>({
      frame,
      resolve: (row) => {
        const fields = this.scope.entities
          .signal(entityRef(row.rowKey))
          .get()?.fields
        return {
          name: String(fields?.['name'] ?? ''),
          paragraph: String(fields?.['paragraph'] ?? ''),
          hiddenParagraph: fields?.['hiddenParagraph'] === true,
          badge: String(fields?.['badge'] ?? ''),
          detail: String(fields?.['detail'] ?? ''),
          expandable: fields?.['expandable'] !== false,
        }
      },
      expandable: (row) => row.expandable,
      sendViewport: (descriptor) =>
        this.requests.update((old) => [...old, descriptor]),
    })
  }

  private seed(
    owner: TableViewportController<RecordValue>,
    reading = this.readingMode,
  ): void {
    owner.withPresentationUpdate({ kind: 'window' }, () => {
      for (const key of TARGETS) {
        const sample: TextSample | undefined = reading
          ? key === 'f' && this.heightContent === 'parts'
            ? 'hidden'
            : (READING_SAMPLES[key as keyof typeof READING_SAMPLES] ?? 'short')
          : undefined
        this.write(key, {
          name:
            reading && sample !== undefined
              ? `Record ${key}`
              : key === 'b'
                ? 'The widest unbroken name WWWWWWWWWWWWWWWW'
                : `Record ${key}`,
          paragraph:
            sample !== undefined
              ? TEXT_SAMPLES[sample]
              : key === 'c'
                ? EXPANDED
                : PARAGRAPH,
          hiddenParagraph: sample === 'hidden',
          badge: 'Available',
          detail: `Private detail for ${key}. ${EXPANDED}`,
          expandable: true,
        })
      }
      owner.ingestSubscriptionWindow(
        KEYS.map(raw),
        KEYS.length,
        true,
        { name: 'a' },
        { name: 'g' },
        10,
        [{ field: 'name', direction: 'asc' }],
        [],
        0,
      )
    })
  }

  private reset(): void {
    this.controller().resetFilters()
    this.seed(this.controller())
    this.stale = false
    this.pending = 'none'
    this.detail = 'collapsed'
    this.expandedText = false
    this.syncSeen.set(false)
  }

  protected depart(own: boolean): void {
    this.controller().ingestDelta({
      kind: 'row_removed',
      rowKey: this.target,
      reason: hilosTableRemovalReason(this.reason),
      own,
    })
  }

  private configure(): void {
    const owner = this.controller()
    owner.withPresentationUpdate(
      { kind: 'rows', rowKeys: [this.target] },
      () => {
        this.write(this.target, {
          expandable: this.detail !== 'absent',
          ...(!this.readingMode
            ? {
                paragraph: this.expandedText ? EXPANDED : PARAGRAPH,
                hiddenParagraph: false,
              }
            : {}),
        })
        owner.ingestDelta({
          kind: 'row_stale',
          rowKey: this.target,
          staleSources: this.stale ? [SOURCE] : [],
        })
        owner.expandRow(this.target, this.detail === 'expanded')
        if (this.pending === 'move') this.move(false)
        else if (this.pending === 'remove') this.depart(false)
      },
    )
  }

  private work(ended = false): void {
    this.controller().ingestProgress({
      scope: 'row',
      rowKey: this.target,
      progressKey: `work-${this.target}`,
      current: 2,
      total: 7,
      detail: { label: `Private progress for ${this.target}. ${PARAGRAPH}` },
      ended,
    })
  }

  private batch(): void {
    const owner = this.controller()
    owner.withPresentationUpdate({ kind: 'window' }, () => {
      for (const rowKey of ['a', 'b', 'c', 'g'])
        owner.ingestDelta({
          kind: 'row_removed',
          rowKey,
          reason: hilosTableRemovalReason(this.reason),
        })
      owner.apply()
    })
  }

  private badge(): void {
    const owner = this.controller()
    owner.withPresentationUpdate(
      { kind: 'rows', rowKeys: [this.target] },
      () => {
        this.write(this.target, { badge: 'Paused' })
        owner.ingestDelta({
          kind: 'row_updated',
          rowKey: this.target,
          row: {
            ...raw(this.target),
            staleSources: this.stale ? [SOURCE] : [],
          },
          own: true,
        })
      },
    )
  }

  private reuse(): void {
    const owner = this.controller()
    owner.withPresentationUpdate({ kind: 'window' }, () => {
      this.depart(true)
      this.write(this.target, {
        name: `Replacement ${this.target}`,
        paragraph: 'New short value',
        hiddenParagraph: false,
        detail: 'New detail',
        expandable: true,
      })
      owner.ingestOwnCreate(raw(this.target), 0, KEYS.length, true, null, null)
    })
  }

  private normalizedDeparture(): void {
    const owner = this.controller()
    const stop = subscribeSignal(
      this.scope.entities.signal(entityRef(this.target)),
      () => {
        this.syncSeen.set(true)
        owner.expandRow(this.target, false)
        this.depart(true)
      },
    )
    try {
      owner.withPresentationUpdate(
        { kind: 'rows', rowKeys: [this.target] },
        () =>
          this.write(this.target, {
            name: 'Normalized replacement',
            paragraph: 'short',
            hiddenParagraph: false,
            detail: '',
          }),
      )
    } finally {
      stop()
    }
  }

  private append(): void {
    this.controller().ingestAppend(
      raw(this.target),
      this.footer().totalCount + 1,
      true,
      { name: 'a' },
      { name: this.target },
    )
  }

  private ownCreate(): void {
    this.controller().ingestOwnCreate(
      raw(this.target),
      this.position,
      this.footer().totalCount + 1,
      true,
      { name: 'a' },
      { name: this.target },
    )
  }

  private move(own: boolean): void {
    this.controller().ingestDelta({
      kind: 'row_moved',
      rowKey: this.target,
      row: raw(this.target),
      position: this.position,
      own,
    })
  }

  private changeWindow(): void {
    const owner = this.controller()
    switch (this.navigation) {
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
      case 'reset':
        owner.resetFilters()
        break
    }
  }

  protected deliver(reconnect = false): void {
    const owner = this.controller()
    const keys =
      this.snapshot === 'empty'
        ? []
        : this.snapshot === 'capacity'
          ? ['a', 'b', 'c']
          : KEYS
    owner.withPresentationUpdate({ kind: 'window' }, () => {
      if (this.snapshot === 'short')
        for (const key of keys)
          this.write(key, {
            name: `Fresh ${key}`,
            paragraph: 'Fresh short value',
            hiddenParagraph: false,
            detail: 'Fresh detail',
            expandable: true,
          })
      const limit = this.snapshot === 'capacity' ? 3 : 10
      const total = this.snapshot === 'capacity' ? 30 : keys.length
      const first = keys.length ? { name: keys[0] } : null
      const last = keys.length ? { name: keys.at(-1)! } : null
      if (reconnect)
        owner.ingestSubscriptionWindow(
          keys.map(raw),
          total,
          true,
          first,
          last,
          limit,
          [{ field: 'name', direction: 'asc' }],
          [],
          0,
        )
      else {
        const requestId = this.requests().at(-1)?.requestId
        owner.ingestWindow(
          keys.map(raw),
          total,
          true,
          first,
          last,
          limit,
          this.snapshot === 'capacity' ? 6 : 0,
          owner.acceptsWindowResponse(requestId) ? requestId : undefined,
        )
      }
    })
  }

  private refuse(): void {
    const owner = this.controller()
    const requestId = this.requests().at(-1)?.requestId
    owner.ingestRefusal(
      'forbidden',
      owner.acceptsWindowResponse(requestId) ? requestId : undefined,
    )
  }

  private replace(search = true): void {
    this.previous?.dispose()
    this.previous = this.controller()
    this.searchEnabled = search
    this.archive.set(this.iconActions)
    const owner = this.makeController(
      search,
      this.iconActions,
      this.readingMode,
    )
    this.seed(owner, this.readingMode)
    this.controller.set(owner)
    this.previous.dispose()
  }

  private updateLive(): void {
    const owner = this.controller()
    owner.withPresentationUpdate(
      { kind: 'rows', rowKeys: [this.target] },
      () => {
        this.write(this.target, {
          name: `Updated ${this.target}`,
          paragraph: this.readingMode ? TEXT_SAMPLES[this.sample] : EXPANDED,
          hiddenParagraph: this.readingMode && this.sample === 'hidden',
          detail: `Updated detail. ${EXPANDED} ${EXPANDED}`,
        })
        owner.ingestDelta({
          kind: 'row_updated',
          rowKey: this.target,
          row: raw(this.target),
        })
      },
    )
  }

  private setWork(): void {
    const owner = this.controller()
    for (const scope of ['table', 'bulk'] as const)
      owner.ingestProgress({
        scope,
        progressKey: `set-${scope}`,
        current: 1,
        total: 3,
      })
    owner.ingestBulkReport({
      progressKey: 'finished',
      touched: 2,
      untouched: [],
      untouchedOmitted: 0,
    })
  }
}
