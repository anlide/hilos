// Overview shows the server summary and the channel's switch and test send.
import { useMemo } from 'react'
import { HilosPages, isHiddenValue } from '@hilos/core/base'
import {
  CHANNEL_ENABLED_FIELD,
  createHilosCommunicationsActions,
  type HilosCommunicationsContext,
} from '@hilos/core/admin/communications'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosSwitch } from '../../HilosSwitch.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import { useHilosCommunicationsChannelHead } from './hilosCommunicationsChannelHead.js'

/** Props for the channel Overview. */
export interface HilosCommunicationsChannelPageProps {
  /** The project's connection, page scopes and action lifecycle. */
  context: HilosCommunicationsContext
}

/** Channel aggregates and management, using only the current page's summary. */
export function HilosCommunicationsChannelPage({
  context,
}: HilosCommunicationsChannelPageProps) {
  const { summary, headProps } = useHilosCommunicationsChannelHead(context)
  const actions = useMemo(
    () => createHilosCommunicationsActions(context),
    [context],
  )
  const toggle = useTrackedAction()
  const test = useTrackedAction()

  function toggleEnabled(next: boolean): void {
    if (!summary || toggle.busy) return
    void toggle.run(
      actions.sendChannelSet(summary.channel, CHANNEL_ENABLED_FIELD, next),
    )
  }

  function sendTest(): void {
    if (!summary || test.busy) return
    void test.run(actions.sendChannelTest(summary.channel))
  }

  return (
    <HilosAdminPage page={HilosPages.COMMUNICATIONS_CHANNEL} {...headProps}>
      {summary ? (
        <>
          <div className="row row-cols-1 row-cols-sm-3 g-3 mb-4">
            <div className="col" data-id="channel-summary-configuration">
              <div className="border rounded-3 p-3 h-100">
                <h2 className="small text-body-secondary mb-1">
                  Configuration
                </h2>
                <div className="fs-4 fw-semibold lh-sm my-1">
                  {summary.configured
                    ? 'Configured'
                    : `${summary.missingFields} missing`}
                </div>
                <div className="small text-body-secondary">
                  {summary.configured
                    ? 'every field has a value'
                    : 'fields hold a bare default'}
                </div>
              </div>
            </div>
            <div className="col" data-id="channel-summary-fields">
              <div className="border rounded-3 p-3 h-100">
                <h2 className="small text-body-secondary mb-1">Fields</h2>
                <div className="fs-4 fw-semibold lh-sm my-1">
                  {summary.fieldCount}
                </div>
                <div className="small text-body-secondary">
                  {summary.secretFieldCount
                    ? `${summary.secretFieldCount} secret, set in env only`
                    : 'no secrets'}
                </div>
              </div>
            </div>
            <div className="col" data-id="channel-summary-overridden">
              <div className="border rounded-3 p-3 h-100">
                <h2 className="small text-body-secondary mb-1">
                  Overridden here
                </h2>
                <div className="fs-4 fw-semibold lh-sm my-1">
                  {summary.overriddenFieldCount}
                </div>
                <div className="small text-body-secondary">
                  {summary.overriddenFieldCount
                    ? 'reset returns them to env or the default'
                    : 'every value comes from env or the default'}
                </div>
              </div>
            </div>
          </div>
          <section
            className="border rounded-3 mb-4"
            data-id="channel-controls"
            aria-labelledby="hilos-channel-controls-title"
          >
            <h2
              className="h6 px-3 py-2 mb-0 border-bottom bg-body-tertiary rounded-top-3"
              id="hilos-channel-controls-title"
            >
              Channel
            </h2>
            <div className="d-flex align-items-center justify-content-between gap-3 p-3 border-bottom">
              <div>
                <h3 className="h6 mb-1">Enabled</h3>
                <div className="small text-body-secondary">
                  When off, no new deliveries go out through it; queued ones
                  still do.
                </div>
              </div>
              {isHiddenValue(summary.enabled) ? (
                <HilosHiddenMark />
              ) : (
                <HilosSwitch
                  checked={summary.enabled}
                  busy={toggle.busy}
                  aria-label={`Enable ${summary.label}`}
                  dataId="hilos-channel-enabled"
                  onToggle={toggleEnabled}
                />
              )}
            </div>
            <div className="d-flex align-items-center justify-content-between gap-3 p-3">
              <div>
                <h3 className="h6 mb-1">Test notification</h3>
                <div className="small text-body-secondary">
                  One notification to you, through this channel only.
                </div>
              </div>
              <LoadingButton
                className="btn-outline-primary btn-sm"
                loading={test.busy}
                data-id="hilos-channel-test"
                onClick={sendTest}
              >
                Send test notification
              </LoadingButton>
            </div>
          </section>
        </>
      ) : null}
    </HilosAdminPage>
  )
}
