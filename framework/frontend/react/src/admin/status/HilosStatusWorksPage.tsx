// HilosStatusWorksPage — the Scheduled maintenance tab of the framework Status
// section inside the admin shell. React counterpart of the Vue page: the shared
// section head with the Status tabs over the standard server viewport of the
// upcoming announcements, with its search, component filter, orders and
// live/Apply strip; Schedule maintenance on the bar and in the empty state, and
// a change and a cancel per row. The core keeps everything: the state reads the
// component catalog of the complete page answer, the table owns the frame, the
// window, the row projection and the focus the windows share, and the three
// controllers own their windows and tracked actions. All five are created and
// started in the effect and disposed by the cleanup of that same run, so a
// StrictMode replay or a new context gets a live generation of its own, a
// discarded render leaves no listener behind, and the windows always get the
// live controllers. An unavailable catalog is said, never drawn as an empty
// list. Bootstrap classes only.
import { useEffect, useState } from 'react'
import {
  formatMoment,
  HILOS_TABLE_ACTIONS_KEY,
  hilosTimeZone,
  HilosPages,
} from '@hilos/core/base'
import {
  createHilosStatusWorkCancel,
  createHilosStatusWorkCreate,
  createHilosStatusWorkEdit,
  createHilosStatusWorksState,
  createHilosStatusWorksTable,
  HILOS_STATUS_WORK_ACTION_COPY,
  STATUS_WORK_COMPONENT_KEYS_FIELD,
  STATUS_WORK_ENDS_AT_FIELD,
  STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD,
  STATUS_WORK_STARTS_AT_FIELD,
  STATUS_WORK_TITLE_FIELD,
  type HilosStatusWorkCancel,
  type HilosStatusWorkCreate,
  type HilosStatusWorkEdit,
  type HilosStatusWorksContext,
  type HilosStatusWorksState,
  type HilosStatusWorksTable,
} from '@hilos/core/admin/status'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'
import { HilosStatusCancelWorkModal } from './HilosStatusCancelWorkModal.js'
import { HilosStatusWorkModal } from './HilosStatusWorkModal.js'

/** Props for {@link HilosStatusWorksPage}. */
export interface HilosStatusWorksPageProps {
  /** The project's existing scopes, connection and tracked actions. */
  context: HilosStatusWorksContext
}

/** The three windows of one generation, which share the table's one focus. */
interface WorksWindows {
  readonly create: HilosStatusWorkCreate
  readonly edit: HilosStatusWorkEdit
  readonly cancel: HilosStatusWorkCancel
}

/** The catalog, the table and the windows one effect run created and started. */
interface WorksGeneration extends WorksWindows {
  readonly state: HilosStatusWorksState
  readonly table: HilosStatusWorksTable
}

/**
 * One table focus belongs to one dialog; opening again never drops a draft.
 *
 * @param windows The windows of the live generation.
 */
function dialogOpen(windows: WorksWindows): boolean {
  return (
    windows.create.opened.get() ||
    windows.edit.opened.get() ||
    windows.cancel.opened.get()
  )
}

/**
 * A moment of an announcement, to the second, in the reader zone.
 *
 * @param at The moment in epoch milliseconds, or null when there is none.
 */
function moment(at: number | null): string {
  if (at === null) return '—'
  return (
    formatMoment(at, {
      format: {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZoneName: 'short',
      },
    }) ?? '—'
  )
}

/**
 * The upcoming maintenance announcements and the windows that schedule,
 * change and cancel them.
 *
 * @param props The project's scopes, connection and tracked actions.
 */
