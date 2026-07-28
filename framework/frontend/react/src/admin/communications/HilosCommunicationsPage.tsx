// HilosCommunicationsPage — the framework Hilos communications hub page
// (HilosPages.COMMUNICATIONS): the delivery-channels table inside the admin shell.
// One row per registered channel (built from the project's channel registry, not a
// hardcoded list), showing its enablement toggle, whether it is fully configured,
// its transport driver, and a link to its configuration page. The table, the row
// view-model, and the enablement round-trip are the core headless's
// (createHilosChannelsTable / createHilosCommunicationsActions); this view owns only
// the markup, so a project mounts it by passing its HilosCommunicationsContext.
// The toggle is a tracked action ("client action = loading + signal, never
// fire-forget"): it dispatches the shared set action with the `enabled` field, the
// outcome toasts, and the row redraws from the reactive table's snapshot signal —
// there is no new server->client signal. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo } from 'react'
import {
  CHANNEL_ENABLED_FIELD,
  HilosPages,
  createHilosChannelsTable,
  createHilosCommunicationsActions,
  resolveHilosPath,
} from '@hilos/core'
import type {
  HilosChannelRow,
  HilosCommunicationsContext,
  HilosTableColumn,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosCommunicationsPage}. */
export interface HilosCommunicationsPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosCommunicationsContext
}

const COLUMNS: HilosTableColumn[] = [
  { key: 'channel', label: 'Channel', sortable: true },
  { key: 'enabled', label: 'Enabled' },
  { key: 'configured', label: 'Configured' },
  { key: 'driver', label: 'Driver', sortable: true },
  { key: 'actions', label: '', headerClass: 'text-end' },
]

/** The channel's configuration page path (its {channelId} route param is the name). */
function channelPath(row: HilosChannelRow): string {
  return resolveHilosPath(HilosPages.COMMUNICATIONS_CHANNEL, {
    channelId: row.channel,
  })
}

/**
 * The framework communications hub page: the delivery-channels table with a
 * per-row enablement toggle and a link to each channel's configuration page.
 *
 * @param props The project context (connection, scope stores, action lifecycle).
 */
export function HilosCommunicationsPage({
  context,
}: HilosCommunicationsPageProps) {
  const channels = useMemo(() => createHilosChannelsTable(context), [context])
  const actions = useMemo(
    () => createHilosCommunicationsActions(context),
    [context],
  )

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    channels.start()

    return () => channels.dispose()
  }, [channels])

  // One tracked runner for the enablement toggles: the switch redraws from the
  // echoed row, so a single in-flight guard across rows is enough (and the busy
  // flag disables every switch while one write is settling).
  const toggle = useTrackedAction()

  // Dispatch the enablement write as a tracked action; the toggled row redraws from
  // the table's snapshot delta, so nothing is set optimistically here.
  function toggleEnabled(row: HilosChannelRow, next: boolean): void {
    void toggle.run(
      actions.sendChannelSet(row.channel, CHANNEL_ENABLED_FIELD, next),
    )
  }

  return (
    <HilosAdminPage page={HilosPages.COMMUNICATIONS}>
      <HilosViewportTable
        label="Delivery channels"
        controller={channels.controller}
        columns={COLUMNS}
        searchable
        searchPlaceholder="Search channels…"
        emptyText="No delivery channels registered."
        row={(row) => (
          <>
            <td>
              <div className="fw-semibold">{row.label}</div>
              <code className="small text-body-secondary">{row.channel}</code>
            </td>
            <td>
              <div className="form-check form-switch mb-0">
                <input
                  id={`hilos-channel-enabled-${row.channel}`}
                  type="checkbox"
                  className="form-check-input"
                  role="switch"
                  checked={row.enabled}
                  disabled={toggle.busy}
                  aria-label={`Enable ${row.label}`}
                  data-id={`hilos-channel-enabled-${row.channel}`}
                  onChange={(event) => toggleEnabled(row, event.target.checked)}
                />
              </div>
            </td>
            <td>
              {row.configured ? (
                <span className="badge text-bg-success-subtle text-success-emphasis">
                  Configured
                </span>
              ) : (
                <span
                  className="badge text-bg-warning-subtle text-warning-emphasis"
                  title={`${row.missingFields} field(s) not set`}
                >
                  {row.missingFields} missing
                </span>
              )}
            </td>
            <td>
              <code className="small">{row.driver}</code>
            </td>
            <td className="text-end">
              <HilosLink
                to={channelPath(row)}
                className="btn btn-sm btn-outline-primary"
                data-id={`hilos-channel-configure-${row.channel}`}
              >
                Configure
              </HilosLink>
            </td>
          </>
        )}
      />
    </HilosAdminPage>
  )
}
