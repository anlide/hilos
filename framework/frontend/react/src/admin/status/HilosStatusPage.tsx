// HilosStatusPage — the Overview of the framework Status section inside the
// admin shell. React counterpart of the Vue page: the shared section head with
// the five Status tabs, the open incident facts with their timeline and the three
// incident operations, the maintenance in progress, the four summary tiles over
// the server's own counts, and the publication delay the settings hold. Every
// fact arrives in the one page answer and the core reads it: the state keeps the
// health, the countdown on the server clock and the incident cards, the incident
// controller keeps the window and its tracked actions. Both are created and
// started in the effect and disposed by the cleanup of that same run, so a
// StrictMode replay or a new context gets a live pair of its own and a discarded
// render leaves no listener behind. Unknown health stays neutral, and an
// unavailable count is a dash, never a zero. Bootstrap classes only.
import { useContext, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { formatMoment, hilosTimeZone, HilosPages } from '@hilos/core/base'
import type { HilosRouter } from '@hilos/core/base'
import {
  createHilosStatusIncidentController,
  createHilosStatusState,
  HILOS_STATUS_ACTION_COPY,
  HILOS_STATUS_COPY,
  HILOS_STATUS_HISTORY_COPY,
  HILOS_STATUS_SUBSCRIBERS_COPY,
  HILOS_STATUS_WORKS_COPY,
  type HilosStatusContext,
  type HilosStatusIncidentCard,
  type HilosStatusIncidentController,
  type HilosStatusState,
} from '@hilos/core/admin/status'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { LoadingButton } from '../../LoadingButton.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'
import { HilosStatusIncidentModal } from './HilosStatusIncidentModal.js'

/** Props for {@link HilosStatusPage}. */
export interface HilosStatusPageProps {
  /** The project's existing scope, connection and action lifecycle. */
  context: HilosStatusContext
}

/** The state and the incident window one effect run created and started. */
interface StatusGeneration {
  readonly state: HilosStatusState
  readonly controller: HilosStatusIncidentController
}

/** The tone of an incident card, its header and its badge, by the card's state. */
const CARD_TONES: Record<
  HilosStatusIncidentCard['state'],
  { readonly card: string; readonly header: string; readonly badge: string }
> = {
  major_outage: {
    card: 'border-danger',
    header: 'bg-danger-subtle',
    badge: 'text-bg-danger',
  },
  partial_outage: {
    card: 'border-warning',
    header: 'bg-warning-subtle',
    badge: 'text-bg-warning',
  },
  checking: {
    card: 'border-secondary',
    header: 'bg-secondary-subtle',
    badge: 'text-bg-secondary',
  },
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
 * Status health and incident facts, which arrive together in the page answer.
 *
 * @param props The project's scope, connection and action lifecycle.
 */
export function HilosStatusPage({ context }: HilosStatusPageProps) {
  const [generation, setGeneration] = useState<StatusGeneration | null>(null)

  useEffect(() => {
    const state = createHilosStatusState(context)
    const controller = createHilosStatusIncidentController(context, state)
    state.start()
    controller.start()
    setGeneration({ state, controller })

    return () => {
      controller.dispose()
      state.dispose()
      setGeneration((current) => (current?.state === state ? null : current))
    }
  }, [context])

  return (
    <HilosAdminPage
      page={HilosPages.STATUS}
      headKind="section"
      tabsLabel="Status pages"
    >
      {generation && (
        <StatusOverview
          state={generation.state}
          controller={generation.controller}
        />
      )}
    </HilosAdminPage>
  )
}

/**
 * The Overview of one live generation and its incident window.
 *
 * @param props The started state and incident controller of this generation.
 * @param props.state The overview, cards, summary and maintenance projection.
 * @param props.controller The incident window the card buttons open.
 */
function StatusOverview({
  state,
  controller,
}: {
  state: HilosStatusState
  controller: HilosStatusIncidentController
}) {
  const router = useContext(HilosRouterContext)
  const overview = useSignal(state.overview)
  const cards = useSignal(state.cards)
  const summary = useSignal(state.summary)
  const maintenance = useSignal(state.maintenance)
  useSignal(hilosTimeZone)

  return (
    <>
      {overview && (
        <div data-id="hilos-status-overview">
          {summary === 'operational' ? (
            <div
              className="alert alert-success d-flex flex-wrap gap-3 align-items-center"
              data-id="hilos-status-operational"
            >
              <span
                className="flex-grow-1"
                data-id="hilos-status-operational-label"
              >
                {HILOS_STATUS_COPY.allOperational}
              </span>
              <a
                href="/status"
                className="btn btn-outline-secondary btn-sm text-nowrap"
                data-id="hilos-status-public-link"
              >
                {HILOS_STATUS_COPY.publicPage}
              </a>
            </div>
          ) : summary === 'unknown' ? (
            <div
              className="alert alert-secondary"
              data-id="hilos-status-unknown"
            >
              {HILOS_STATUS_COPY.unknown}
            </div>
          ) : summary === 'checking' ? (
            <div
              className="alert alert-secondary"
              data-id="hilos-status-checking"
            >
              {HILOS_STATUS_COPY.checking}
            </div>
          ) : null}

          {maintenance && (
            <section
              className="card border-info mb-3"
              data-id="hilos-status-maintenance"
            >
              <div className="card-header bg-info-subtle">
                <h2 className="h6 mb-0">Maintenance in progress</h2>
              </div>
              <div className="card-body text-break">
                <dl className="row mb-2">
                  <dt className="col-sm-4">Advance announcement</dt>
                  <dd
                    className="col-sm-8"
                    data-id="hilos-status-maintenance-announcement"
                  >
                    <div className="hilos-stack">
                      <p className="invisible mb-0" aria-hidden="true" inert>
                        Announcement details are unavailable.
                      </p>
                      {maintenance.announcement === null ? (
                        <p className="mb-0">
                          Announcement details are unavailable.
                        </p>
                      ) : !maintenance.announcement.known ? (
                        <p className="mb-0">Advance announcement unknown</p>
                      ) : maintenance.announcement.work === null ? (
                        <p className="mb-0">Not announced in advance</p>
                      ) : (
                        <div>
                          <p className="mb-1">
                            {maintenance.announcement.work.title}
                          </p>
                          <p className="small mb-0">
                            {moment(maintenance.announcement.work.startsAt)} –{' '}
                            {moment(maintenance.announcement.work.endsAt)}
                          </p>
                        </div>
                      )}
                    </div>
                  </dd>
                  <dt className="col-sm-4">Phase</dt>
                  <dd className="col-sm-8">{maintenance.phase}</dd>
                  <dt className="col-sm-4">Operation</dt>
                  <dd className="col-sm-8">
                    <code>{maintenance.operation ?? '—'}</code>
                  </dd>
                  <dt className="col-sm-4">Started</dt>
                  <dd className="col-sm-8">
                    {maintenance.startedAtLabel ?? '—'}
                  </dd>
                </dl>
                <StatusLink
                  router={router}
                  page={HilosPages.MAINTENANCE}
                  dataId="hilos-status-maintenance-link"
                >
                  View Maintenance
                </StatusLink>
              </div>
            </section>
          )}
          {overview.incidents === null && (
            <p
              className="text-body-secondary"
              data-id="hilos-status-incidents-unavailable"
            >
              {HILOS_STATUS_COPY.incidentsUnavailable}
            </p>
          )}
          {cards.map((card) => (
            <IncidentCard
              key={card.incident.incidentId}
              card={card}
              controller={controller}
            />
          ))}
          <div className="row row-cols-2 row-cols-lg-4 g-3 mb-3">
            <div className="col">
              <section className="card h-100" data-id="hilos-status-works-tile">
                <div className="card-body text-break">
                  <h2 className="h6">Scheduled maintenance</h2>
                  <p className="fs-4 mb-2" data-id="hilos-status-works-count">
                    {overview.scheduledWorks?.count ?? '—'}
                  </p>
                  {overview.scheduledWorks?.nextWork && (
                    <p data-id="hilos-status-works-next">
                      Next: {overview.scheduledWorks.nextWork.title}
                      <br />
                      <span className="small">
                        {moment(overview.scheduledWorks.nextWork.startsAt)} –{' '}
                        {moment(overview.scheduledWorks.nextWork.endsAt)}
                      </span>
                    </p>
                  )}
                  <div className="hilos-stack mb-2 small">
                    <p className="invisible mb-0" aria-hidden="true" inert>
                      Scheduled maintenance data is unavailable.
                    </p>
                    {overview.scheduledWorks === null ? (
                      <p
                        className="text-body-secondary mb-0"
                        data-id="hilos-status-works-unavailable"
                      >
                        Scheduled maintenance data is unavailable.
                      </p>
                    ) : overview.scheduledWorks.count === 0 ? (
                      <p className="mb-0" data-id="hilos-status-works-empty">
                        {HILOS_STATUS_WORKS_COPY.empty}
                      </p>
                    ) : null}
                  </div>
                  <StatusLink
                    router={router}
                    page={HilosPages.STATUS_WORKS}
                    dataId="hilos-status-works-link"
                  >
                    Scheduled maintenance
                  </StatusLink>
                </div>
              </section>
            </div>
            <div className="col">
              <section className="card h-100">
                <div className="card-body">
                  <h2 className="h6">{HILOS_STATUS_COPY.components}</h2>
                  <StatusLink
                    router={router}
                    page={HilosPages.STATUS_COMPONENTS}
                    dataId="hilos-status-components-link"
                  >
                    {overview.components.operationalCount} /{' '}
                    {overview.components.totalCount} operational ·{' '}
                    {HILOS_STATUS_COPY.componentsLink}
                  </StatusLink>
                </div>
              </section>
            </div>
            <div className="col">
              <section
                className="card h-100"
                data-id="hilos-status-subscribers-tile"
              >
                <div className="card-body">
                  <h2 className="h6">
                    {HILOS_STATUS_SUBSCRIBERS_COPY.subscribers}
                  </h2>
                  <dl className="mb-2">
                    <div className="d-flex gap-2">
                      <dt>{HILOS_STATUS_SUBSCRIBERS_COPY.confirmed}</dt>
                      <dd
                        className="mb-0"
                        data-id="hilos-status-subscribers-tile-confirmed"
                      >
                        {overview.subscribers?.confirmedCount ?? '—'}
                      </dd>
                    </div>
                    <div className="d-flex gap-2">
                      <dt>
                        {HILOS_STATUS_SUBSCRIBERS_COPY.awaitingConfirmation}
                      </dt>
                      <dd
                        className="mb-0"
                        data-id="hilos-status-subscribers-tile-pending"
                      >
                        {overview.subscribers?.pendingCount ?? '—'}
                      </dd>
                    </div>
                  </dl>
                  <div className="hilos-stack mb-2">
                    <p className="invisible mb-0" aria-hidden="true">
                      {HILOS_STATUS_SUBSCRIBERS_COPY.unavailable}
                    </p>
                    {overview.subscribers === null && (
                      <p
                        className="text-body-secondary mb-0"
                        data-id="hilos-status-subscribers-tile-unavailable"
                      >
                        {HILOS_STATUS_SUBSCRIBERS_COPY.unavailable}
                      </p>
                    )}
                  </div>
                  <StatusLink
                    router={router}
                    page={HilosPages.STATUS_SUBSCRIBERS}
                    dataId="hilos-status-subscribers-link"
                  >
                    {HILOS_STATUS_SUBSCRIBERS_COPY.subscribers}
                  </StatusLink>
                </div>
              </section>
            </div>
            <div className="col">
              <section
                className="card h-100"
                data-id="hilos-status-history-tile"
              >
                <div className="card-body">
                  <h2 className="h6">{HILOS_STATUS_HISTORY_COPY.history}</h2>
                  <p className="fs-4 mb-2" data-id="hilos-status-history-count">
                    {overview.historyCount ?? '—'}
                  </p>
                  <p className="small mb-2">
                    {HILOS_STATUS_HISTORY_COPY.count}
                  </p>
                  <div className="hilos-stack mb-2">
                    <p className="invisible mb-0" aria-hidden="true" inert>
                      {HILOS_STATUS_HISTORY_COPY.unavailable}
                    </p>
                    {overview.historyCount === null && (
                      <p
                        className="text-body-secondary mb-0"
                        data-id="hilos-status-history-unavailable"
                      >
                        {HILOS_STATUS_HISTORY_COPY.unavailable}
                      </p>
                    )}
                  </div>
                  <StatusLink
                    router={router}
                    page={HilosPages.STATUS_HISTORY}
                    dataId="hilos-status-history-link"
                  >
                    {HILOS_STATUS_HISTORY_COPY.link}
                  </StatusLink>
                </div>
              </section>
            </div>
          </div>
          <section className="card">
            <div className="card-body">
              <h2 className="h6">{HILOS_STATUS_COPY.publishDelay}</h2>
              {overview.publishDelaySeconds === null ? (
                <p data-id="hilos-status-delay">
                  {HILOS_STATUS_COPY.delayUnavailable}
                </p>
              ) : (
                <p data-id="hilos-status-delay">
                  <HilosHideable value={overview.publishDelaySeconds}>
                    {(value) => <>{value} seconds</>}
                  </HilosHideable>
                </p>
              )}
              <StatusLink
                router={router}
                page={HilosPages.SETTINGS}
                dataId="hilos-status-settings-link"
              >
                {HILOS_STATUS_COPY.settings}
              </StatusLink>
            </div>
          </section>
        </div>
      )}
      <HilosStatusIncidentModal controller={controller} />
    </>
  )
}

/**
 * One open incident: its facts, the publication countdown or its reason, the
 * timeline and the operations its state allows.
 *
 * @param props The card and the incident window its buttons open.
 * @param props.card The incident card the core projected.
 * @param props.controller The incident window the buttons open.
 */
function IncidentCard({
  card,
  controller,
}: {
  card: HilosStatusIncidentCard
  controller: HilosStatusIncidentController
}) {
  const id = card.incident.incidentId
  const tone = CARD_TONES[card.state]

  return (
    <section
      className={`card mb-3 ${tone.card}`}
      data-id={`hilos-status-incident-${id}`}
    >
      <div
        className={`card-header d-flex flex-wrap gap-2 align-items-center ${tone.header}`}
      >
        <h2 className="h6 mb-0 text-break">{card.incident.componentName}</h2>
        <span className={`badge text-wrap ${tone.badge}`}>{card.label}</span>
      </div>
      <div className="card-body text-break">
        <dl className="row mb-2">
          <dt className="col-sm-4">Agent</dt>
          <dd className="col-sm-8">
            <code>{card.incident.agentType}</code>
          </dd>
          <dt className="col-sm-4">{HILOS_STATUS_COPY.slots}</dt>
          <dd className="col-sm-8">
            {card.incident.liveInstances ?? '—'} /{' '}
            {card.incident.expectedInstances ?? '—'}
          </dd>
          <dt className="col-sm-4">Last observed</dt>
          <dd className="col-sm-8">{card.observedAtLabel ?? '—'}</dd>
          <dt className="col-sm-4">{HILOS_STATUS_COPY.detected}</dt>
          <dd className="col-sm-8">{card.detectedAtLabel}</dd>
          {card.incident.publishedAt !== null ? (
            <>
              <dt className="col-sm-4">{HILOS_STATUS_COPY.published}</dt>
              <dd className="col-sm-8">{card.publishedAtLabel}</dd>
            </>
          ) : !card.suppressed ? (
            <>
              <dt className="col-sm-4">{HILOS_STATUS_COPY.publishAt}</dt>
              <dd className="col-sm-8">{card.publishAtLabel}</dd>
            </>
          ) : null}
        </dl>
        {card.suppressed ? (
          <p
            className="text-body-secondary"
            data-id={`hilos-status-suppressed-${id}`}
          >
            {HILOS_STATUS_COPY.suppressed}
          </p>
        ) : card.countdown ? (
          <p data-id={`hilos-status-countdown-${id}`}>
            Publication in {card.countdown}
          </p>
        ) : card.waitingForPublication ? (
          <p data-id={`hilos-status-awaiting-${id}`}>
            {HILOS_STATUS_COPY.awaitingPublication}
          </p>
        ) : null}
        {card.updates.length > 0 && (
          <ol
            className="list-unstyled mb-3"
            data-id={`hilos-status-timeline-${id}`}
          >
            {card.updates.map((update) => (
              <li key={update.id} className="border-top py-2">
                <span className="small text-body-secondary">
                  {update.atLabel} ·{' '}
                  {HILOS_STATUS_COPY.updateKinds[update.kind]} ·{' '}
                  {HILOS_STATUS_COPY[update.severity]}
                </span>
                <div className="text-break">
                  <HilosHideable value={update.text} />
                </div>
              </li>
            ))}
          </ol>
        )}
        <div className="hilos-button-row">
          {card.canCancelPublication && (
            <LoadingButton
              className="btn-outline-danger btn-sm"
              data-id={`hilos-status-cancel-publication-${id}`}
              onClick={() => controller.open('cancel-publication', id)}
            >
              {HILOS_STATUS_ACTION_COPY['cancel-publication'].title}
            </LoadingButton>
          )}
          {card.canPublishNow && (
            <LoadingButton
              className="btn-danger btn-sm"
              data-id={`hilos-status-publish-now-${id}`}
              onClick={() => controller.open('publish-now', id)}
            >
              {HILOS_STATUS_ACTION_COPY['publish-now'].confirm}
            </LoadingButton>
          )}
          {card.canAddUpdate && (
            <LoadingButton
              className="btn-outline-primary btn-sm"
              data-id={`hilos-status-add-update-${id}`}
              onClick={() => controller.open('add-update', id)}
            >
              {HILOS_STATUS_ACTION_COPY['add-update'].title}
            </LoadingButton>
          )}
        </div>
      </div>
    </section>
  )
}

/**
 * A link to another page of the admin, drawn only when the router serves it.
 *
 * @param props The router, the page key, the link's data-id and its words.
 * @param props.router The provided router, if any.
 * @param props.page The page key the link opens.
 * @param props.dataId The link's data-id.
 * @param props.children The link's words.
 */
function StatusLink({
  router,
  page,
  dataId,
  children,
}: {
  router: HilosRouter | null
  page: string
  dataId: string
  children: ReactNode
}) {
  const path = router?.resolvePath(page)
  return path ? (
    <HilosLink to={path} data-id={dataId}>
      {children}
    </HilosLink>
  ) : null
}
