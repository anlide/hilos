// HilosStatusHistoryPage — the History tab of the framework Status section
// inside the admin shell. React counterpart of the Vue page: the shared section
// head with the Status tabs over the standard read-only server viewport of the
// completed incidents and maintenance, with its search, component and outcome
// filters, period and live/Apply strip. The core keeps everything: the state
// reads the component catalog of the complete page answer, and the table owns
// the frame, the window and the row projection. A maintenance whose exact end
// was lost reads as an Unknown duration beside the moment its completion was
// observed — never as a zero or a guessed end. Both are created and started in
// the effect and disposed by the cleanup of that same run, so a StrictMode
// replay or a new context gets a live generation of its own and a discarded
// render leaves no listener behind. An unavailable catalog is said, never drawn
// as an empty list. Bootstrap classes only.
import { useEffect, useState } from 'react'
import { formatMoment, hilosTimeZone, HilosPages } from '@hilos/core/base'
import {
  createHilosStatusHistoryState,
  createHilosStatusHistoryTable,
  hilosStatusHistoryDuration,
  HILOS_STATUS_COPY,
  HILOS_STATUS_HISTORY_COPY,
  STATUS_HISTORY_ENDED_AT_FIELD,
  STATUS_HISTORY_OUTCOME_FIELD,
  STATUS_HISTORY_STARTED_AT_FIELD,
  STATUS_HISTORY_TITLE_FIELD,
  type HilosStatusContext,
  type HilosStatusHistoryRow,
  type HilosStatusHistoryState,
  type HilosStatusHistoryTable,
} from '@hilos/core/admin/status'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosStatusHistoryPage}. */
export interface HilosStatusHistoryPageProps {
  /** The project's existing scopes, connection and tracked actions. */
  context: HilosStatusContext
}

/** The catalog and the table one effect run created and started. */
interface HistoryGeneration {
  readonly state: HilosStatusHistoryState
  readonly table: HilosStatusHistoryTable
}

/**
 * A moment of a completed fact, to the second, in the reader zone.
 *
 * @param at The moment in epoch milliseconds.
 */
function moment(at: number): string {
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
 * What a completed fact was: the incident's title, or the maintenance as far as
 * its announcement is known.
 *
 * @param row The completed fact.
 */
function subject(row: HilosStatusHistoryRow): string {
  if (row.kind === 'incident') return row.title
  if (!row.announcementKnown) return 'Maintenance: announcement unknown'
  if (row.workPublicId === null) return 'Unannounced maintenance'
  return `Maintenance «${row.title}»`
}

/**
 * How a completed fact ended, said by what was retained and never derived.
 *
 * @param row The completed fact.
 */
function outcome(row: HilosStatusHistoryRow) {
  if (row.outcome === 'resolved') {
    return row.publishedAt !== null
      ? `Published at ${moment(row.publishedAt)}; resolved automatically`
      : 'Resolved automatically'
  }
  if (row.outcome === 'recovered_before_publication') {
    return 'Not published: recovered before publication'
  }
  if (row.outcome === 'canceled_publication') {
    return 'Not published: publication canceled manually'
  }
  if (row.endedAt !== null) return 'Completed'
  return (
    <>
      Completion observed at {moment(row.completionObservedAt!)}
      <p className="small text-body-secondary mb-0">
        {HILOS_STATUS_HISTORY_COPY.unknownEnd}
      </p>
    </>
  )
}

/**
 * The completed incidents and maintenance, read only.
 *
 * @param props The project's scopes, connection and tracked actions.
 */
export function HilosStatusHistoryPage({
  context,
}: HilosStatusHistoryPageProps) {
  const [generation, setGeneration] = useState<HistoryGeneration | null>(null)

  useEffect(() => {
    const state = createHilosStatusHistoryState(context)
    const table = createHilosStatusHistoryTable(context)
    state.start()
    table.start()
    setGeneration({ state, table })

    return () => {
      table.dispose()
      state.dispose()
      setGeneration((current) => (current?.table === table ? null : current))
    }
  }, [context])

  return (
    <HilosAdminPage
      page={HilosPages.STATUS_HISTORY}
      headKind="section"
      tabsLabel="Status pages"
    >
      {generation && <StatusHistory generation={generation} />}
    </HilosAdminPage>
  )
}

/**
 * The unavailable word and the table of one live generation.
 *
 * @param props The started catalog and table of this generation.
 * @param props.generation The live generation the page's effect created.
 */
function StatusHistory({ generation }: { generation: HistoryGeneration }) {
  const { state, table } = generation
  const data = useSignal(state.data)
  useSignal(hilosTimeZone)

  return (
    <>
      <div className="hilos-stack mb-3">
        <p className="invisible mb-0" aria-hidden="true" inert>
          {HILOS_STATUS_HISTORY_COPY.unavailable}
        </p>
        {data === null && (
          <p
            className="text-body-secondary mb-0"
            data-id="hilos-status-history-unavailable"
          >
            {HILOS_STATUS_HISTORY_COPY.unavailable}
          </p>
        )}
      </div>
      {data !== null && (
        <HilosViewportTable
          controller={table.controller}
          dataId="hilos-status-history-table"
          cells={{
            [STATUS_HISTORY_STARTED_AT_FIELD]: (row) => moment(row.startedAt),
            [STATUS_HISTORY_ENDED_AT_FIELD]: (row) =>
              hilosStatusHistoryDuration(row),
            [STATUS_HISTORY_TITLE_FIELD]: (row) => (
              <>
                <span
                  className="text-break"
                  data-id={`hilos-status-history-title-${row.id}`}
                >
                  {subject(row)}
                </span>
                {row.kind === 'incident' && (
                  <p className="small text-body-secondary mb-0">
                    {HILOS_STATUS_COPY[row.severity]}
                  </p>
                )}
              </>
            ),
            [STATUS_HISTORY_OUTCOME_FIELD]: (row) => outcome(row),
          }}
        />
      )}
    </>
  )
}
