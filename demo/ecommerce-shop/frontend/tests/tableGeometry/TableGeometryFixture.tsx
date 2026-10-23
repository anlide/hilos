import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import {
  Scope,
  TableViewportController,
  hilosTableRemovalReason,
  ingest,
  subscribeSignal,
  type EntityRef,
  type HilosTableFrame,
  type TableRow,
  type TableViewportDescriptor,
} from '@hilos/core/base'
import {
  HilosModal,
  HilosViewportTable,
  HilosTableSelectionEdgeContext,
  useSignal,
} from '@hilos/react'

interface RecordValue {
  readonly name: string
  readonly paragraph: string
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
const ARCHIVE_COLUMNS = [
  ['environment', 'Environment', 'Production'],
  ['scope', 'Scope', 'Full'],
  ['size', 'Size', '25.6 MB'],
  ['checksum', 'Checksum', 'Verified'],
  ['copy', 'Copy', 'Copied'],
  ['duration', 'Duration', '2.7 s'],
  ['restore', 'Restore', 'No restore'],
] as const
const entityRef = (key: string): EntityRef => ({ type: SOURCE, id: key })
const raw = (key: string): TableRow => ({
  rowKey: key,
  slots: { [SOURCE]: entityRef(key) },
})

/** Synthetic inputs stay in this fixture mount; all geometry belongs to the SDK. */
export function TableGeometryFixture() {
  const [scope] = useState(() => new Scope('page', 'react-table-geometry'))
  const [target, setTarget] = useState('a')
  const [reason, setReason] = useState('deleted')
  const [stale, setStale] = useState(false)
  const [pending, setPending] = useState('none')
  const [detail, setDetail] = useState('collapsed')
  const [expandedText, setExpandedText] = useState(false)
  const [edge, setEdge] = useState<'start' | 'end'>('start')
  const [projection, setProjection] = useState('wide')
  const [mounted, setMounted] = useState(true)
  const [icons, setIcons] = useState(false)
  const [requests, setRequests] = useState<readonly TableViewportDescriptor[]>(
    [],
  )
  const [navigation, setNavigation] = useState('search')
  const [snapshot, setSnapshot] = useState('short')
  const [position, setPosition] = useState(0)
  const [syncSeen, setSyncSeen] = useState(false)
  const [modalOpen, setModalOpen] = useState(false)
  const [actionLog, setActionLog] = useState<string[]>([])
  const bodyRef = useRef<HTMLDivElement>(null)
  const previous = useRef<TableViewportController<RecordValue> | null>(null)

  function write(key: string, fields: Partial<RecordValue>): void {
    ingest(scope, { entities: { [SOURCE]: { id: key, ...fields } } })
  }
  function makeController(search = true, archive = false) {
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
          progress: true,
        },
        { key: 'badge', label: 'Status', source: SOURCE, card: 'badge' },
        ...(archive
          ? ARCHIVE_COLUMNS.map(([key, label]) => ({
              key,
              label,
              cellClass: 'text-nowrap',
            }))
          : []),
        {
          key: 'actions',
          label: archive ? '' : 'Actions',
          reads: [],
          card: 'field',
          cellClass: archive ? 'text-end' : undefined,
        },
        {
          key: 'detail',
          label: 'Expanded detail',
          detail: true,
          detailWide: true,
        },
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
    return new TableViewportController<RecordValue>({
      frame,
      resolve: (row) => {
        const fields = scope.entities
          .signal(row.slots[SOURCE] as EntityRef)
          .get()?.fields
        return {
          name: String(fields?.name ?? ''),
          paragraph: String(fields?.paragraph ?? ''),
          badge: String(fields?.badge ?? ''),
          detail: String(fields?.detail ?? ''),
          expandable: fields?.expandable !== false,
        }
      },
      expandable: (row) => row.expandable,
      sendViewport: (descriptor) => setRequests((old) => [...old, descriptor]),
    })
  }
  function seed(owner: TableViewportController<RecordValue>): void {
    for (const key of TARGETS)
      write(key, {
        name:
          key === 'b'
            ? 'The widest unbroken name WWWWWWWWWWWWWWWW'
            : `Record ${key}`,
        paragraph: key === 'c' ? EXPANDED : PARAGRAPH,
        badge: 'Available',
        detail: `Private detail for ${key}. ${EXPANDED}`,
        expandable: true,
      })
    owner.ingestWindow(KEYS.map(raw), KEYS.length, true, null, null, 10)
  }
  const [controller, setController] = useState(() => {
    const owner = makeController()
    seed(owner)
    return owner
  })
  const rows = useSignal(controller.rows)
  const footer = useSignal(controller.frame.footer)
  const body = useSignal(controller.frame.body)
  const selected = useSignal(controller.selection.target)
  const tableWork = useSignal(controller.progress.table)
  const bulkWork = useSignal(controller.progress.bulk)
  const report = useSignal(controller.bulk.report)

