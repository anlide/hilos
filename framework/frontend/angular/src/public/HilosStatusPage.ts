// Public facts come from the core file reader; the page owns only presentation.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  formatMoment,
  hilosTimeZone,
  type HilosConnection,
  type ActionLifecycle,
} from '@hilos/core/base'
import {
  createPublicStatus,
  createStatusSubscription,
  publicStatusDayLabel,
  STATUS_SUBSCRIPTION_COPY,
  type PublicStatus,
  type PublicStatusDay,
  type PublicStatusDayStrip,
  type PublicStatusState,
  type StatusSubscription,
} from '@hilos/core/status'
import {
  HilosFormError,
  HilosStaticPage,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'
import { HilosStatusSubscriptionModal } from './HilosStatusSubscriptionModal.js'

let publicStatusSequence = 0

/** Read public Status files before welcome, without a server page subscription. */
@Component({
  selector: 'hilos-public-status-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, HilosStaticPage, HilosStatusSubscriptionModal],
  template: `
    <hilos-static-page title="System status" icon="bi-activity">
      <div class="hilos-stack mb-3">
        <div
          class="invisible d-flex justify-content-end"
          aria-hidden="true"
          inert
        >
          <span class="btn btn-sm btn-outline-primary"
            ><i class="bi bi-envelope me-1" aria-hidden="true"></i
            >{{ copy.subscribe }}</span
          >
        </div>
        @if (subscriptionUsable()) {
          <div class="d-flex justify-content-end">
            <button
              type="button"
              class="btn btn-sm btn-outline-primary"
              data-id="public-status-subscribe"
              (click)="subscription().open()"
            >
              <i class="bi bi-envelope me-1" aria-hidden="true"></i
              >{{ copy.subscribe }}
            </button>
          </div>
        }
      </div>
      <div
        class="hilos-stack small mb-3"
        data-id="public-status-subscription-slot"
      >
        <div
          class="alert alert-secondary mb-0 invisible"
          aria-hidden="true"
          inert
        >
          <i class="bi bi-check-circle me-1" aria-hidden="true"></i
          >{{ copy.subscribed }}
        </div>
        @if (
          !subscriptionDialog() &&
          (subscriptionSuccess() || subscriptionProgress())
        ) {
          <div
            class="alert mb-0"
            [class]="subscriptionNoticeClass()"
            data-id="public-status-subscription-notice"
          >
            @if (subscriptionNoticeIcon(); as icon) {
              <i class="bi me-1" [class]="icon" aria-hidden="true"></i>
            }
            {{ subscriptionSuccess() || subscriptionProgress() }}
          </div>
        }
        <hilos-form-error
          [message]="subscriptionDialog() ? null : subscriptionError()"
          dataId="public-status-subscription-error"
          [announce]="true"
        />
      </div>
      <div
        class="visually-hidden"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-id="public-status-subscription-live"
      >
        {{
          subscriptionDialog()
            ? ''
            : subscriptionSuccess() || subscriptionProgress()
        }}
      </div>
      <div
        class="visually-hidden"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-id="public-status-live"
      >
        {{ banner().title }} {{ connectionMessage() }}
      </div>
      <div class="hilos-stack mb-3 small">
        <div class="invisible border rounded p-2" aria-hidden="true" inert>
          Your live connection to the site is lost — the system is up
        </div>
        @if (connectionMessage(); as message) {
          <div
            class="border rounded p-2 bg-warning-subtle text-warning-emphasis"
            data-id="public-status-connection"
          >
            {{ message }}
          </div>
        }
      </div>
      <div
        class="border rounded p-3 mb-3"
        [class]="
          'bg-' +
          states[banner().kind].tone +
          '-subtle text-' +
          states[banner().kind].tone +
          '-emphasis'
        "
        data-id="public-status-banner"
      >
        <h2 class="h5 mb-0">
          <i
            [class]="'bi bi-' + states[banner().kind].icon + ' me-2'"
            aria-hidden="true"
          ></i
          >{{ banner().title }}
        </h2>
        @if (freshness().kind === 'unknown') {
          <p class="small mt-2 mb-0">
            There is no trustworthy status picture yet. This page keeps
            checking.
          </p>
        }
      </div>
      @if (candidates().length) {
        <section class="card mb-3" data-id="public-status-candidates">
          <div class="card-header"><h2 class="h6 mb-0">Checking</h2></div>
          <ul class="list-group list-group-flush">
            @for (candidate of candidates(); track candidate.componentKey) {
              <li class="list-group-item">
                <h3 class="h6">
                  Checking a possible problem with
                  {{ componentName(candidate.componentKey) }}
                </h3>
                <p class="small mb-0">
                  Detected at {{ moment(candidate.detectedAt) }}. If confirmed,
                  it will appear here as an incident.
                </p>
              </li>
            }
          </ul>
        </section>
      }
      @if (currentOutages().length) {
        <section class="mb-3" data-id="public-status-outages">
          <h2 class="h6">Current incidents</h2>
          @for (outage of currentOutages(); track outage.id) {
            <article class="card mb-3">
              <div
                class="card-header d-flex flex-wrap align-items-center gap-2"
              >
                <h3 class="h6 mb-0 text-break">
                  {{ componentName(outage.componentKey) }}
                </h3>
                <span
                  class="badge"
                  [class]="'text-bg-' + states[outage.severity].tone"
                  >{{ states[outage.severity].label }}</span
                >
                <span class="small"
                  >Since {{ outage.detectedAtLabel }} ·
                  {{ outage.durationLabel }}</span
                >
              </div>
              @if (outage.updates.length) {
                <ul class="list-group list-group-flush small">
                  @for (update of outage.updates; track $index) {
                    <li
                      class="list-group-item text-break"
                      data-id="public-status-update"
                    >
                      <span class="text-body-secondary">{{
                        moment(update.at)
                      }}</span>
                      <p class="mb-1">{{ update.text }}</p>
                      <span
                        class="badge bg-body-secondary text-body border fw-normal"
                        >{{
                          update.source === 'team'
                            ? 'From the team'
                            : 'Automatic'
                        }}</span
                      >
                    </li>
                  }
                </ul>
              }
            </article>
          }
        </section>
      }
      @if (upcomingWorks().length) {
        <section class="mb-3" data-id="public-status-works">
          <h2 class="h6">Scheduled maintenance</h2>
          @for (work of upcomingWorks(); track work.id) {
            <article class="card border-info mb-3 text-break">
              <div class="card-header bg-info-subtle d-flex flex-wrap gap-2">
                <h3 class="h6 mb-0">{{ work.title }}</h3>
                <span class="small ms-auto"
                  >{{ work.startsAtLabel ?? '—' }} –
                  {{ work.endsAtLabel ?? '—' }}</span
                >
              </div>
              <div class="card-body small">
                @if (work.description) {
                  <p class="mb-1">{{ work.description }}</p>
                }
                <p class="mb-0">
                  Affects: {{ work.componentNames.join(', ') }}
                </p>
              </div>
            </article>
          }
        </section>
      }
      @if (currentMaintenance().length) {
        <section class="mb-3" data-id="public-status-maintenance">
          <h2 class="h6">Maintenance</h2>
          @for (period of currentMaintenance(); track period.id) {
            <article class="card border-info mb-3 text-break">
              <div class="card-header bg-info-subtle d-flex flex-wrap gap-2">
                <h3 class="h6 mb-0">{{ period.title }}</h3>
                <span class="badge text-bg-info text-wrap">{{
                  period.confirmedCurrent
                    ? 'Maintenance in progress'
                    : 'Last observed maintenance'
                }}</span>
              </div>
              <div class="card-body small">
                <div class="hilos-stack mb-2">
                  <p class="invisible mb-0" aria-hidden="true" inert>
                    This is a retained observation. Current maintenance status
                    is unknown.
                  </p>
                  @if (!period.confirmedCurrent) {
                    <p
                      class="mb-0"
                      data-id="public-status-maintenance-retained"
                    >
                      This is a retained observation. Current maintenance status
                      is unknown.
                    </p>
                  }
                </div>
                <dl class="row mb-0">
                  <dt class="col-sm-4">Advance announcement</dt>
                  <dd class="col-sm-8">
                    {{ announcementLabels[period.announcement] }}
                  </dd>
                  @if (period.plannedWork; as planned) {
                    <dt class="col-sm-4">Planned window</dt>
                    <dd class="col-sm-8">
                      {{ planned.startsAtLabel ?? '—' }} –
                      {{ planned.endsAtLabel ?? '—' }}
                    </dd>
                  }
                  <dt class="col-sm-4">Started</dt>
                  <dd class="col-sm-8">{{ period.startedAtLabel ?? '—' }}</dd>
                  <dt class="col-sm-4">Affects</dt>
                  <dd class="col-sm-8">
                    {{ period.componentNames.join(', ') }}
                  </dd>
                </dl>
                @if (period.plannedWork) {
                  <p class="mb-0">
                    The scheduled window does not determine the actual end.
                  </p>
                }
              </div>
            </article>
          }
        </section>
      }
      @if (components().length) {
        <section class="card mb-3" data-id="public-status-components">
          <div class="card-header"><h2 class="h6 mb-0">Components</h2></div>
          @for (
            component of components();
            track component.componentKey;
            let componentIndex = $index
          ) {
            <div class="card-body border-top">
              <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                <div class="flex-grow-1 text-break">
                  <h3 class="h6 mb-1">{{ component.name }}</h3>
                  @if (component.description) {
                    <p class="small mb-0">{{ component.description }}</p>
                  }
                </div>
                <span
                  class="badge"
                  [class]="'text-bg-' + states[component.state].tone"
                  data-id="public-status-component-state"
                  >{{ states[component.state].label }}</span
                >
              </div>
              @for (
                strip of stripsFor(component.componentKey);
                track strip.componentKey
              ) {
                @for (count of dayCounts; track count) {
                  <div
                    [class]="count === 90 ? 'd-none d-md-block' : 'd-md-none'"
                    [attr.data-id]="'public-status-days-' + count"
                  >
                    <div
                      class="d-flex gap-1"
                      role="group"
                      [attr.aria-label]="
                        component.name + ': last ' + count + ' days'
                      "
                    >
                      @for (
                        day of count === 90 ? strip.days : strip.narrowDays;
                        track day.startTs
                      ) {
                        <button
                          type="button"
                          class="btn flex-fill p-0 py-2 rounded-1 position-relative overflow-hidden"
                          [class]="
                            day.notMonitored
                              ? 'btn-outline-secondary bg-transparent'
                              : 'btn-' +
                                (day.hasNoData
                                  ? 'secondary'
                                  : states[day.state].tone)
                          "
                          [attr.aria-label]="dayLabel(day)"
                          [attr.aria-describedby]="
                            detailId + '-' + componentIndex
                          "
                          (focus)="selectDay(component.componentKey, day)"
                          (click)="selectDay(component.componentKey, day)"
                        >
                          @if (day.hasNoData && day.state !== 'operational') {
                            <span
                              class="position-absolute bottom-0 start-0 w-100 py-1 rounded-bottom-1"
                              [class]="'text-bg-' + states[day.state].tone"
                              aria-hidden="true"
                            ></span>
                          }
                        </button>
                      }
                    </div>
                    <div
                      class="d-flex justify-content-between small text-body-secondary mt-1"
                    >
                      <span>{{ count }} days</span><span>Today</span>
                    </div>
                  </div>
                }
                <p
                  [id]="detailId + '-' + componentIndex"
                  class="small mb-0 mt-1"
                  data-id="public-status-day-detail"
                >
                  {{ dayDetail(strip) }}
                </p>
              }
            </div>
          }
        </section>
      }
      @if (projection()) {
        <section class="card mb-3" data-id="public-status-history">
          <div class="card-header"><h2 class="h6 mb-0">Past incidents</h2></div>
          @if (!history().length) {
            <p class="card-body small mb-0">No past published incidents</p>
          } @else {
            <ul class="list-group list-group-flush small">
              @for (entry of history(); track entry.kind + '-' + entry.id) {
                <li class="list-group-item text-break">
                  @if (entry.kind === 'outage') {
                    <h3 class="h6">
                      {{ componentName(entry.componentKey) }}
                      <span
                        class="badge"
                        [class]="'text-bg-' + states[entry.severity].tone"
                        >{{ states[entry.severity].label }}</span
                      >
                    </h3>
                    <p class="mb-1">
                      {{ entry.detectedAtLabel }} · {{ entry.durationLabel }}
                    </p>
                    <p class="mb-1">Resolved at {{ entry.resolvedAtLabel }}</p>
                    @for (update of entry.updates; track $index) {
                      <p class="mb-1">
                        {{ moment(update.at) }} · {{ update.text }}
                      </p>
                    }
                  } @else {
                    <h3 class="h6">
                      {{ entry.title }}
                      <span class="badge text-bg-info">Maintenance</span>
                      <span class="badge bg-body-secondary text-body"
                        >Completed</span
                      >
                    </h3>
                    <p class="mb-1">
                      {{ announcementLabels[entry.announcement] }}
                    </p>
                    <p class="mb-1">
                      Affects: {{ entry.componentNames.join(', ') }}
                    </p>
                    <p class="mb-1">
                      {{ entry.startedAtLabel }}
                      @if (entry.durationLabel !== null) {
                        <span> · {{ entry.durationLabel }}</span>
                      }
                    </p>
                    @if (entry.endedAt !== null) {
                      <p class="mb-0">Completed at {{ entry.endedAtLabel }}</p>
                    } @else {
                      <p class="mb-0">
                        Completion observed at
                        {{ entry.completionObservedAtLabel }}. Exact end
                        unknown.
                      </p>
                    }
                  }
                </li>
              }
            </ul>
          }
        </section>
      }
      <p class="small text-body-secondary" data-id="public-status-last-update">
        Last update from the system: {{ lastUpdate() ?? 'Unknown' }}
      </p>
      <div class="hilos-stack small text-body-secondary">
        <p class="invisible mb-0" aria-hidden="true" inert>
          Subscribed already? Every letter has a link to unsubscribe.
        </p>
        @if (subscriptionUsable()) {
          <p class="mb-0" data-id="public-status-subscription-hint">
            Subscribed already? Every letter has a link to unsubscribe.
          </p>
        }
      </div>
      <hilos-status-subscription-modal [controller]="subscription()" />
    </hilos-static-page>
  `,
})
export class HilosStatusPage {
  /** The project's connection singleton; no session answer is required. */
  readonly connection = input.required<HilosConnection>()
  /** The project's existing lifecycle for correlated public actions. */
  readonly actions = input.required<ActionLifecycle>()

  protected readonly reader = computed(() =>
    createPublicStatus(this.connection()),
  )
  protected readonly subscription = computed(() =>
    createStatusSubscription({
      connection: this.connection(),
      actions: this.actions(),
    }),
  )
  protected readonly copy = STATUS_SUBSCRIPTION_COPY
  protected readonly projection =
    signal<ReturnType<PublicStatus['projection']['get']>>(null)
  protected readonly freshness = signal<
    ReturnType<PublicStatus['freshness']['get']>
  >({ kind: 'unknown', aliveAt: null, since: null })
  protected readonly banner = signal<ReturnType<PublicStatus['banner']['get']>>(
    { kind: 'unknown', title: 'Status unknown', since: null },
  )
  protected readonly connectionMessage = signal<string | null>(null)
  protected readonly components = signal<
    ReturnType<PublicStatus['components']['get']>
  >([])
  protected readonly currentOutages = signal<
    ReturnType<PublicStatus['currentOutages']['get']>
  >([])
  protected readonly history = signal<
    ReturnType<PublicStatus['history']['get']>
  >([])
  protected readonly upcomingWorks = signal<
    ReturnType<PublicStatus['upcomingWorks']['get']>
  >([])
  protected readonly currentMaintenance = signal<
    ReturnType<PublicStatus['currentMaintenance']['get']>
  >([])
  protected readonly dayStrips = signal<readonly PublicStatusDayStrip[]>([])
  protected readonly subscriptionUsable = signal(false)
  protected readonly subscriptionOperation =
    signal<ReturnType<StatusSubscription['operation']['get']>>(null)
  protected readonly subscriptionDialog = signal(false)
  protected readonly subscriptionStep =
    signal<ReturnType<StatusSubscription['step']['get']>>('idle')
  protected readonly subscriptionError = signal<string | null>(null)
  protected readonly subscriptionSuccess = signal<string | null>(null)
  protected readonly subscriptionProgress = computed(() =>
    this.subscriptionStep() === 'waiting'
      ? this.copy.waiting
      : this.subscriptionStep() !== 'pending'
        ? ''
        : this.subscriptionOperation() === 'confirm'
          ? 'Confirming your subscription…'
          : 'Waiting for confirmation…',
  )
  protected readonly subscriptionNoticeClass = computed(() =>
    this.subscriptionSuccess() !== null &&
    this.subscriptionOperation() === 'confirm'
      ? 'alert-success'
      : 'alert-secondary',
  )
  protected readonly subscriptionNoticeIcon = computed(() =>
    this.subscriptionSuccess() === null
      ? null
      : this.subscriptionOperation() === 'subscribe'
        ? 'bi-envelope'
        : this.subscriptionOperation() === 'confirm'
          ? 'bi-check-circle'
          : null,
  )
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly detailId = `public-status-day-${publicStatusSequence++}`
  protected readonly dayCounts = [90, 30] as const
  private readonly selectedDay = signal<{
    componentKey: string
    startTs: number
  } | null>(null)
  protected readonly states: Record<
    PublicStatusState | 'not_responding',
    { label: string; tone: string; icon: string }
  > = {
    operational: {
      label: 'Operational',
      tone: 'success',
      icon: 'check-circle',
    },
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
  protected readonly candidates = computed(() =>
    this.freshness().kind === 'fresh'
      ? (this.projection()?.candidates ?? [])
      : [],
  )
  protected readonly announcementLabels = {
    announced: 'Announced in advance',
    unannounced: 'Not announced in advance',
    unknown: 'Advance announcement unknown',
  }
  protected readonly lastUpdate = computed(() => {
    const at = this.freshness().aliveAt
    return at === null ? null : this.moment(at)
  })

  constructor() {
    effect((onCleanup) => {
      const reader = this.reader()
      const stops = [
        mirrorHilosSignal(reader.projection, this.projection),
        mirrorHilosSignal(reader.freshness, this.freshness),
        mirrorHilosSignal(reader.banner, this.banner),
        mirrorHilosSignal(reader.connectionMessage, this.connectionMessage),
        mirrorHilosSignal(reader.components, this.components),
        mirrorHilosSignal(reader.currentOutages, this.currentOutages),
        mirrorHilosSignal(reader.history, this.history),
        mirrorHilosSignal(reader.upcomingWorks, this.upcomingWorks),
        mirrorHilosSignal(reader.currentMaintenance, this.currentMaintenance),
        mirrorHilosSignal(reader.dayStrips, this.dayStrips),
      ]
      reader.start()
      onCleanup(() => {
        for (const stop of stops) stop()
        reader.dispose()
      })
    })
    effect((onCleanup) => {
      const subscription = this.subscription()
      const stops = [
        mirrorHilosSignal(subscription.usable, this.subscriptionUsable),
        mirrorHilosSignal(subscription.operation, this.subscriptionOperation),
        mirrorHilosSignal(subscription.dialog, this.subscriptionDialog),
        mirrorHilosSignal(subscription.step, this.subscriptionStep),
        mirrorHilosSignal(subscription.error, this.subscriptionError),
        mirrorHilosSignal(subscription.success, this.subscriptionSuccess),
      ]
      subscription.start()
      onCleanup(() => {
        for (const stop of stops) stop()
        subscription.dispose()
      })
    })
  }

  /** Format in the live reader zone, without rereading files. */
  protected moment(at: number): string {
    this.timeZone()
    return formatMoment(at) ?? '—'
  }
  /** Give each calendar button a textual date and verdict. */
  protected dayLabel(day: PublicStatusDay): string {
    this.timeZone()
    return publicStatusDayLabel(day)
  }
  /** Keep the last day selected by either keyboard focus or activation. */
  protected selectDay(componentKey: string, day: PublicStatusDay): void {
    this.selectedDay.set({ componentKey, startTs: day.startTs })
  }
  /** Show one permanent detail line for the selected day or today's default. */
  protected dayDetail(strip: PublicStatusDayStrip): string {
    const selected = this.selectedDay()
    const day =
      (selected?.componentKey === strip.componentKey
        ? strip.days.find((item) => item.startTs === selected.startTs)
        : undefined) ?? strip.days.at(-1)
    return day === undefined ? '' : this.dayLabel(day)
  }
  /** Select only the core's existing strip for this component. */
  protected stripsFor(key: string): readonly PublicStatusDayStrip[] {
    return this.dayStrips().filter((strip) => strip.componentKey === key)
  }
  /** Resolve the published name, retaining a known key when its name is absent. */
  protected componentName(key: string): string {
    return (
      this.components().find((component) => component.componentKey === key)
        ?.name ?? key
    )
  }
}
