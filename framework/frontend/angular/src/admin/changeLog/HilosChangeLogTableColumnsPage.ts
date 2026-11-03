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
  CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD,
  CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD,
  CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD,
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  createHilosChangeLogTableColumnsTable,
  changeLogColumnModeCard,
  formatChangeLogColumnStorage,
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

/** The current table's server-windowed column declarations. */
@Component({
  selector: 'hilos-change-log-table-columns-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosChangeLogTableShell, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-change-log-table-shell [context]="context()" [page]="page">
      <ng-template #pageBody>
        @if (viewport(); as window) {
          <section class="mb-4" data-id="hilos-change-log-table-columns">
            @for (current of [window]; track current.controller) {
              <hilos-viewport-table [controller]="current.controller">
                <ng-template [hilosTableCell]="keys.name" let-row>
                  <code class="text-break">{{ row.name }}</code>
                </ng-template>
                <ng-template [hilosTableCell]="keys.storage" let-row>
                  <span class="small">{{ storage(row) }}</span>
                </ng-template>
                <ng-template [hilosTableCell]="keys.mode" let-row>
                  @if (modeCard(row.mode); as card) {
                    <span [class]="'badge text-wrap ' + card.tone">
                      <i
                        [class]="'bi ' + card.icon + ' me-1'"
                        aria-hidden="true"
                      ></i>
                      {{ card.label }}
                    </span>
                  } @else {
                    —
                  }
                </ng-template>
              </hilos-viewport-table>
            }
            @if (hasNoise()) {
              <p class="small text-body-secondary mt-2 mb-0">
                {{ copy.noisyNote }}
              </p>
            }
          </section>
        }
      </ng-template>
    </hilos-change-log-table-shell>
  `,
})
export class HilosChangeLogTableColumnsPage {
  /** The project's connection, page scope and tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()

  protected readonly page = HilosPages.CHANGE_LOG_TABLE_COLUMNS
  protected readonly copy = CHANGE_LOG_TABLE_COPY
  protected readonly storage = formatChangeLogColumnStorage
  protected readonly modeCard = changeLogColumnModeCard
  protected readonly keys = {
    name: CHANGE_LOG_TABLE_COLUMNS_NAME_FIELD,
    storage: CHANGE_LOG_TABLE_COLUMNS_STORAGE_FIELD,
    mode: CHANGE_LOG_TABLE_COLUMNS_MODE_FIELD,
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
  protected readonly hasNoise = computed(
    () =>
      this.answer()?.table?.columns.some(({ mode }) => mode === 'noise') ===
      true,
  )
  protected readonly viewport = computed(() => {
    const context = this.windowContext()
    const scope = this.windowScope()
    const table = this.tableName()
    if (!context || !scope || table === undefined) return null
    return createHilosChangeLogTableColumnsTable(context, { table })
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
