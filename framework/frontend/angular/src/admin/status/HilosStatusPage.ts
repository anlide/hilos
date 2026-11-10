// The page renders the complete Status answer through the core's timed projections.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import { formatMoment, hilosTimeZone, HilosPages } from '@hilos/core/base'
import {
  createHilosStatusState,
  createHilosStatusIncidentController,
  HILOS_STATUS_COPY,
  HILOS_STATUS_ACTION_COPY,
  HILOS_STATUS_SUBSCRIBERS_COPY,
  HILOS_STATUS_WORKS_COPY,
  HILOS_STATUS_HISTORY_COPY,
  type HilosStatusContext,
  type HilosStatusState,
} from '@hilos/core/admin/status'
import {
  HilosAdminPage,
  HilosHideable,
  HilosLink,
  LoadingButton,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosStatusIncidentModal } from './HilosStatusIncidentModal.js'

/** The Status Overview: open facts, authoritative counts and incident operations. */
@Component({
  selector: 'hilos-status-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosHideable,
    HilosLink,
    LoadingButton,
    HilosStatusIncidentModal,
  ],
  template: `
    <hilos-admin-page [page]="page" headKind="section" tabsLabel="Status pages">
      @if (overview(); as overview) {
        <div data-id="hilos-status-overview">
          @if (summary() === 'operational') {
            <div
              class="alert alert-success d-flex flex-wrap gap-3 align-items-center"
              data-id="hilos-status-operational"
            >
              <span
                class="flex-grow-1"
                data-id="hilos-status-operational-label"
              >
                {{ copy.allOperational }}
              </span>
              <a
                href="/status"
                class="btn btn-outline-secondary btn-sm text-nowrap"
                data-id="hilos-status-public-link"
                >{{ copy.publicPage }}</a
              >
            </div>
          } @else if (summary() === 'unknown') {
            <div class="alert alert-secondary" data-id="hilos-status-unknown">
              {{ copy.unknown }}
            </div>
          } @else if (summary() === 'checking') {
            <div class="alert alert-secondary" data-id="hilos-status-checking">
              {{ copy.checking }}
            </div>
          }

          @if (maintenance(); as maintenance) {
            <section
              class="card border-info mb-3"
              data-id="hilos-status-maintenance"
            >
              <div class="card-header bg-info-subtle">
                <h2 class="h6 mb-0">Maintenance in progress</h2>
              </div>
              <div class="card-body text-break">
                <dl class="row mb-2">
                  <dt class="col-sm-4">Advance announcement</dt>
                  <dd
                    class="col-sm-8"
                    data-id="hilos-status-maintenance-announcement"
                  >
                    <div class="hilos-stack">
                      <p class="invisible mb-0" aria-hidden="true" inert>
                        Announcement details are unavailable.
                      </p>
                      @if (maintenance.announcement === null) {
                        <p class="mb-0">
                          Announcement details are unavailable.
                        </p>
                      } @else if (!maintenance.announcement.known) {
                        <p class="mb-0">Advance announcement unknown</p>
                      } @else if (maintenance.announcement.work === null) {
                        <p class="mb-0">Not announced in advance</p>
                      } @else {
                        <div>
                          <p class="mb-1">
                            {{ maintenance.announcement.work.title }}
                          </p>
                          <p class="small mb-0">
                            {{ moment(maintenance.announcement.work.startsAt) }}
                            –
                            {{ moment(maintenance.announcement.work.endsAt) }}
                          </p>
                        </div>
                      }
                    </div>
                  </dd>
                  <dt class="col-sm-4">Phase</dt>
                  <dd class="col-sm-8">{{ maintenance.phase }}</dd>
                  <dt class="col-sm-4">Operation</dt>
                  <dd class="col-sm-8">
                    <code>{{ maintenance.operation ?? '—' }}</code>
                  </dd>
                  <dt class="col-sm-4">Started</dt>
                  <dd class="col-sm-8">
                    {{ maintenance.startedAtLabel ?? '—' }}
                  </dd>
                </dl>
                @if (router?.resolvePath(pages.MAINTENANCE)) {
                  <a
                    [hilosLink]="router.resolvePath(pages.MAINTENANCE)!"
                    data-id="hilos-status-maintenance-link"
                    >View Maintenance</a
                  >
                }
              </div>
            </section>
          }
          @if (overview.incidents === null) {
            <p
              class="text-body-secondary"
              data-id="hilos-status-incidents-unavailable"
            >
              {{ copy.incidentsUnavailable }}
            </p>
          }
          @for (card of cards(); track card.incident.incidentId) {
            <section
              class="card mb-3"
              [class]="
                card.state === 'major_outage'
                  ? 'border-danger'
                  : card.state === 'partial_outage'
                    ? 'border-warning'
                    : 'border-secondary'
              "
              [attr.data-id]="
                'hilos-status-incident-' + card.incident.incidentId
              "
            >
              <div
                class="card-header d-flex flex-wrap gap-2 align-items-center"
                [class]="
                  card.state === 'major_outage'
                    ? 'bg-danger-subtle'
                    : card.state === 'partial_outage'
                      ? 'bg-warning-subtle'
                      : 'bg-secondary-subtle'
                "
              >
                <h2 class="h6 mb-0 text-break">
                  {{ card.incident.componentName }}
                </h2>
                <span
                  class="badge text-wrap"
                  [class]="
                    card.state === 'major_outage'
                      ? 'text-bg-danger'
                      : card.state === 'partial_outage'
                        ? 'text-bg-warning'
                        : 'text-bg-secondary'
                  "
                  >{{ card.label }}</span
                >
              </div>
              <div class="card-body text-break">
                <dl class="row mb-2">
                  <dt class="col-sm-4">Agent</dt>
                  <dd class="col-sm-8">
                    <code>{{ card.incident.agentType }}</code>
                  </dd>
                  <dt class="col-sm-4">{{ copy.slots }}</dt>
                  <dd class="col-sm-8">
                    {{ card.incident.liveInstances ?? '—' }} /
                    {{ card.incident.expectedInstances ?? '—' }}
                  </dd>
                  <dt class="col-sm-4">Last observed</dt>
                  <dd class="col-sm-8">{{ card.observedAtLabel ?? '—' }}</dd>
                  <dt class="col-sm-4">{{ copy.detected }}</dt>
                  <dd class="col-sm-8">{{ card.detectedAtLabel }}</dd>
                  @if (card.incident.publishedAt !== null) {
                    <dt class="col-sm-4">{{ copy.published }}</dt>
                    <dd class="col-sm-8">{{ card.publishedAtLabel }}</dd>
                  } @else if (!card.suppressed) {
                    <dt class="col-sm-4">{{ copy.publishAt }}</dt>
                    <dd class="col-sm-8">{{ card.publishAtLabel }}</dd>
                  }
                </dl>
                @if (card.suppressed) {
                  <p
                    class="text-body-secondary"
                    [attr.data-id]="
                      'hilos-status-suppressed-' + card.incident.incidentId
                    "
                  >
                    {{ copy.suppressed }}
                  </p>
                } @else if (card.countdown) {
                  <p
                    [attr.data-id]="
                      'hilos-status-countdown-' + card.incident.incidentId
                    "
                  >
                    Publication in {{ card.countdown }}
                  </p>
                } @else if (card.waitingForPublication) {
                  <p
                    [attr.data-id]="
                      'hilos-status-awaiting-' + card.incident.incidentId
                    "
                  >
                    {{ copy.awaitingPublication }}
                  </p>
                }
                @if (card.updates.length) {
                  <ol
                    class="list-unstyled mb-3"
                    [attr.data-id]="
                      'hilos-status-timeline-' + card.incident.incidentId
                    "
                  >
                    @for (update of card.updates; track update.id) {
                      <li class="border-top py-2">
                        <span class="small text-body-secondary"
                          >{{ update.atLabel }} ·
                          {{ copy.updateKinds[update.kind] }} ·
                          {{ copy[update.severity] }}</span
                        >
                        <div class="text-break">
                          <hilos-hideable [value]="update.text" />
                        </div>
                      </li>
                    }
                  </ol>
                }
                <div>
                  @if (card.canCancelPublication) {
                    <button
                      hilosLoadingButton
                      class="btn-outline-danger btn-sm"
                      [attr.data-id]="
                        'hilos-status-cancel-publication-' +
                        card.incident.incidentId
                      "
                      (click)="
                        controller().open(
                          'cancel-publication',
                          card.incident.incidentId
                        )
                      "
                    >
                      {{ actionCopy['cancel-publication'].title }}
                    </button>
                  }
                  @if (card.canPublishNow) {
                    <button
                      hilosLoadingButton
                      class="btn-danger btn-sm"
                      [attr.data-id]="
                        'hilos-status-publish-now-' + card.incident.incidentId
                      "
                      (click)="
                        controller().open(
                          'publish-now',
                          card.incident.incidentId
                        )
                      "
                    >
                      {{ actionCopy['publish-now'].confirm }}
                    </button>
                  }
                  @if (card.canAddUpdate) {
                    <button
                      hilosLoadingButton
                      class="btn-outline-primary btn-sm"
                      [attr.data-id]="
                        'hilos-status-add-update-' + card.incident.incidentId
                      "
                      (click)="
                        controller().open(
                          'add-update',
                          card.incident.incidentId
                        )
                      "
                    >
                      {{ actionCopy['add-update'].title }}
                    </button>
                  }
                </div>
              </div>
            </section>
          }
          <div class="row row-cols-2 row-cols-lg-4 g-3 mb-3">
            <div class="col">
              <section class="card h-100" data-id="hilos-status-works-tile">
                <div class="card-body text-break">
                  <h2 class="h6">Scheduled maintenance</h2>
                  <p class="fs-4 mb-2" data-id="hilos-status-works-count">
                    {{ overview.scheduledWorks?.count ?? '—' }}
                  </p>
                  @if (overview.scheduledWorks?.nextWork) {
                    <p data-id="hilos-status-works-next">
                      Next: {{ overview.scheduledWorks.nextWork.title }}<br />
                      <span class="small">
                        {{ moment(overview.scheduledWorks.nextWork.startsAt) }}
                        –
                        {{ moment(overview.scheduledWorks.nextWork.endsAt) }}
                      </span>
                    </p>
                  }
                  <div class="hilos-stack mb-2 small">
                    <p class="invisible mb-0" aria-hidden="true" inert>
                      Scheduled maintenance data is unavailable.
                    </p>
                    @if (overview.scheduledWorks === null) {
                      <p
                        class="text-body-secondary mb-0"
                        data-id="hilos-status-works-unavailable"
                      >
                        Scheduled maintenance data is unavailable.
                      </p>
                    } @else if (overview.scheduledWorks.count === 0) {
                      <p class="mb-0" data-id="hilos-status-works-empty">
                        {{ worksCopy.empty }}
                      </p>
                    }
                  </div>
                  @if (router?.resolvePath(pages.STATUS_WORKS)) {
                    <a
                      [hilosLink]="router.resolvePath(pages.STATUS_WORKS)!"
                      data-id="hilos-status-works-link"
                      >Scheduled maintenance</a
                    >
                  }
                </div>
              </section>
            </div>
            <div class="col">
              <section class="card h-100">
                <div class="card-body">
                  <h2 class="h6">{{ copy.components }}</h2>
                  @if (router?.resolvePath(pages.STATUS_COMPONENTS)) {
                    <a
                      [hilosLink]="router.resolvePath(pages.STATUS_COMPONENTS)!"
                      data-id="hilos-status-components-link"
                      >{{ overview.components.operationalCount }} /
                      {{ overview.components.totalCount }} operational ·
                      {{ copy.componentsLink }}</a
                    >
                  }
                </div>
              </section>
            </div>
            <div class="col">
              <section
                class="card h-100"
                data-id="hilos-status-subscribers-tile"
              >
                <div class="card-body">
                  <h2 class="h6">
                    {{ subscribersCopy.subscribers }}
                  </h2>
                  <dl class="mb-2">
                    <div class="d-flex gap-2">
                      <dt>{{ subscribersCopy.confirmed }}</dt>
                      <dd
                        class="mb-0"
                        data-id="hilos-status-subscribers-tile-confirmed"
                      >
                        {{ overview.subscribers?.confirmedCount ?? '—' }}
                      </dd>
                    </div>
                    <div class="d-flex gap-2">
                      <dt>
                        {{ subscribersCopy.awaitingConfirmation }}
                      </dt>
                      <dd
                        class="mb-0"
                        data-id="hilos-status-subscribers-tile-pending"
                      >
                        {{ overview.subscribers?.pendingCount ?? '—' }}
                      </dd>
                    </div>
                  </dl>
                  <div class="hilos-stack mb-2">
                    <p class="invisible mb-0" aria-hidden="true">
                      {{ subscribersCopy.unavailable }}
                    </p>
                    @if (overview.subscribers === null) {
                      <p
                        class="text-body-secondary mb-0"
                        data-id="hilos-status-subscribers-tile-unavailable"
                      >
                        {{ subscribersCopy.unavailable }}
                      </p>
                    }
                  </div>
                  @if (router?.resolvePath(pages.STATUS_SUBSCRIBERS)) {
                    <a
                      [hilosLink]="
                        router.resolvePath(pages.STATUS_SUBSCRIBERS)!
                      "
                      data-id="hilos-status-subscribers-link"
                      >{{ subscribersCopy.subscribers }}</a
                    >
                  }
                </div>
              </section>
            </div>
            <div class="col">
              <section class="card h-100" data-id="hilos-status-history-tile">
                <div class="card-body">
                  <h2 class="h6">{{ historyCopy.history }}</h2>
                  <p class="fs-4 mb-2" data-id="hilos-status-history-count">
                    {{ overview.historyCount ?? '—' }}
                  </p>
                  <p class="small mb-2">{{ historyCopy.count }}</p>
                  <div class="hilos-stack mb-2">
                    <p class="invisible mb-0" aria-hidden="true" inert>
                      {{ historyCopy.unavailable }}
                    </p>
                    @if (overview.historyCount === null) {
                      <p
                        class="text-body-secondary mb-0"
                        data-id="hilos-status-history-unavailable"
                      >
                        {{ historyCopy.unavailable }}
                      </p>
                    }
                  </div>
                  @if (router?.resolvePath(pages.STATUS_HISTORY)) {
                    <a
                      [hilosLink]="router.resolvePath(pages.STATUS_HISTORY)!"
                      data-id="hilos-status-history-link"
                      >{{ historyCopy.link }}</a
                    >
                  }
                </div>
              </section>
            </div>
          </div>
          <section class="card">
            <div class="card-body">
              <h2 class="h6">{{ copy.publishDelay }}</h2>
              @if (overview.publishDelaySeconds === null) {
                <p data-id="hilos-status-delay">
                  {{ copy.delayUnavailable }}
                </p>
              } @else {
                <p data-id="hilos-status-delay">
                  <hilos-hideable [value]="overview.publishDelaySeconds"
                    ><ng-template let-value
                      >{{ value }} seconds</ng-template
                    ></hilos-hideable
                  >
                </p>
              }
              @if (router?.resolvePath(pages.SETTINGS)) {
                <a
                  [hilosLink]="router.resolvePath(pages.SETTINGS)!"
                  data-id="hilos-status-settings-link"
                  >{{ copy.settings }}</a
                >
              }
            </div>
          </section>
        </div>
      }
      <hilos-status-incident-modal [controller]="controller()" />
    </hilos-admin-page>
  `,
})
export class HilosStatusPage {
  /** The project's existing scopes, connection and action lifecycle. */
  readonly context = input.required<HilosStatusContext>()