  useEffect(() => () => controller.dispose(), [controller])
  // Expose an existing projection at the configured desktop viewport. No product
  // prop, stylesheet or viewport override is needed for this test-only control.
  useLayoutEffect(() => {
    const wide = bodyRef.current?.querySelector('.table-responsive')
    const cards = bodyRef.current?.querySelector(
      '[data-id="hilos-table-cards"]',
    )
    wide?.classList.remove('d-md-block')
    cards?.classList.remove('d-md-none')
    wide?.classList.toggle('d-none', projection !== 'wide')
    cards?.classList.toggle('d-none', projection !== 'card')
  }, [projection, mounted, controller, edge])

  function reset(): void {
    seed(controller)
    setStale(false)
    setPending('none')
    setDetail('collapsed')
    setExpandedText(false)
    setSyncSeen(false)
  }
  function depart(own: boolean): void {
    controller.ingestDelta({
      kind: 'row_removed',
      rowKey: target,
      reason: hilosTableRemovalReason(reason),
      own,
    })
  }
  function configure(): void {
    controller.withPresentationUpdate(
      { kind: 'rows', rowKeys: [target] },
      () => {
        write(target, {
          expandable: detail !== 'absent',
          paragraph: expandedText ? EXPANDED : PARAGRAPH,
        })
        controller.ingestDelta({
          kind: 'row_stale',
          rowKey: target,
          staleSources: stale ? [SOURCE] : [],
        })
        controller.expandRow(target, detail === 'expanded')
        if (pending === 'move') move(false, 1)
        else if (pending === 'remove') depart(false)
      },
    )
  }
  function work(ended = false): void {
    controller.ingestProgress({
      scope: 'row',
      rowKey: target,
      progressKey: `work-${target}`,
      current: 2,
      total: 7,
      detail: { label: `Private progress for ${target}. ${PARAGRAPH}` },
      ended,
    })
  }
  function batch(): void {
    controller.withPresentationUpdate({ kind: 'window' }, () => {
      for (const rowKey of ['a', 'b', 'c', 'g'])
        controller.ingestDelta({
          kind: 'row_removed',
          rowKey,
          reason: hilosTableRemovalReason(reason),
        })
      controller.apply()
    })
  }
  function badge(): void {
    controller.withPresentationUpdate(
      { kind: 'rows', rowKeys: [target] },
      () => {
        write(target, { badge: 'Paused' })
        controller.ingestDelta({
          kind: 'row_updated',
          rowKey: target,
          row: { ...raw(target), staleSources: stale ? [SOURCE] : [] },
          own: true,
        })
      },
    )
  }
  function reuse(): void {
    controller.withPresentationUpdate({ kind: 'window' }, () => {
      depart(true)
      write(target, {
        name: `Replacement ${target}`,
        paragraph: 'New short value',
        detail: 'New detail',
        expandable: true,
      })
      controller.ingestOwnCreate(raw(target), 0, KEYS.length, true, null, null)
    })
  }
  function normalizedDeparture(): void {
    const stop = subscribeSignal(
      scope.entities.signal(entityRef(target)),
      () => {
        setSyncSeen(true)
        controller.expandRow(target, false)
        depart(true)
      },
    )
    try {
      controller.withPresentationUpdate(
        { kind: 'rows', rowKeys: [target] },
        () =>
          write(target, {
            name: 'Normalized replacement',
            paragraph: 'short',
            detail: '',
          }),
      )
    } finally {
      stop()
    }
  }
  function append(): void {
    controller.ingestAppend(
      raw(target),
      footer.totalCount + 1,
      true,
      { name: 'a' },
      { name: target },
    )
  }
  function move(own: boolean, at = position): void {
    controller.ingestDelta({
      kind: 'row_moved',
      rowKey: target,
      row: raw(target),
      position: at,
      own,
    })
  }
  function changeWindow(): void {
    switch (navigation) {
      case 'search':
        controller.setSearch('needle')
        break
      case 'filter':
        controller.setFilter('status', 'paused')
        break
      case 'order':
        controller.setOrder([{ field: 'name', direction: 'desc' }])
        break
      case 'page':
        controller.setPage(1)
        break
      case 'show':
        controller.show()
        break
      case 'refresh':
        controller.refresh()
        break
      case 'backward':
        controller.prevPage()
        break
    }
  }
  function deliver(reconnect = false): void {
    const keys =
      snapshot === 'empty'
        ? []
        : snapshot === 'capacity'
          ? ['a', 'b', 'c']
          : KEYS
    if (snapshot === 'short')
      for (const key of keys)
        write(key, {
          name: `Fresh ${key}`,
          paragraph: 'Fresh short value',
          detail: 'Fresh detail',
          expandable: true,
        })
    const limit = snapshot === 'capacity' ? 3 : 10
    const total = snapshot === 'capacity' ? 30 : keys.length
    if (reconnect)
      controller.ingestSubscriptionWindow(
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
    else {
      const requestId = requests.at(-1)?.requestId
      controller.ingestWindow(
        keys.map(raw),
        total,
        true,
        { name: 'a' },
        { name: 'g' },
        limit,
        snapshot === 'capacity' ? 6 : 0,
        controller.acceptsWindowResponse(requestId) ? requestId : undefined,
      )
    }
  }
  function replace(search = true): void {
    previous.current = controller
    const owner = makeController(search, icons)
    seed(owner)
    setController(owner)
  }
  function updateLive(): void {
    controller.withPresentationUpdate(
      { kind: 'rows', rowKeys: [target] },
      () => {
        write(target, {
          name: `Updated ${target}`,
          paragraph: EXPANDED,
          detail: `Updated detail. ${EXPANDED} ${EXPANDED}`,
        })
        controller.ingestDelta({
          kind: 'row_updated',
          rowKey: target,
          row: raw(target),
        })
      },
    )
  }
  function setWork(): void {
    for (const scope of ['table', 'bulk'] as const)
      controller.ingestProgress({
        scope,
        progressKey: `set-${scope}`,
        current: 1,
        total: 3,
      })
    controller.ingestBulkReport({
      progressKey: 'finished',
      touched: 2,
      untouched: [],
      untouchedOmitted: 0,
    })
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
      flushSync(() =>
        controller.ingestAppend(
          raw(key),
          1,
          true,
          { name: key },
          { name: key },
        ),
      )
      await new Promise<void>((resolve) =>
        requestAnimationFrame(() => resolve()),
      )
      flushSync(() =>
        controller.ingestDelta({
          kind: 'row_removed',
          rowKey: key,
          reason: 'deleted',
          own: true,
        }),
      )
      await new Promise<void>((resolve) =>
        requestAnimationFrame(() => resolve()),
      )
    }
    controller.ingestCount(0, true)
  }
  const commands: readonly [string, string, () => void][] = [
    ['reset', 'Reset', reset],
    ['configure', 'Configure', configure],
    ['work', 'Start work', () => work()],
    ['complete', 'Complete work', () => work(true)],
    ['remove', 'Foreign removal', () => depart(false)],
    ['own', 'Own removal', () => depart(true)],
    ['batch', 'Batch and Apply', batch],
    ['apply', 'Apply', () => controller.apply()],
    ['reuse', 'Own key reuse', reuse],
    ['normalize', 'Normalize and remove', normalizedDeparture],
    ['badge', 'Change badge and highlight', badge],
    ['unmount', 'Unmount table', () => setMounted(false)],
    ['mount', 'Mount table', () => setMounted(true)],
    ['count-zero', 'Count zero', () => controller.ingestCount(0, true, false)],
    [
      'count-positive',
      'Count positive',
      () => controller.ingestCount(30, true, true),
    ],
    ['append', 'Append', append],
    [
      'create',
      'Own create',
      () =>
        controller.ingestOwnCreate(
          raw(target),
          position,
          footer.totalCount + 1,
          true,
          { name: 'a' },
          { name: target },
        ),
    ],
    ['move', 'Move', () => move(false)],
    ['own-move', 'Own move', () => move(true)],
    ['window', 'Request window', changeWindow],
    ['deliver', 'Deliver snapshot', () => deliver()],
    ['reconnect', 'Reconnect snapshot', () => deliver(true)],
    ['refuse', 'Refuse', () => controller.ingestRefusal('forbidden')],
    ['replace', 'Replace controller', () => replace()],
    ['no-search', 'Controller without search', () => replace(false)],
    [
      'old',
      'Old controller reply',
      () => previous.current?.ingestRefusal('old'),
    ],
    ['live', 'Live value and detail', updateLive],
    ['set-work', 'Set work and report', setWork],
    [
      'announce',
      'Announce',
      () =>
        controller.ingestAnnounce('j', 'inside', footer.totalCount + 1, true),
    ],
    ['churn', 'Append/delete churn', () => void churn()],
  ]
  function select(
    id: string,
    label: string,
    value: string,
    options: readonly string[],
    change: (value: string) => void,
  ) {
    return (
      <label>
        {label}
        <select
          className="form-select"
          data-id={`geometry-${id}`}
          value={value}
          onChange={(event) => change(event.target.value)}
        >
          {options.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </label>
    )
  }
  return (
    <main
      className="container-fluid py-3"
      data-id="geometry-fixture"
      onKeyDown={(event) => {
        // Fixture ingress keys preserve the user's current focus during delivery.
        if (event.key === 'F8') {
          event.preventDefault()
          depart(true)
        }
      }}
    >
      <h1 className="h4">React table geometry fixture</h1>
      <div className="d-flex flex-wrap gap-2 align-items-end mb-3">
        {select('target', 'Target', target, TARGETS, setTarget)}
        {select(
          'reason',
          'Reason',
          reason,
          ['deleted', 'left_set', 'moved_out', 'future_reason'],
          setReason,
        )}
        {select(
          'pending',
          'Pending',
          pending,
          ['none', 'move', 'remove'],
          setPending,
        )}
        {select(
          'detail',
          'Detail',
          detail,
          ['absent', 'collapsed', 'expanded'],
          setDetail,
        )}
        {select('edge', 'Selection edge', edge, ['start', 'end'], (value) =>
          setEdge(value as 'start' | 'end'),
        )}
        {select(
          'projection',
          'Projection',
          projection,
          ['wide', 'card'],
          setProjection,
        )}
        {select(
          'navigation',
          'Window request',
          navigation,
          ['search', 'filter', 'order', 'page', 'show', 'refresh', 'backward'],
          setNavigation,
        )}
        {select(
          'snapshot',
          'Snapshot',
          snapshot,
          ['short', 'initial', 'empty', 'capacity'],
          setSnapshot,
        )}
        <label>
          Position
          <input
            type="number"
            className="form-control"
            data-id="geometry-position"
            value={position}
            onChange={(event) => setPosition(Number(event.target.value))}
          />
        </label>
        {(
          [
            ['stale', 'Stale', stale, setStale],
            ['text', 'Expanded text', expandedText, setExpandedText],
            ['icon-actions', 'Icon action group', icons, setIcons],
          ] as const
        ).map(([id, label, checked, change]) => (
          <label key={id} className="form-check">
            <input
              className="form-check-input"
              type="checkbox"
              data-id={`geometry-${id}`}
              checked={checked}
              onChange={(event) => change(event.target.checked)}
            />
            {label}
          </label>
        ))}
      </div>
      <div className="d-flex flex-wrap gap-2 mb-3">
        {commands.map(([id, label, run]) => (
          <button
            key={id}
            type="button"
            className="btn btn-secondary"
            data-id={`geometry-${id}`}
            onClick={run}
          >
            {label}
          </button>
        ))}
      </div>
      <output data-id="geometry-actions">{actionLog.join(',')}</output>
      <output
        className="d-block mb-2"
        data-id="geometry-state"
        data-sync={syncSeen}
        data-count={footer.totalCount}
        data-range={`${footer.firstRow}:${footer.lastRow}`}
        data-body={body}
        data-selected={JSON.stringify(selected)}
        data-identities={rows
          .map((row) => `${row.rowKey}:${row.occurrence}`)
          .join(',')}
        data-requests={requests.length}
        data-descriptor={JSON.stringify(
          requests.at(-1) ?? controller.descriptor(),
        )}
        data-set-work={`${tableWork?.progressKey}:${bulkWork?.progressKey}:${report?.progressKey}`}
      >
        {rows
          .map(
            (row) =>
              `${row.rowKey}:${row.placeholder ? 'departed' : (row.pending ?? 'live')}`,
          )
          .join(',')}
      </output>
      <div ref={bodyRef} data-id="geometry-body">
        {mounted && (
          <HilosTableSelectionEdgeContext.Provider key={edge} value={edge}>
            <HilosViewportTable
              controller={controller}
              cells={{
                name: (row, key) => (
                  <span data-id={`geometry-name-${key}`}>
                    {icons ? `2026-10-21 11:13 ${key}` : row.name}
                  </span>
                ),
                paragraph: (row, key) => (
                  <p className="mb-0" data-id={`geometry-text-${key}`}>
                    {icons ? 'Completed archive' : row.paragraph}
                  </p>
                ),
                badge: (row, key) => (
                  <span
                    className="badge text-bg-success"
                    data-id={`geometry-badge-${key}`}
                  >
                    {row.badge}
                  </span>
                ),
                ...Object.fromEntries(
                  ARCHIVE_COLUMNS.map(([key, , value]) => [key, () => value]),
                ),
                actions: (_, key) =>
                  icons ? (
                    <>
                      {[
                        ['details', 'exclamation-circle'],
                        ['restore', 'arrow-counterclockwise'],
                        ['delete', 'trash'],
                      ].map(([action, icon]) => (
                        <button
                          key={action}
                          type="button"
                          className="btn btn-sm btn-outline-secondary"
                          aria-label={action}
                          data-id={`geometry-${action}-${key}`}
                          onClick={() =>
                            setActionLog((old) => [...old, `${key}:${action}`])
                          }
                        >
                          <i className={`bi bi-${icon}`} aria-hidden="true" />
                        </button>
                      ))}
                    </>
                  ) : (
                    <button
                      type="button"
                      className="btn btn-sm btn-outline-secondary"
                      data-id={`geometry-action-${key}`}
                      data-hilos-focus-return="inspect-geometry"
                      onClick={() => setModalOpen(true)}
                    >
                      Inspect
                    </button>
                  ),
              }}
              details={{
                detail: (row, key) => (
                  <>
                    <p className="mb-0" data-id={`geometry-detail-${key}`}>
                      {row.detail}
                    </p>
                    <button
                      type="button"
                      className="btn btn-sm btn-outline-secondary"
                      data-id={`geometry-detail-action-${key}`}
                    >
                      Detail action
                    </button>
                  </>
                ),
              }}
              rowProgress={(progress, key) => (
                <p className="mb-0" data-id={`geometry-work-${key}`}>
                  {String(progress.detail.label)}
                </p>
              )}
            />
          </HilosTableSelectionEdgeContext.Provider>
        )}
      </div>
      <HilosModal
        open={modalOpen}
        title="Inspect geometry record"
        initialFocus="dialog"
        onClose={() => setModalOpen(false)}
      >
        <div className="d-flex gap-2">
          <button
            type="button"
            className="btn btn-secondary"
            data-id="geometry-modal-remove"
            onClick={() => depart(true)}
          >
            Deliver removal
          </button>
          <button
            type="button"
            className="btn btn-secondary"
            data-id="geometry-modal-snapshot"
            onClick={() => deliver()}
          >
            Deliver snapshot
          </button>
        </div>
      </HilosModal>
    </main>
  )
}
