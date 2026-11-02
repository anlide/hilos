// HilosStatusSubscribersPage — the Subscribers tab of the framework Status
// section inside the admin shell. React counterpart of the Vue page: the shared
// section head with the Status tabs, the confirmed and awaiting counts of the
// complete page answer, and the standard server viewport of the subscriptions
// with its search, confirmation filter, orders and live/Apply strip, each row
// with a trash that opens the removal window. The core keeps everything: the
// state reads the counts, the table owns the frame, the window and the row
// projection, and the controller owns the removal window and its tracked
// action. All three are created and started in the effect and disposed by the
// cleanup of that same run, so a StrictMode replay or a new context gets a live
// generation of its own and a discarded render leaves no listener behind. An
// unavailable count is a dash, never a zero. Bootstrap classes only.
import { useEffect, useState } from 'react'
import {
  formatMoment,
  HILOS_TABLE_ACTIONS_KEY,
  hilosTimeZone,
  HilosPages,
} from '@hilos/core/base'
import {
  createHilosStatusSubscriberController,
  createHilosStatusSubscribersState,
  createHilosStatusSubscribersTable,
  HILOS_STATUS_SUBSCRIBERS_COPY,
  STATUS_SUBSCRIBER_CONFIRMED_FIELD,
  STATUS_SUBSCRIBER_EMAIL_FIELD,
  STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD,
  type HilosStatusSubscriberController,
  type HilosStatusSubscribersContext,
  type HilosStatusSubscribersState,
  type HilosStatusSubscribersTable,
} from '@hilos/core/admin/status'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { HilosStatusRemoveSubscriberModal } from './HilosStatusRemoveSubscriberModal.js'

/** Props for {@link HilosStatusSubscribersPage}. */
export interface HilosStatusSubscribersPageProps {
  /** The project's existing scope, connection and action lifecycle. */
  context: HilosStatusSubscribersContext
}

/** The table, the counts and the removal window one effect run created and started. */
interface SubscribersGeneration {
  readonly table: HilosStatusSubscribersTable
  readonly state: HilosStatusSubscribersState
  readonly controller: HilosStatusSubscriberController
}

/**
 * The subscriber list and its aggregates, which share the complete page answer.
 *
 * @param props The project's scope, connection and action lifecycle.
 */
export function HilosStatusSubscribersPage({
  context,
}: HilosStatusSubscribersPageProps) {
  const [generation, setGeneration] = useState<SubscribersGeneration | null>(
    null,
  )

  useEffect(() => {
    const table = createHilosStatusSubscribersTable(context)
    const state = createHilosStatusSubscribersState(context)
    const controller = createHilosStatusSubscriberController(context, table)
    table.start()
    state.start()
    controller.start()
    setGeneration({ table, state, controller })

    return () => {
      controller.dispose()
      state.dispose()
      table.dispose()
      setGeneration((current) => (current?.table === table ? null : current))
    }
  }, [context])

  return (
    <HilosAdminPage
      page={HilosPages.STATUS_SUBSCRIBERS}
      headKind="section"
      tabsLabel="Status pages"
    >
      {generation && (
        <StatusSubscribers
          table={generation.table}
          state={generation.state}
          controller={generation.controller}
        />
      )}
    </HilosAdminPage>
  )
}

/**
 * The counts, the table and the removal window of one live generation.
 *
 * @param props The started table, counts and removal window of this generation.
 * @param props.table The standard viewport of the subscriptions.
 * @param props.state The confirmed and awaiting counts of the page answer.
 * @param props.controller The removal window the row trash opens.
 */
function StatusSubscribers({
  table,
  state,
  controller,
}: {
  table: HilosStatusSubscribersTable
  state: HilosStatusSubscribersState
  controller: HilosStatusSubscriberController
}) {
  const counts = useSignal(state.counts)
  const personalControlsReason = useSignal(table.personalControlsReason)
  useSignal(hilosTimeZone)

  return (
    <>
      <dl
        className="d-flex flex-wrap gap-3 mb-3"
        data-id="hilos-status-subscriber-counts"
      >
        <div>
          <dt>{HILOS_STATUS_SUBSCRIBERS_COPY.confirmed}</dt>
          <dd className="mb-0" data-id="hilos-status-subscribers-confirmed">
            {counts?.confirmedCount ?? '—'}
          </dd>
        </div>
        <div>
          <dt>{HILOS_STATUS_SUBSCRIBERS_COPY.awaitingConfirmation}</dt>
          <dd className="mb-0" data-id="hilos-status-subscribers-pending">
            {counts?.pendingCount ?? '—'}
          </dd>
        </div>
      </dl>
      <div className="hilos-stack mb-3">
        <p className="invisible mb-0" aria-hidden="true">
          {HILOS_STATUS_SUBSCRIBERS_COPY.unavailable}
        </p>
        {counts === null && (
          <p
            className="text-body-secondary mb-0"
            data-id="hilos-status-subscribers-unavailable"
          >
            {HILOS_STATUS_SUBSCRIBERS_COPY.unavailable}
          </p>
        )}
      </div>
      <HilosViewportTable
        controller={table.controller}
        dataId="hilos-status-subscribers-table"
        cells={{
          [STATUS_SUBSCRIBER_EMAIL_FIELD]: (row) => (
            <span
              className="text-break"
              data-id={`hilos-status-subscriber-email-${row.id}`}
            >
              <HilosHideable value={row.email} />
            </span>
          ),
          [STATUS_SUBSCRIBER_SUBSCRIBED_AT_FIELD]: (row) =>
            formatMoment(row.subscribedAt) ?? '—',
          [STATUS_SUBSCRIBER_CONFIRMED_FIELD]: (row) => (
            <span
              className={row.confirmed ? 'text-success' : 'text-body-secondary'}
              data-id={`hilos-status-subscriber-confirmed-${row.id}`}
            >
              {row.confirmed
                ? HILOS_STATUS_SUBSCRIBERS_COPY.yes
                : HILOS_STATUS_SUBSCRIBERS_COPY.awaitingConfirmation}
            </span>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row, rowKey) => (
            <LoadingButton
              className="btn-outline-danger btn-sm"
              disabled={personalControlsReason !== null}
              aria-label={HILOS_STATUS_SUBSCRIBERS_COPY.remove}
              data-id={`hilos-status-subscriber-remove-${row.id}`}
              onClick={() => controller.open(rowKey)}
            >
              <i className="bi bi-trash" aria-hidden="true" />
            </LoadingButton>
          ),
        }}
      />
      <HilosStatusRemoveSubscriberModal controller={controller} />
    </>
  )
}
