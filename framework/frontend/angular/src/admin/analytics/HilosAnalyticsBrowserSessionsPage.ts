// The core owns Period, address synchronization, facets and paging. This view
// supplies cells to the shared table under the Analytics section head.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
} from '@angular/core'
import {
  ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_PERSON_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  createHilosAnalyticsBrowserSessionListTable,
  formatAnalyticsBrowserSessionListPerson,
  formatAnalyticsBrowserSessionListTime,
  type HilosAnalyticsBrowserSessionListRow,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import { HilosPages, isHiddenValue } from '@hilos/core/base'
import {
  HilosAdminPage,
  HilosHideable,
  HilosLink,
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
} from '@hilos/angular/base'

/** Browser sessions overlapping the selected calendar period. */
@Component({
  selector: 'hilos-analytics-browser-sessions-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosHideable,
    HilosLink,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-admin-page
      [page]="page"
      headKind="section"
      tabsLabel="Analytics pages"
    >
      @for (current of [sessions()]; track current.controller) {
        <hilos-viewport-table
          [controller]="current.controller"
          dataId="hilos-analytics-browser-session-list-table"
        >
          <ng-template [hilosTableCell]="keys.rowKey" let-row>
            @if (sessionPath(row.rowKey); as path) {
              <a
                [hilosLink]="path"
                data-id="hilos-analytics-browser-session-list-session"
                [attr.data-record]="row.rowKey"
                >#{{ row.rowKey }}</a
              >
            } @else {
              <span
                data-id="hilos-analytics-browser-session-list-session"
                [attr.data-record]="row.rowKey"
                >#{{ row.rowKey }}</span
              >
            }
          </ng-template>
          <ng-template [hilosTableCell]="keys.lastSignedInUserId" let-row>
            <span
              class="text-break"
              data-id="hilos-analytics-browser-session-list-person"
              [attr.data-record]="row.rowKey"
            >
              <hilos-hideable [value]="person(row)">
                <ng-template let-value>
                  @if (personPath(row); as path) {
                    <a [hilosLink]="path">{{ value }}</a>
                  } @else {
                    {{ value }}
                  }
                </ng-template>
              </hilos-hideable>
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="keys.browser" let-row>
            <span
              class="text-break"
              data-id="hilos-analytics-browser-session-list-browser"
              [attr.data-record]="row.rowKey"
            >
              <hilos-hideable [value]="row.browser"
                ><ng-template let-value>{{
                  value ?? noValue
                }}</ng-template></hilos-hideable
              >
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="keys.firstSeenTs" let-row>
            <span
              class="text-nowrap"
              data-id="hilos-analytics-browser-session-list-first-seen"
              [attr.data-record]="row.rowKey"
            >
              <hilos-hideable [value]="row.firstSeenTs"
                ><ng-template let-value>{{
                  time(value, current.periodContext)
                }}</ng-template></hilos-hideable
              >
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="keys.lastSeenTs" let-row>
            <span
              class="text-nowrap"
              data-id="hilos-analytics-browser-session-list-last-seen"
              [attr.data-record]="row.rowKey"
            >
              <hilos-hideable [value]="row.lastSeenTs"
                ><ng-template let-value>{{
                  time(value, current.periodContext)
                }}</ng-template></hilos-hideable
              >
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="keys.actionCount" let-row>
            <span
              data-id="hilos-analytics-browser-session-list-actions"
              [attr.data-record]="row.rowKey"
            >
              <hilos-hideable [value]="row.actionCount" />
            </span>
          </ng-template>
        </hilos-viewport-table>
      }
    </hilos-admin-page>
  `,
})
export class HilosAnalyticsBrowserSessionsPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosAnalyticsContext>()

  protected readonly page = HilosPages.ANALYTICS_BROWSER_SESSIONS
  protected readonly person = formatAnalyticsBrowserSessionListPerson
  protected readonly time = formatAnalyticsBrowserSessionListTime
  protected readonly noValue = ANALYTICS_NO_VALUE
  protected readonly keys = {
    rowKey: ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD,
    lastSignedInUserId:
      ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD,
    browser: ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD,
    firstSeenTs: ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD,
    lastSeenTs: ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD,
    actionCount: ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD,
  } as const

  private readonly router = inject(HILOS_ROUTER)
  protected readonly sessions = computed(() =>
    createHilosAnalyticsBrowserSessionListTable(this.context(), {
      currentRoute: this.router.currentRoute,
      replacePath: (path) => this.router.replacePath(path),
    }),
  )

  constructor() {
    effect((onCleanup) => {
      const sessions = this.sessions()
      sessions.start()
      onCleanup(() => sessions.dispose())
    })
  }

  /** The session's existing address, when the project serves it. */
  protected sessionPath(rowKey: number): string | undefined {
    return this.router.resolvePath(HilosPages.ANALYTICS_BROWSER_SESSION, {
      [ANALYTICS_BROWSER_SESSION_ROUTE_PARAM]: String(rowKey),
    })
  }

  /** Neither account field contributes to an address while either is hidden. */
  protected personPath(
    row: HilosAnalyticsBrowserSessionListRow,
  ): string | undefined {
    if (
      isHiddenValue(row.lastSignedInUserId) ||
      isHiddenValue(row.lastSignedInUserName) ||
      row.lastSignedInUserId === null
    )
      return undefined
    return this.router.resolvePath(HilosPages.ANALYTICS_PERSON, {
      [ANALYTICS_PERSON_ROUTE_PARAM]: String(row.lastSignedInUserId),
    })
  }
}
