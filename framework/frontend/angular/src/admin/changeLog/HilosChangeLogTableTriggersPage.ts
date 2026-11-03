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
  CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_OWNER_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_WRITES_TO_FIELD,
  CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD,
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  createHilosChangeLogTableTriggersTable,
  formatChangeLogTrigger,
  readHilosChangeLogTable,
  type HilosChangeLogReceiptContext,
  type HilosChangeLogTableDetail,
} from '@hilos/core/admin/change-log'
import { computedSignal, HilosPages, type Scope } from '@hilos/core/base'

import {
  HilosTableCell,
  HilosViewportTable,
  HILOS_ROUTER,
  mirrorHilosSignal,
} from '@hilos/angular/base'

import { HilosChangeLogTableShell } from './HilosChangeLogTableShell.js'

/** The current table's server-windowed service trigger interfaces. */
@Component({
  selector: 'hilos-change-log-table-triggers-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTableShell, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-change-log-table-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        @if (viewport(); as window) {
          <section class="mb-4" data-id="hilos-change-log-table-triggers">
            <p class="small text-body-secondary mb-2">
              {{ copy.triggersLead }}
            </p>
            @for (current of [window]; track current.controller) {
              <hilos-viewport-table [controller]="current.controller">
                <ng-template [hilosTableCell]="keys.name" let-row>
                  <code class="text-break">{{ row.name }}</code>
                </ng-template>
                <ng-template [hilosTableCell]="keys.owner">
                  <span class="badge bg-body-secondary text-body border">{{
                    copy.service
                  }}</span>
                </ng-template>
                <ng-template [hilosTableCell]="keys.event" let-row>
                  <span class="small text-nowrap">{{
                    describeTrigger(row).fires
                  }}</span>
                </ng-template>
                <ng-template [hilosTableCell]="keys.writesTo" let-row>
                  <span class="small">{{ describeTrigger(row).writesTo }}</span>
                </ng-template>
                <ng-template [hilosTableCell]="keys.migration" let-row>
                  <span class="small text-nowrap">{{
                    describeTrigger(row).validFrom
                  }}</span>
                </ng-template>
              </hilos-viewport-table>
            }
            <p class="small text-body-secondary mt-2 mb-0">
              {{ copy.triggersFootnote }}
            </p>
          </section>
        }
      </ng-template>
    </hilos-change-log-table-shell>
  `,
})
export class HilosChangeLogTableTriggersPage {
  /** The project's connection, page scope and tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()

  protected readonly page = HilosPages.CHANGE_LOG_TABLE_TRIGGERS
  protected readonly copy = CHANGE_LOG_TABLE_COPY
  protected readonly describeTrigger = formatChangeLogTrigger
  protected readonly keys = {
    name: CHANGE_LOG_TABLE_TRIGGERS_NAME_FIELD,
    owner: CHANGE_LOG_TABLE_TRIGGERS_OWNER_FIELD,
    event: CHANGE_LOG_TABLE_TRIGGERS_EVENT_FIELD,
    writesTo: CHANGE_LOG_TABLE_TRIGGERS_WRITES_TO_FIELD,
    migration: CHANGE_LOG_TABLE_TRIGGERS_MIGRATION_FIELD,
  } as const
  private readonly router = inject(HILOS_ROUTER)
  private readonly answer = signal<{
    context: HilosChangeLogReceiptContext
    scope: Scope | undefined
    table: HilosChangeLogTableDetail | null
  } | null>(null)
  private readonly windowContext = computed(() => this.answer()?.context)
  private readonly windowScope = computed(() => this.answer()?.scope)
  private readonly tableName = computed(() => this.answer()?.table?.name)
  protected readonly viewport = computed(() => {
    const context = this.windowContext()
    const scope = this.windowScope()
    const table = this.tableName()
    if (!context || !scope || table === undefined) return null
    return createHilosChangeLogTableTriggersTable(context, { table })
  })

  constructor() {
    effect((onCleanup) => {
      const context = this.context()
      const section = context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION)
      const current = computedSignal(() => {
        const scope = context.scopes.page()
        const route = this.router.currentRoute.get()
        const detail = readHilosChangeLogTable(section.get())
        const table =
          scope?.key === this.page &&
          route.page === this.page &&
          this.router.pageError.get() === null &&
          detail?.name === route.params[CHANGE_LOG_TABLE_ROUTE_PARAM]
            ? detail
            : null
        return { context, scope, table }
      })
      const stop = mirrorHilosSignal(current, this.answer)
      onCleanup(() => {
        stop()
        this.answer.set(null)
      })
    })
    // Bind after navigation has published its route and discarded the old
    // buffered answer. A new scope is a new window even at the same address.
    effect((onCleanup) => {
      const viewport = this.viewport()
      if (!viewport) return
      viewport.start()
      onCleanup(() => viewport.dispose())
    })
  }
}
