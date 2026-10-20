// HilosAnalyticsPersonPage — one person's page in the Analytics section
// (HilosPages.ANALYTICS_PERSON): the browser sessions in which they signed in
// last. The admin shell draws the one heading (the person's name and number from
// the page's answer), the lead, and the table's accessible name; the table is the
// core's browser-sessions viewport, preset to the person in the address and drawn
// once per subscription, without live updates. Each session number opens that
// session's page (HIL-1419). A viewer of the admin view mode sees Hidden in place
// of every value. The core owns the words and the table, so a project mounts this
// view by passing its HilosAnalyticsContext. A new person in the address builds a
// new controller, and the single-pass @for tracked by that number gives it a new
// table — what Vue writes as a key. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
} from '@angular/core'
import {
  ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD,
  ANALYTICS_BROWSER_SESSION_BROWSER_FIELD,
  ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  ANALYTICS_PERSON_ROUTE_PARAM,
  createHilosAnalyticsBrowserSessionsTable,
  formatAnalyticsSessionNumber,
  formatAnalyticsTime,
  readAnalyticsRouteId,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { computedSignal, HilosPages } from '@hilos/core/base'

import {
  HilosAdminPage,
  HilosHideable,
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
  hilosSignal,
} from '@hilos/angular/base'

/** The person page of the Analytics section: the browser sessions in which the person in the address signed in last. */
@Component({
  selector: 'hilos-analytics-person-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosHideable,
    HilosLink,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-admin-page [page]="page">
      @if (sessions(); as table) {
        @for (current of [table]; track userId()) {
          <hilos-viewport-table
            [controller]="current.controller"
            dataId="hilos-analytics-browser-sessions-table"
          >
            <ng-template hilosTableCell="rowKey" let-row>
              @if (sessionPath(row.rowKey); as path) {
                <a
                  [hilosLink]="path"
                  data-id="hilos-analytics-session-link"
                  [attr.data-record]="row.rowKey"
                  >{{ sessionNumber(row.rowKey) }}</a
                >
              } @else {
                <span
                  data-id="hilos-analytics-session-link"
                  [attr.data-record]="row.rowKey"
                  >{{ sessionNumber(row.rowKey) }}</span
                >
              }
            </ng-template>
            <ng-template [hilosTableCell]="keys.firstSeenTs" let-row>
              <span
                data-id="hilos-analytics-session-first-seen"
                [attr.data-record]="row.rowKey"
              >
                <hilos-hideable [value]="row.firstSeenTs"
                  ><ng-template let-value>{{
                    time(value)
                  }}</ng-template></hilos-hideable
                >
              </span>
            </ng-template>
            <ng-template [hilosTableCell]="keys.lastSeenTs" let-row>
              <span
                data-id="hilos-analytics-session-last-seen"
                [attr.data-record]="row.rowKey"
              >
                <hilos-hideable [value]="row.lastSeenTs"
                  ><ng-template let-value>{{
                    time(value)
                  }}</ng-template></hilos-hideable
                >
              </span>
            </ng-template>
            <ng-template [hilosTableCell]="keys.browser" let-row>
              <span
                data-id="hilos-analytics-session-browser"
                [attr.data-record]="row.rowKey"
                class="text-break"
              >
                <hilos-hideable [value]="row.browser"
                  ><ng-template let-value>{{
                    value ?? noValue
                  }}</ng-template></hilos-hideable
                >
              </span>
            </ng-template>
            <ng-template [hilosTableCell]="keys.actionCount" let-row>
              <span
                data-id="hilos-analytics-session-actions"
                [attr.data-record]="row.rowKey"
              >
                <hilos-hideable [value]="row.actionCount" />
              </span>
            </ng-template>
          </hilos-viewport-table>
        }
      }
    </hilos-admin-page>
  `,
})
export class HilosAnalyticsPersonPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosAnalyticsContext>()

  protected readonly page = HilosPages.ANALYTICS_PERSON
  protected readonly noValue = ANALYTICS_NO_VALUE
  protected readonly sessionNumber = formatAnalyticsSessionNumber
  protected readonly time = formatAnalyticsTime
  protected readonly keys = {
    firstSeenTs: ANALYTICS_BROWSER_SESSION_FIRST_SEEN_TS_FIELD,
    lastSeenTs: ANALYTICS_BROWSER_SESSION_LAST_SEEN_TS_FIELD,
    browser: ANALYTICS_BROWSER_SESSION_BROWSER_FIELD,
    actionCount: ANALYTICS_BROWSER_SESSION_ACTION_COUNT_FIELD,
  } as const

  private readonly router = inject(HILOS_ROUTER)
  // The person the address names now — read live: another number on the same
  // page is a new subject.
  protected readonly userId = hilosSignal(
    computedSignal(() =>
      readAnalyticsRouteId(
        this.router.currentRoute.get().params[ANALYTICS_PERSON_ROUTE_PARAM],
      ),
    ),
  )
  protected readonly sessions = computed(() => {
    const userId = this.userId()

    return userId === null
      ? null
      : createHilosAnalyticsBrowserSessionsTable(this.context(), {
          page: HilosPages.ANALYTICS_PERSON,
          userId,
        })
  })

  constructor() {
    // Bind the person's table after the change that published its route, once
    // the router has dropped the previous page's buffered answer, and release it
    // when the number changes, disappears, or the page is destroyed.
    effect((onCleanup) => {
      const sessions = this.sessions()
      if (sessions === null) {
        return
      }
      sessions.start()
      onCleanup(() => sessions.dispose())
    })
  }

  /**
   * A session's page, or nothing when the application has no address for it.
   *
   * @param rowKey The session's number.
   */
  protected sessionPath(rowKey: number): string | undefined {
    return this.router.resolvePath(HilosPages.ANALYTICS_BROWSER_SESSION, {
      [ANALYTICS_BROWSER_SESSION_ROUTE_PARAM]: String(rowKey),
    })
  }
}
