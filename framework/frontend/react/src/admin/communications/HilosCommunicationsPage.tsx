// HilosCommunicationsPage — the framework Hilos communications hub page
// (HilosPages.COMMUNICATIONS): the delivery-channels table inside the admin shell.
// One row per registered channel (built from the project's channel registry, not a
// hardcoded list), showing its enablement toggle, whether it is fully configured,
// its transport driver, and a link to its configuration page. The table, the frame
// it declares (its columns, search, and empty words), the row view-model, and the
// enablement round-trip are the core headless's (createHilosChannelsTable /
// createHilosCommunicationsActions); this view owns only the cell markup, so a
// project mounts it by passing its HilosCommunicationsContext.
// The toggle is a tracked action ("client action = loading + signal, never
// fire-forget"): it dispatches the shared set action with the `enabled` field, the
// outcome toasts, and the row redraws from the reactive table's snapshot signal —
// there is no new server->client signal. Bootstrap classes only (styling-rules.md).
import { useEffect, useMemo, useState } from 'react'
import {
  CHANNEL_ENABLED_FIELD,
  HILOS_TABLE_ACTIONS_KEY,
  HilosChannelRowKey,
  HilosPages,
  createHilosChannelsTable,
  createHilosCommunicationsActions,
  resolveHilosPath,
} from '@hilos/core'
import type { HilosChannelRow, HilosCommunicationsContext } from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { useTrackedAction } from '../../useTrackedAction.js'

/** Props for {@link HilosCommunicationsPage}. */
export interface HilosCommunicationsPageProps {
  /** The project context: connection, scope stores, and the action lifecycle. */
  context: HilosCommunicationsContext
}

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
  const [pendingChannel, setPendingChannel] = useState<string | null>(null)

  // Dispatch the enablement write as a tracked action; the toggled row redraws from
  // the table's snapshot delta, so nothing is set optimistically here.
  async function toggleEnabled(
    row: HilosChannelRow,
    next: boolean,
  ): Promise<void> {
    setPendingChannel(row.channel)
    try {
      await toggle.run(
        actions.sendChannelSet(row.channel, CHANNEL_ENABLED_FIELD, next),
      )
    } finally {
      setPendingChannel(null)
    }
  }

  return (
    <HilosAdminPage page={HilosPages.COMMUNICATIONS}>
      <HilosViewportTable
        controller={channels.controller}
        cells={{
          [HilosChannelRowKey.channel]: (row) => (
            <>
              <div className="fw-semibold">{row.label}</div>
              <code className="small text-body-secondary">{row.channel}</code>
            </>
          ),
          [HilosChannelRowKey.enabled]: (row) => (
            <HilosSwitch
              className="mb-0"
              checked={row.enabled}
              busy={pendingChannel === row.channel}
              disabled={toggle.busy}
              aria-label={`Enable ${row.label}`}
              dataId={`hilos-channel-enabled-${row.channel}`}
              onToggle={(next) => void toggleEnabled(row, next)}
            />
          ),
          [HilosChannelRowKey.configured]: (row) =>
            row.configured ? (
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
            ),
          [HilosChannelRowKey.driver]: (row) => (
            <code className="small">{row.driver ?? '—'}</code>
          ),
          [HILOS_TABLE_ACTIONS_KEY]: (row) => (
            <HilosLink
              to={channelPath(row)}
              className="btn btn-sm btn-outline-primary"
              data-id={`hilos-channel-configure-${row.channel}`}
            >
              Configure
            </HilosLink>
          ),
        }}
      />
    </HilosAdminPage>
  )
}
