// HilosCommunicationsDeliveriesPage — the framework Hilos delivery-logs page
// (HilosPages.COMMUNICATIONS_DELIVERIES): the admin journal of channel deliveries
// inside the admin shell. The journal is served straight from SQL (an unbounded
// table) and its rows arrive live: a status / period filter re-requests the
// window, and a new delivery or a status change reaches it as a delta. The
// per-channel route ({channelId}) opens the otherwise cross-cutting journal with
// a channel preset; the status picker, the period range, and the type/recipient
// search are what the table declares about its frame, drawn by the framework's
// bar, and ride the open viewport filter map (server-side, no local filtering).
// The single row action is retry, shown only on a failed delivery: it re-queues
// the delivery as a tracked action (createHilosDeliveriesActions), and the
// re-queued row arrives live. All table logic, the row view-model, and that
// declaration are the core headless's (hilosDeliveries); this view owns only the
// markup, so a project mounts it by passing its HilosDeliveriesContext. Bootstrap
// classes only (styling-rules.md).
import { useContext, useEffect, useMemo, useState } from 'react'
import {
  DELIVERY_ATTEMPTS_FIELD,
  DELIVERY_CHANNEL_FIELD,
  DELIVERY_CREATED_AT_FIELD,
  DELIVERY_DELIVERED_AT_FIELD,
  DELIVERY_LAST_ERROR_FIELD,
  DELIVERY_NOTIFICATION_TITLE_FIELD,
  DELIVERY_STATUS_FIELD,
  DELIVERY_USER_LABEL_FIELD,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  computedSignal,
  createHilosDeliveriesActions,
  createHilosDeliveriesTable,
  isDeliveryRetryable,
} from '@hilos/core'
import type { HilosDeliveriesContext, HilosDeliveryRow } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosCommunicationsDeliveriesPage}. */
export interface HilosCommunicationsDeliveriesPageProps {
  /** The project context: scope stores, the connection, and the action lifecycle. */
  context: HilosDeliveriesContext
}

// The status contextual badge: failed is danger, sent is success, the rest neutral.
const STATUS_CLASS: Record<string, string> = {
  failed: 'text-bg-danger',
  sent: 'text-bg-success',
  pending: 'text-bg-secondary',
}

function statusClass(status: string): string {
  return STATUS_CLASS[status] ?? 'text-bg-secondary'
}

// The recipient label: the resolved display name, its user id, both, or a dash.
function recipientLabel(row: HilosDeliveryRow): string {
  if (row.userId === null) {
    return '—'
  }
  const id = `#${row.userId}`

  return row.userLabel ? `${row.userLabel} (${id})` : id
}

/**
 * The framework delivery-logs admin page: the searchable, sortable, filterable
 * delivery journal with a per-row retry on a failed delivery.
 *
 * @param props The project context (scope stores + connection + action lifecycle).
 */
export function HilosCommunicationsDeliveriesPage({
  context,
}: HilosCommunicationsDeliveriesPageProps) {
  const router = useContext(HilosRouterContext)

  // The per-channel route ({channelId}) opens the journal with a channel preset; a
  // router-less mount (none in practice) shows the cross-cutting journal instead.
  const channelSignal = useMemo(
    () =>
      computedSignal(
        () =>
          (router?.currentRoute.get().params.channelId as string | undefined) ??
          '',
      ),
    [router],
  )
  const channel = useSignal(channelSignal)

  const deliveries = useMemo(
    () =>
      createHilosDeliveriesTable(context, channel ? { channel } : undefined),
    // The channel preset seeds a fresh table; navigating to another channel's
    // journal (a route-param change) rebinds it with the new preset.
    [context, channel],
  )
  const actions = useMemo(
    () => createHilosDeliveriesActions(context),
    [context],
  )

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    deliveries.start()

    return () => deliveries.dispose()
  }, [deliveries])

  // Retry: a per-row tracked action on a failed delivery. The re-queued row
  // arrives live — at once for the author, as a delta for everyone else — so the
  // window is not re-requested.
  const retryAction = useTrackedAction()
  const [retryPendingId, setRetryPendingId] = useState<string | null>(null)

  async function retry(row: HilosDeliveryRow): Promise<void> {
    if (retryAction.busy) {
      return
    }
    setRetryPendingId(row.rowKey)
    await retryAction.run(actions.sendDeliveryRetry(Number(row.rowKey)))
    setRetryPendingId(null)
  }

  return (
    <HilosAdminPage page={HilosPages.COMMUNICATIONS_DELIVERIES}>
      {/* The channel the route opened the journal on is the table's preset, not a
          filter of its bar: it is named here, above the table, and nothing on the
          page offers to take it off. */}
      {channel ? (
        <div className="mb-3">
          <span className="form-label d-block">Channel</span>
          <span className="badge text-bg-secondary-subtle text-secondary-emphasis fs-6">
            <code>{channel}</code>
          </span>
        </div>
      ) : null}

      <HilosViewportTable
        controller={deliveries.controller}
        cells={{
          [DELIVERY_CREATED_AT_FIELD]: (row) => row.createdAt || '—',
          [DELIVERY_CHANNEL_FIELD]: (row) => <code>{row.channel || '—'}</code>,
          [DELIVERY_STATUS_FIELD]: (row) => (
            <span className={`badge ${statusClass(row.status)}`}>
              {row.status || '—'}
            </span>
          ),
          [DELIVERY_ATTEMPTS_FIELD]: (row) => row.attempts,
          [DELIVERY_DELIVERED_AT_FIELD]: (row) => row.deliveredAt || '—',
          [DELIVERY_USER_LABEL_FIELD]: (row) => recipientLabel(row),
          [HILOS_TABLE_ACTIONS_KEY]: (row) =>
            isDeliveryRetryable(row) ? (
              <LoadingButton
                className="btn-outline-primary btn-sm"
                loading={retryAction.busy && retryPendingId === row.rowKey}
                disabled={retryAction.busy}
                data-id={`hilos-delivery-retry-${row.rowKey}`}
                onClick={() => void retry(row)}
              >
                Retry
              </LoadingButton>
            ) : null,
        }}
        details={{
          [DELIVERY_NOTIFICATION_TITLE_FIELD]: (row) => (
            <>
              <div className="fw-semibold">{row.notificationTitle || '—'}</div>
              <code className="small text-body-secondary">
                {row.notificationType}
              </code>
            </>
          ),
          [DELIVERY_LAST_ERROR_FIELD]: (row) => row.lastError || '—',
        }}
      />
    </HilosAdminPage>
  )
}