  protected readonly page = HilosPages.STATUS
  protected readonly pages = HilosPages
  protected readonly copy = HILOS_STATUS_COPY
  protected readonly actionCopy = HILOS_STATUS_ACTION_COPY
  protected readonly subscribersCopy = HILOS_STATUS_SUBSCRIBERS_COPY
  protected readonly worksCopy = HILOS_STATUS_WORKS_COPY
  protected readonly historyCopy = HILOS_STATUS_HISTORY_COPY
  protected readonly router = inject(HILOS_ROUTER, { optional: true })
  private readonly timeZone = hilosSignal(hilosTimeZone)
  private readonly state = computed(() =>
    createHilosStatusState(this.context()),
  )
  protected readonly controller = computed(() =>
    createHilosStatusIncidentController(this.context(), this.state()),
  )
  protected readonly overview =
    signal<ReturnType<HilosStatusState['overview']['get']>>(null)
  protected readonly cards = signal<
    ReturnType<HilosStatusState['cards']['get']>
  >([])
  protected readonly summary =
    signal<ReturnType<HilosStatusState['summary']['get']>>('loading')
  protected readonly maintenance =
    signal<ReturnType<HilosStatusState['maintenance']['get']>>(null)

  constructor() {
    effect((onCleanup) => {
      const state = this.state()
      const controller = this.controller()
      state.start()
      controller.start()
      const stops = [
        mirrorHilosSignal(state.overview, this.overview),
        mirrorHilosSignal(state.cards, this.cards),
        mirrorHilosSignal(state.summary, this.summary),
        mirrorHilosSignal(state.maintenance, this.maintenance),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
        controller.dispose()
        state.dispose()
      })
    })
  }

  /** Format planned work in the reader's current zone. */
  protected moment(at: number): string {
    this.timeZone()
    return formatMoment(at) ?? '—'
  }
}
