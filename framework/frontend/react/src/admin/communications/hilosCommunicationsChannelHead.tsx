import { useMemo } from 'react'
import { computedSignal, HilosPages } from '@hilos/core/base'
import {
  createHilosChannelSummary,
  type HilosCommunicationsContext,
} from '@hilos/core/admin/communications'

import { HilosHideable } from '../../HilosHideable.js'
import { usePageInstanceName } from '../../usePageInstanceName.js'
import { useSignal } from '../../useSignal.js'

/** The permitted summary supplies one shared entity head on all channel tabs. */
export function useHilosCommunicationsChannelHead(
  context: Pick<HilosCommunicationsContext, 'scopes'>,
) {
  const source = useMemo(() => createHilosChannelSummary(context), [context])
  const current = useMemo(
    () =>
      computedSignal(() => {
        const page = context.scopes.page()?.key
        if (
          page !== HilosPages.COMMUNICATIONS_CHANNEL &&
          page !== HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION &&
          page !== HilosPages.COMMUNICATIONS_DELIVERIES
        )
          return null
        return source.get()
      }),
    [context, source],
  )
  const name = useMemo(
    () => computedSignal(() => current.get()?.label),
    [current],
  )
  usePageInstanceName(context.scopes, HilosPages.COMMUNICATIONS_CHANNEL, name)
  const summary = useSignal(current)

  return {
    summary,
    headProps: {
      headKind: 'entity' as const,
      tabsLabel: 'Channel pages',
      headVisual: (
        <span
          className="bg-body-secondary rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
          aria-hidden="true"
          data-id="channel-head-icon"
        >
          <i className="bi bi-envelope-paper" />
        </span>
      ),
      headBadges: summary ? (
        <>
          <span data-id="channel-head-enabled">
            <HilosHideable value={summary.enabled}>
              {(enabled) => (
                <span
                  className={`badge ${enabled ? 'text-bg-success' : 'text-bg-secondary'}`}
                >
                  {enabled ? 'Enabled' : 'Disabled'}
                </span>
              )}
            </HilosHideable>
          </span>
          <span
            className={`badge ${summary.configured ? 'text-bg-success' : 'text-bg-warning'}`}
            title={`${summary.missingFields} field(s) not set`}
            data-id="channel-head-configured"
          >
            {summary.configured
              ? 'Configured'
              : `${summary.missingFields} missing`}
          </span>
        </>
      ) : undefined,
      headDescription: summary ? (
        <p
          className="text-body-secondary mb-0"
          data-id="channel-head-description"
        >
          Channel <code>{summary.channel}</code> · driver{' '}
          <code>{summary.driver ?? '—'}</code>
        </p>
      ) : undefined,
    },
  }
}