export function HilosStatusWorksPage({ context }: HilosStatusWorksPageProps) {
  const [generation, setGeneration] = useState<WorksGeneration | null>(null)

  useEffect(() => {
    const state = createHilosStatusWorksState(context)
    const table = createHilosStatusWorksTable(context, {
      openSchedule: () => {
        if (!dialogOpen({ create, edit, cancel })) create.open()
      },
    })
    const create = createHilosStatusWorkCreate(context, state)
    const edit = createHilosStatusWorkEdit(context, table, state)
    const cancel = createHilosStatusWorkCancel(context, table)
    state.start()
    table.start()
    create.start()
    edit.start()
    cancel.start()
    setGeneration({ state, table, create, edit, cancel })

    return () => {
      cancel.dispose()
      edit.dispose()
      create.dispose()
      table.dispose()
      state.dispose()
      setGeneration((current) => (current?.table === table ? null : current))
    }
  }, [context])

  return (
    <HilosAdminPage
      page={HilosPages.STATUS_WORKS}
      headKind="section"
      tabsLabel="Status pages"
    >
      {generation && <StatusWorks generation={generation} />}
    </HilosAdminPage>
  )
}

/**
 * The unavailable word, the table and the three windows of one live generation.
 *
 * @param props The started catalog, table and windows of this generation.
 * @param props.generation The live generation the page's effect created.
 */
function StatusWorks({ generation }: { generation: WorksGeneration }) {
  const { state, table, create, edit, cancel } = generation
  const data = useSignal(state.data)
  useSignal(hilosTimeZone)

  /**
   * The names of the catalog's components, in the order of the keys.
   *
   * @param keys The component keys to name.
   */
  function componentNames(keys: readonly string[]): string {
    return keys
      .map(
        (key) =>
          data?.components.find((component) => component.componentKey === key)
            ?.name ?? key,
      )
      .join(', ')
  }

  return (
    <>
      <div className="hilos-stack mb-3">
        <p className="invisible mb-0" aria-hidden="true" inert>
          {HILOS_STATUS_WORK_ACTION_COPY.unavailable}
        </p>
        {data === null && (
          <p
            className="text-body-secondary mb-0"
            data-id="hilos-status-works-unavailable"
          >
            {HILOS_STATUS_WORK_ACTION_COPY.unavailable}
          </p>
        )}
      </div>
      {data !== null && (
        <HilosViewportTable
          controller={table.controller}
          dataId="hilos-status-works-table"
          cells={{
            [STATUS_WORK_STARTS_AT_FIELD]: (row) => moment(row.startsAt),
            [STATUS_WORK_ENDS_AT_FIELD]: (row) => moment(row.endsAt),
            [STATUS_WORK_TITLE_FIELD]: (row) => (
              <>
                <span
                  className="text-break"
                  data-id={`hilos-status-work-title-${row.id}`}
                >
                  {row.title}
                </span>
                {row.description && (
                  <p className="small text-body-secondary mb-0 text-break">
                    {row.description}
                  </p>
                )}
              </>
            ),
            [STATUS_WORK_COMPONENT_KEYS_FIELD]: (row) =>
              componentNames(row.componentKeys),
            [STATUS_WORK_LAST_MAIL_QUEUED_AT_FIELD]: (row) => (
              <span data-id={`hilos-status-work-mail-${row.id}`}>
                {moment(row.lastMailQueuedAt)}
              </span>
            ),
            [HILOS_TABLE_ACTIONS_KEY]: (row, rowKey) => (
              <div className="d-flex gap-2">
                <button
                  type="button"
                  className="btn btn-sm btn-outline-secondary"
                  aria-label={`Change scheduled maintenance · ${row.title}`}
                  data-id={`hilos-status-work-edit-${row.id}`}
                  onClick={() => {
                    if (!dialogOpen(generation)) edit.open(rowKey)
                  }}
                >
                  <i className="bi bi-pencil" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  className="btn btn-sm btn-outline-danger"
                  aria-label={`Cancel scheduled maintenance · ${row.title}`}
                  data-id={`hilos-status-work-cancel-${row.id}`}
                  onClick={() => {
                    if (!dialogOpen(generation)) cancel.open(rowKey)
                  }}
                >
                  <i className="bi bi-x-lg" aria-hidden="true" />
                </button>
              </div>
            ),
          }}
        />
      )}
      <HilosStatusWorkModal controller={create} state={state} />
      <HilosStatusWorkModal controller={edit} state={state} />
      <HilosStatusCancelWorkModal controller={cancel} />
    </>
  )
}
