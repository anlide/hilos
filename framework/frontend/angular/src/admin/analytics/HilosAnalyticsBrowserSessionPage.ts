// HilosAnalyticsBrowserSessionPage — one browser session's page in the Analytics
// section (HilosPages.ANALYTICS_BROWSER_SESSION): the session's header and the
// actions recorded in it. The admin shell draws the one heading (the session's
// number from the page's answer), the lead, and the table's accessible name; the
// header comes whole with that answer, and the table is the core's actions
// viewport, preset to the session in the address. A viewer of the admin view
// mode sees Hidden in place of the header and of every value. The core owns the
// words, the table and the header, so a project mounts this view by passing its
// HilosAnalyticsContext. A new number in the address builds a new controller,
// and the single-pass @for tracked by that number gives it a new table — what
// Vue writes as a key. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import {
  ANALYTICS_ACTION_CREATED_TS_FIELD,
  ANALYTICS_ACTION_NAME_FIELD,
  ANALYTICS_ACTION_PAGE_NAME_FIELD,
  ANALYTICS_BROWSER_SESSION_COPY,
  ANALYTICS_BROWSER_SESSION_ROUTE_PARAM,
  ANALYTICS_NO_VALUE,
  createHilosAnalyticsActionsTable,
  formatAnalyticsLastSignedIn,
  formatAnalyticsTime,
  hilosAnalyticsBrowserSession,
  readAnalyticsRouteId,
  type HilosAnalyticsBrowserSession,
  type HilosAnalyticsContext,
} from '@hilos/core/admin/analytics'
import {
  computedSignal,
  HilosPages,
  hilosTimeZone,
  type Hideable,
} from '@hilos/core/base'

import {
  HilosAdminPage,
  HilosHideable,
  HilosSkeleton,
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

/** The browser-session page of the Analytics section: the header and the session's actions. */
@Component({
  selector: 'hilos-analytics-browser-session-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosHideable,
    HilosSkeleton,
    HilosTableCell,
    HilosViewportTable,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <div
        data-id="hilos-analytics-browser-session-header"
        class="mb-4 text-break"
      >
        @if (header(); as current) {
          <hilos-hideable [value]="current"
            ><ng-template let-value>
              <p data-id="hilos-analytics-browser-session-browser" class="mb-2">
                {{ value.browser ?? noValue }}
              </p>
              <p data-id="hilos-analytics-browser-session-person" class="mb-0">
                @if (value.lastSignedInUserId !== null) {
                  <span>{{ copy.lastSignedIn }}:</span>
                }
                {{ lastSignedIn(value) }}
              </p>
            </ng-template></hilos-hideable
          >
        } @else {
          <hilos-skeleton [lines]="[6, 8]" />
        }
      </div>
      @if (actions(); as table) {
        @for (current of [table]; track browserSessionId()) {
          <hilos-viewport-table
            [controller]="current.controller"
            dataId="hilos-analytics-actions-table"
          >
            <ng-template [hilosTableCell]="keys.createdTs" let-row>
              <span
                data-id="hilos-analytics-action-when"
                [attr.data-record]="row.rowKey"
              >
                <hilos-hideable [value]="row.createdTs"
                  ><ng-template let-value
                    ><span [attr.data-created-ts]="value">{{
                      time(value)
                    }}</span></ng-template
                  ></hilos-hideable
                >
              </span>
            </ng-template>
            <ng-template [hilosTableCell]="keys.pageName" let-row>
              <span
                data-id="hilos-analytics-action-page"
                [attr.data-record]="row.rowKey"
                class="text-break"
              >
                <hilos-hideable [value]="row.pageName"
                  ><ng-template let-value>{{
                    value ?? noValue
                  }}</ng-template></hilos-hideable
                >
              </span>
            </ng-template>
            <ng-template [hilosTableCell]="keys.actionName" let-row>
              <span
                data-id="hilos-analytics-action-name"
                [attr.data-record]="row.rowKey"
                class="text-break"
              >
                <hilos-hideable [value]="row.actionName" />
              </span>
            </ng-template>
          </hilos-viewport-table>
        }
      }
    </hilos-admin-page>
  `,
})
export class HilosAnalyticsBrowserSessionPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosAnalyticsContext>()

  protected readonly page = HilosPages.ANALYTICS_BROWSER_SESSION
  protected readonly copy = ANALYTICS_BROWSER_SESSION_COPY
  protected readonly noValue = ANALYTICS_NO_VALUE
  protected readonly lastSignedIn = formatAnalyticsLastSignedIn
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly time = (moment: number): string => {
    this.timeZone()
    return formatAnalyticsTime(moment)
  }
  protected readonly keys = {
    createdTs: ANALYTICS_ACTION_CREATED_TS_FIELD,
    pageName: ANALYTICS_ACTION_PAGE_NAME_FIELD,
    actionName: ANALYTICS_ACTION_NAME_FIELD,
  } as const

  private readonly router = inject(HILOS_ROUTER)
  // The session the address names now — read live: another number on the same
  // page is a new subject.
  protected readonly browserSessionId = hilosSignal(
    computedSignal(() =>
      readAnalyticsRouteId(
        this.router.currentRoute.get().params[
          ANALYTICS_BROWSER_SESSION_ROUTE_PARAM
        ],
      ),
    ),
  )
  // Mirrored from the core header, which derives from the context input.
  protected readonly header = signal<
    Hideable<HilosAnalyticsBrowserSession> | undefined
  >(undefined)
  protected readonly actions = computed(() => {
    const browserSessionId = this.browserSessionId()

    return browserSessionId === null
      ? null
      : createHilosAnalyticsActionsTable(this.context(), {
          page: HilosPages.ANALYTICS_BROWSER_SESSION,
          browserSessionId,
        })
  })

  constructor() {
    // The header comes with the page's answer; mirror it once the context binds.
    effect((onCleanup) => {
      onCleanup(
        mirrorHilosSignal(
          hilosAnalyticsBrowserSession(this.context().scopes),
          this.header,
        ),
      )
    })
    // Bind the session's table after the change that published its route, once
    // the router has dropped the previous page's buffered answer, and release it
    // when the number changes, disappears, or the page is destroyed.
    effect((onCleanup) => {
      const actions = this.actions()
      if (actions === null) {
        return
      }
      actions.start()
      onCleanup(() => actions.dispose())
    })
  }
}
