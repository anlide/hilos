// People in browser sessions of the selected period. The core owns the captured
// calendar context, Period filter, facets and paging; the shared Analytics head
// names both table projections. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
} from '@angular/core'
import {
  ANALYTICS_PERSON_LAST_SEEN_TS_FIELD,
  ANALYTICS_PERSON_ROUTE_PARAM,
  ANALYTICS_PERSON_ROW_KEY_FIELD,
  ANALYTICS_PERSON_SESSION_COUNT_FIELD,
  createHilosAnalyticsPeopleTable,
  formatAnalyticsPeopleTime,
  formatAnalyticsPersonName,
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

/** People whose browser sessions touch the selected calendar period. */
@Component({
  selector: 'hilos-analytics-people-page',
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
      @for (current of [people()]; track current.controller) {
        <hilos-viewport-table
          [controller]="current.controller"
          dataId="hilos-analytics-people-table"
        >
          <ng-template [hilosTableCell]="keys.rowKey" let-row>
            <span
              class="text-break"
              data-id="hilos-analytics-person-name"
              [attr.data-record]="row.rowKey"
            >
              @if (personPath(row.rowKey); as path) {
                <a
                  [hilosLink]="path"
                  data-id="hilos-analytics-person-link"
                  [attr.data-record]="row.rowKey"
                  >{{ personName(row) }}</a
                >
              } @else {
                <span
                  data-id="hilos-analytics-person-link"
                  [attr.data-record]="row.rowKey"
                  >{{ personName(row) }}</span
                >
              }
              @if (isHidden(row.name)) {
                <hilos-hideable [value]="row.name" class="ms-1" />
              }
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="keys.sessionCount" let-row>
            <span
              data-id="hilos-analytics-person-sessions"
              [attr.data-record]="row.rowKey"
            >
              <hilos-hideable [value]="row.sessionCount" />
            </span>
          </ng-template>
          <ng-template [hilosTableCell]="keys.lastSeenTs" let-row>
            <span
              data-id="hilos-analytics-person-last-seen"
              [attr.data-record]="row.rowKey"
            >
              <hilos-hideable [value]="row.lastSeenTs"
                ><ng-template let-value>{{
                  time(value, current.periodContext)
                }}</ng-template></hilos-hideable
              >
            </span>
          </ng-template>
        </hilos-viewport-table>
      }
    </hilos-admin-page>
  `,
})
export class HilosAnalyticsPeoplePage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosAnalyticsContext>()

  protected readonly page = HilosPages.ANALYTICS_PEOPLE
  protected readonly personName = formatAnalyticsPersonName
  protected readonly isHidden = isHiddenValue
  protected readonly time = formatAnalyticsPeopleTime
  protected readonly keys = {
    rowKey: ANALYTICS_PERSON_ROW_KEY_FIELD,
    sessionCount: ANALYTICS_PERSON_SESSION_COUNT_FIELD,
    lastSeenTs: ANALYTICS_PERSON_LAST_SEEN_TS_FIELD,
  } as const

  private readonly router = inject(HILOS_ROUTER)
  protected readonly people = computed(() =>
    createHilosAnalyticsPeopleTable(this.context(), { page: this.page }),
  )

  constructor() {
    effect((onCleanup) => {
      const people = this.people()
      people.start()
      onCleanup(() => people.dispose())
    })
  }

  /**
   * The person's existing Analytics address, when the project serves it.
   *
   * @param rowKey The public person number.
   */
  protected personPath(rowKey: number): string | undefined {
    return this.router.resolvePath(HilosPages.ANALYTICS_PERSON, {
      [ANALYTICS_PERSON_ROUTE_PARAM]: String(rowKey),
    })
  }
}
