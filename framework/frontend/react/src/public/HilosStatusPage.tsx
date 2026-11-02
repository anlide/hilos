// HilosStatusPage — the public /status page: the overall verdict, Checking, the
// published outages with their timelines, scheduled and current maintenance,
// the components with their 90-day strips (30 on a narrow screen), the past
// incidents and the time of the last mark from the system. React counterpart of
// the Vue page. It reads the two nginx files even before a handshake and asks
// the socket for nothing: no page subscription, no page answer. One effect run
// creates the core file reader and the subscription controller of that same
// mount, starts both and disposes both in its cleanup, so a StrictMode replay
// or a new connection gets a live pair of its own and a discarded render leaves
// no listener, timer or fetch behind. The subscription's actions never delay or
// replace the file reader. Precedence, freshness, calendar days and intervals
// belong to the core; this view only draws them, with the same words beside
// every colored badge and day. Bootstrap classes only (styling-rules.md).
import { Fragment, useEffect, useId, useState } from 'react'
import {
  formatMoment,
  hilosTimeZone,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core/base'
import {
  createPublicStatus,
  createStatusSubscription,
  STATUS_SUBSCRIPTION_COPY,
  type PublicStatus,
  type PublicStatusAnnouncement,
  type PublicStatusDay,
  type PublicStatusDayStrip,
  type PublicStatusState,
  type StatusSubscription,
} from '@hilos/core/status'

import { HilosFormError } from '../HilosFormError.js'
import { HilosStaticPage } from '../HilosStaticPage.js'
import { useSignal } from '../useSignal.js'
import { HilosStatusSubscriptionModal } from './HilosStatusSubscriptionModal.js'

/** Props for {@link HilosStatusPage}. */
export interface HilosStatusPageProps {
  /** The project's connection singleton; no session answer is required. */
  connection: HilosConnection
  /** The project's existing lifecycle for correlated public actions. */
  actions: ActionLifecycle
}

/** The file reader and the subscription one effect run created and started. */
interface PublicStatusGeneration {
  readonly reader: PublicStatus
  readonly subscription: StatusSubscription
}

/** The day a strip button selected by focus or click. */
interface SelectedDay {
  readonly componentKey: string
  readonly startTs: number
}

/** The words, tone and icon of each state; the words always go with the color. */
const STATES: Record<
  PublicStatusState | 'not_responding',
  { readonly label: string; readonly tone: string; readonly icon: string }
> = {
  operational: { label: 'Operational', tone: 'success', icon: 'check-circle' },
  checking: { label: 'Checking', tone: 'secondary', icon: 'search' },
  partial_outage: {
    label: 'Partial outage',
    tone: 'warning',
    icon: 'exclamation-triangle',
  },
  major_outage: { label: 'Major outage', tone: 'danger', icon: 'x-circle' },
  maintenance: { label: 'Maintenance', tone: 'info', icon: 'tools' },
  unknown: { label: 'Unknown', tone: 'secondary', icon: 'question-circle' },
  not_responding: {
    label: 'Not responding',
    tone: 'danger',
    icon: 'exclamation-triangle',
  },
}

/** What is known about announcing an actual maintenance in advance. */
const ANNOUNCEMENT_LABELS: Record<PublicStatusAnnouncement, string> = {
  announced: 'Announced in advance',
  unannounced: 'Not announced in advance',
  unknown: 'Advance announcement unknown',
}

/**
 * A moment in the reader's zone, or a dash when it cannot be drawn.
 *
 * @param at The moment in milliseconds.
 */
function moment(at: number): string {
  return formatMoment(at) ?? '—'
}

/**
 * The accessible name of a strip day: its date in the reader's zone and its
 * state in words.
 *
 * @param day The calendar day and its worst published fact.
 */
function dayLabel(day: PublicStatusDay): string {
  return `${formatMoment(day.startTs, { kind: 'date' }) ?? '—'} · ${STATES[day.state].label}`
}

/**
 * The public status page, read from the published files.
 *
 * @param props The project's connection and action lifecycle.
 */
export function HilosStatusPage({ connection, actions }: HilosStatusPageProps) {
  const [generation, setGeneration] = useState<PublicStatusGeneration | null>(
    null,
  )

  useEffect(() => {
    const reader = createPublicStatus(connection)
    const subscription = createStatusSubscription({ connection, actions })
    subscription.start()
    reader.start()
    setGeneration({ reader, subscription })

    return () => {
      subscription.dispose()
      reader.dispose()
      setGeneration((current) => (current?.reader === reader ? null : current))
    }
  }, [connection, actions])

  return (
    <HilosStaticPage title="System status" icon="bi-activity">
      {generation && (
        <PublicStatusView
          reader={generation.reader}
          subscription={generation.subscription}
        />
      )}
    </HilosStaticPage>
  )
}

/**
 * The page body over one live generation's reader and subscription.
 *
 * @param props The started reader and subscription of this generation.
 * @param props.reader The file-derived public projection.
 * @param props.subscription The subscription controller the page's window draws.
 */
function PublicStatusView({ reader, subscription }: PublicStatusGeneration) {
  const subscriptionUsable = useSignal(subscription.usable)
  const subscriptionOperation = useSignal(subscription.operation)
  const subscriptionDialog = useSignal(subscription.dialog)
  const subscriptionStep = useSignal(subscription.step)
  const subscriptionError = useSignal(subscription.error)
  const subscriptionSuccess = useSignal(subscription.success)
  const projection = useSignal(reader.projection)
  const freshness = useSignal(reader.freshness)
  const banner = useSignal(reader.banner)
  const connectionMessage = useSignal(reader.connectionMessage)
  const components = useSignal(reader.components)
  const currentOutages = useSignal(reader.currentOutages)
  const history = useSignal(reader.history)
  const upcomingWorks = useSignal(reader.upcomingWorks)
  const currentMaintenance = useSignal(reader.currentMaintenance)
  const dayStrips = useSignal(reader.dayStrips)
  // Every moment below is formatted in the reader's zone; a zone change
  // redraws them all.
  useSignal(hilosTimeZone)
  const detailId = useId()
  const [selectedDay, setSelectedDay] = useState<SelectedDay | null>(null)

  const subscriptionProgress =
    subscriptionStep === 'waiting'
      ? STATUS_SUBSCRIPTION_COPY.waiting
      : subscriptionStep !== 'pending'
        ? ''
        : subscriptionOperation === 'confirm'
          ? 'Confirming your subscription…'
          : 'Waiting for confirmation…'
  const subscriptionNotice = subscriptionSuccess ?? subscriptionProgress
  // A confirmed address is the one success; a sent link and an ended
  // subscription are neutral news. The mailed link gets the envelope.
  const subscriptionNoticeClass =
    subscriptionSuccess !== null && subscriptionOperation === 'confirm'
      ? 'alert-success'
      : 'alert-secondary'
  const subscriptionNoticeIcon =
    subscriptionSuccess === null
      ? null
      : subscriptionOperation === 'subscribe'
        ? 'bi-envelope'
        : subscriptionOperation === 'confirm'
          ? 'bi-check-circle'
          : null
  const bannerState = STATES[banner.kind]
  const candidates =
    freshness.kind === 'fresh' ? (projection?.candidates ?? []) : []
  const lastUpdate =
    freshness.aliveAt === null ? null : moment(freshness.aliveAt)

  const componentName = (key: string): string =>
    components.find((component) => component.componentKey === key)?.name ?? key

  const dayDetail = (strip: PublicStatusDayStrip): string => {
    const day =
      (selectedDay?.componentKey === strip.componentKey
        ? strip.days.find((item) => item.startTs === selectedDay.startTs)
        : undefined) ?? strip.days.at(-1)
    return day === undefined ? '' : dayLabel(day)
  }

  return (
    <>
      <div className="hilos-stack mb-3">
        <div
          className="invisible d-flex justify-content-end"
          aria-hidden="true"
          inert
        >
          <span className="btn btn-sm btn-outline-primary">
            <i className="bi bi-envelope me-1" aria-hidden="true" />
            {STATUS_SUBSCRIPTION_COPY.subscribe}
          </span>
        </div>
        {subscriptionUsable && (
          <div className="d-flex justify-content-end">
            <button
              type="button"
              className="btn btn-sm btn-outline-primary"
              data-id="public-status-subscribe"
              onClick={() => subscription.open()}
            >
              <i className="bi bi-envelope me-1" aria-hidden="true" />
              {STATUS_SUBSCRIPTION_COPY.subscribe}
            </button>
          </div>
        )}
      </div>
      <div
        className="hilos-stack small mb-3"
        data-id="public-status-subscription-slot"
      >
        <div
          className="alert alert-secondary mb-0 invisible"
          aria-hidden="true"
          inert
        >
          <i className="bi bi-check-circle me-1" aria-hidden="true" />
          {STATUS_SUBSCRIPTION_COPY.subscribed}
        </div>
        {!subscriptionDialog && subscriptionNotice !== '' && (
          <div
            className={`alert mb-0 ${subscriptionNoticeClass}`}
            data-id="public-status-subscription-notice"
          >
            {subscriptionNoticeIcon && (
              <i
                className={`bi ${subscriptionNoticeIcon} me-1`}
                aria-hidden="true"
              />
            )}
            {subscriptionNotice}
          </div>
        )}
        <HilosFormError
          message={subscriptionDialog ? null : subscriptionError}
          dataId="public-status-subscription-error"
          announce
        />
      </div>
      <div
        className="visually-hidden"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-id="public-status-subscription-live"
      >
        {subscriptionDialog ? '' : subscriptionNotice}
      </div>
      <div
        className="visually-hidden"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-id="public-status-live"
      >
        {banner.title} {connectionMessage}
      </div>

      <div className="hilos-stack mb-3 small">
        <div className="invisible border rounded p-2" aria-hidden="true" inert>
          Your live connection to the site is lost — the system is up
        </div>
        {connectionMessage && (
          <div
            className="border rounded p-2 bg-warning-subtle text-warning-emphasis"
            data-id="public-status-connection"
          >
            {connectionMessage}
          </div>
        )}
      </div>

      <div
        className={`border rounded p-3 mb-3 bg-${bannerState.tone}-subtle text-${bannerState.tone}-emphasis`}
        data-id="public-status-banner"
      >
        <h2 className="h5 mb-0">
          <i className={`bi bi-${bannerState.icon} me-2`} aria-hidden="true" />
          {banner.title}
        </h2>
        {freshness.kind === 'unknown' && (
          <p className="small mt-2 mb-0">
            There is no trustworthy status picture yet. This page keeps
            checking.
          </p>
        )}
      </div>

      {candidates.length > 0 && (
        <section className="card mb-3" data-id="public-status-candidates">
          <div className="card-header">
            <h2 className="h6 mb-0">Checking</h2>
          </div>
          <ul className="list-group list-group-flush">
            {candidates.map((candidate) => (
              <li key={candidate.componentKey} className="list-group-item">
                <h3 className="h6">
                  Checking a possible problem with{' '}
                  {componentName(candidate.componentKey)}
                </h3>
                <p className="small mb-0">
                  Detected at {moment(candidate.detectedAt)}. If confirmed, it
                  will appear here as an incident.
                </p>
              </li>
            ))}
          </ul>
        </section>
      )}

      {currentOutages.length > 0 && (
        <section className="mb-3" data-id="public-status-outages">
          <h2 className="h6">Current incidents</h2>
          {currentOutages.map((outage) => (
            <article key={outage.id} className="card mb-3">
              <div className="card-header d-flex flex-wrap align-items-center gap-2">
                <h3 className="h6 mb-0 text-break">
                  {componentName(outage.componentKey)}
                </h3>
                <span
                  className={`badge text-bg-${STATES[outage.severity].tone}`}
                >
                  {STATES[outage.severity].label}
                </span>
                <span className="small">
                  Since {outage.detectedAtLabel} · {outage.durationLabel}
                </span>
              </div>
              {outage.updates.length > 0 && (
                <ul className="list-group list-group-flush small">
                  {outage.updates.map((update, index) => (
                    <li
                      key={index}
                      className="list-group-item text-break"
                      data-id="public-status-update"
                    >
                      <span className="text-body-secondary">
                        {moment(update.at)}
                      </span>
                      <p className="mb-1">{update.text}</p>
                      <span className="badge bg-body-secondary text-body border fw-normal">
                        {update.source === 'team'
                          ? 'From the team'
                          : 'Automatic'}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </article>
          ))}
        </section>
      )}

      {upcomingWorks.length > 0 && (
        <section className="mb-3" data-id="public-status-works">
          <h2 className="h6">Scheduled maintenance</h2>
          {upcomingWorks.map((work) => (
            <article key={work.id} className="card border-info mb-3 text-break">
              <div className="card-header bg-info-subtle d-flex flex-wrap gap-2">
                <h3 className="h6 mb-0">{work.title}</h3>
                <span className="small ms-auto">
                  {work.startsAtLabel ?? '—'} – {work.endsAtLabel ?? '—'}
                </span>
              </div>
              <div className="card-body small">
                {work.description && <p className="mb-1">{work.description}</p>}
                <p className="mb-0">
                  Affects: {work.componentNames.join(', ')}
                </p>
              </div>
            </article>
          ))}
        </section>
      )}

      {currentMaintenance.length > 0 && (
        <section className="mb-3" data-id="public-status-maintenance">
          <h2 className="h6">Maintenance</h2>
          {currentMaintenance.map((period) => (
            <article
              key={period.id}
              className="card border-info mb-3 text-break"
            >
              <div className="card-header bg-info-subtle d-flex flex-wrap gap-2">
                <h3 className="h6 mb-0">{period.title}</h3>
                <span className="badge text-bg-info text-wrap">
                  {period.confirmedCurrent
                    ? 'Maintenance in progress'
                    : 'Last observed maintenance'}
                </span>
              </div>
              <div className="card-body small">
                <div className="hilos-stack mb-2">
                  <p className="invisible mb-0" aria-hidden="true" inert>
                    This is a retained observation. Current maintenance status
                    is unknown.
                  </p>
                  {!period.confirmedCurrent && (
                    <p
                      className="mb-0"
                      data-id="public-status-maintenance-retained"
                    >
                      This is a retained observation. Current maintenance status
                      is unknown.
                    </p>
                  )}
                </div>
                <dl className="row mb-0">
                  <dt className="col-sm-4">Advance announcement</dt>
                  <dd className="col-sm-8">
                    {ANNOUNCEMENT_LABELS[period.announcement]}
                  </dd>
                  {period.plannedWork && (
                    <>
                      <dt className="col-sm-4">Planned window</dt>
                      <dd className="col-sm-8">
                        {period.plannedWork.startsAtLabel ?? '—'} –{' '}
                        {period.plannedWork.endsAtLabel ?? '—'}
                      </dd>
                    </>
                  )}
                  <dt className="col-sm-4">Started</dt>
                  <dd className="col-sm-8">{period.startedAtLabel ?? '—'}</dd>
                  <dt className="col-sm-4">Affects</dt>
                  <dd className="col-sm-8">
                    {period.componentNames.join(', ')}
                  </dd>
                </dl>
                {period.plannedWork && (
                  <p className="mb-0">
                    The scheduled window does not determine the actual end.
                  </p>
                )}
              </div>
            </article>
          ))}
        </section>
      )}

      {components.length > 0 && (
        <section className="card mb-3" data-id="public-status-components">
          <div className="card-header">
            <h2 className="h6 mb-0">Components</h2>
          </div>
          {components.map((component, componentIndex) => (
            <div key={component.componentKey} className="card-body border-top">
              <div className="d-flex flex-wrap align-items-start gap-2 mb-2">
                <div className="flex-grow-1 text-break">
                  <h3 className="h6 mb-1">{component.name}</h3>
                  {component.description && (
                    <p className="small mb-0">{component.description}</p>
                  )}
                </div>
                <span
                  className={`badge text-bg-${STATES[component.state].tone}`}
                  data-id="public-status-component-state"
                >
                  {STATES[component.state].label}
                </span>
              </div>
              {dayStrips
                .filter((item) => item.componentKey === component.componentKey)
                .map((strip) => (
                  <Fragment key={strip.componentKey}>
                    {[
                      {
                        days: strip.days,
                        classes: 'd-none d-md-block',
                        count: 90,
                      },
                      {
                        days: strip.narrowDays,
                        classes: 'd-md-none',
                        count: 30,
                      },
                    ].map((variant) => (
                      <div
                        key={variant.count}
                        className={variant.classes}
                        data-id={`public-status-days-${variant.count}`}
                      >
                        <div
                          className="d-flex gap-1"
                          role="group"
                          aria-label={`${component.name}: last ${variant.count} days`}
                        >
                          {variant.days.map((day) => {
                            const select = () =>
                              setSelectedDay({
                                componentKey: component.componentKey,
                                startTs: day.startTs,
                              })
                            return (
                              <button
                                key={day.startTs}
                                type="button"
                                className={`btn flex-fill p-0 py-2 border-0 rounded-1 btn-${STATES[day.state].tone}`}
                                aria-label={dayLabel(day)}
                                aria-describedby={`${detailId}-${componentIndex}`}
                                onFocus={select}
                                onClick={select}
                              />
                            )
                          })}
                        </div>
                        <div className="d-flex justify-content-between small text-body-secondary mt-1">
                          <span>{variant.count} days</span>
                          <span>Today</span>
                        </div>
                      </div>
                    ))}
                    <p
                      id={`${detailId}-${componentIndex}`}
                      className="small mb-0 mt-1"
                      data-id="public-status-day-detail"
                    >
                      {dayDetail(strip)}
                    </p>
                  </Fragment>
                ))}
            </div>
          ))}
        </section>
      )}

      {projection && (
        <section className="card mb-3" data-id="public-status-history">
          <div className="card-header">
            <h2 className="h6 mb-0">Past incidents</h2>
          </div>
          {history.length === 0 ? (
            <p className="card-body small mb-0">No past published incidents</p>
          ) : (
            <ul className="list-group list-group-flush small">
              {history.map((entry) => (
                <li
                  key={`${entry.kind}-${entry.id}`}
                  className="list-group-item text-break"
                >
                  {entry.kind === 'outage' ? (
                    <>
                      <h3 className="h6">
                        {componentName(entry.componentKey)}{' '}
                        <span
                          className={`badge text-bg-${STATES[entry.severity].tone}`}
                        >
                          {STATES[entry.severity].label}
                        </span>
                      </h3>
                      <p className="mb-1">
                        {entry.detectedAtLabel} · {entry.durationLabel}
                      </p>
                      <p className="mb-1">
                        Resolved at {entry.resolvedAtLabel}
                      </p>
                      {entry.updates.map((update, index) => (
                        <p key={index} className="mb-1">
                          {moment(update.at)} · {update.text}
                        </p>
                      ))}
                    </>
                  ) : (
                    <>
                      <h3 className="h6">
                        {entry.title}{' '}
                        <span className="badge text-bg-info">Maintenance</span>{' '}
                        <span className="badge bg-body-secondary text-body">
                          Completed
                        </span>
                      </h3>
                      <p className="mb-1">
                        {ANNOUNCEMENT_LABELS[entry.announcement]}
                      </p>
                      <p className="mb-1">
                        Affects: {entry.componentNames.join(', ')}
                      </p>
                      <p className="mb-1">
                        {entry.startedAtLabel}
                        {entry.durationLabel !== null && (
                          <span> · {entry.durationLabel}</span>
                        )}
                      </p>
                      {entry.endedAt !== null ? (
                        <p className="mb-0">
                          Completed at {entry.endedAtLabel}
                        </p>
                      ) : (
                        <p className="mb-0">
                          Completion observed at{' '}
                          {entry.completionObservedAtLabel}. Exact end unknown.
                        </p>
                      )}
                    </>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      <p
        className="small text-body-secondary"
        data-id="public-status-last-update"
      >
        Last update from the system: {lastUpdate ?? 'Unknown'}
      </p>
      <div className="hilos-stack small text-body-secondary">
        <p className="invisible mb-0" aria-hidden="true" inert>
          Subscribed already? Every letter has a link to unsubscribe.
        </p>
        {subscriptionUsable && (
          <p className="mb-0" data-id="public-status-subscription-hint">
            Subscribed already? Every letter has a link to unsubscribe.
          </p>
        )}
      </div>
      <HilosStatusSubscriptionModal controller={subscription} />
    </>
  )
}
