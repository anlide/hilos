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
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  changeLogColumnModeCard,
  formatChangeLogColumnStorage,
  readHilosChangeLogTable,
  type HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'

import {
  HilosAdminPage,
  HilosLink,
  HILOS_ROUTER,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

let tableColumnsPageSeq = 0

@Component({
  selector: 'hilos-change-log-table-columns-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink],
  template: ` <hilos-admin-page [page]="page">
    <p>
      @if (overviewPath(); as path) {
        <a [hilosLink]="path" data-id="hilos-change-log-table-overview"
          >Overview</a
        >
      }
    </p>
    @if (table(); as current) {
      <section class="mb-4" data-id="hilos-change-log-table-columns">
        <h2 [id]="columnsHeadingId" class="h5">{{ copy.columns }}</h2>
        <div class="table-responsive d-none d-md-block">
          <table
            class="table table-sm align-middle mb-0"
            [attr.aria-labelledby]="columnsHeadingId"
          >
            <thead>
              <tr>
                <th scope="col">{{ copy.column }}</th>
                <th scope="col">{{ copy.storedAs }}</th>
                <th scope="col">{{ copy.inJournal }}</th>
              </tr>
            </thead>
            <tbody>
              @for (column of current.columns; track column.name) {
                <tr>
                  <td>
                    <code class="text-break">{{ column.name }}</code>
                  </td>
                  <td class="small">{{ storage(column) }}</td>
                  <td>
                    @if (modeCard(column.mode); as card) {
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
                  </td>
                </tr>
              }
            </tbody>
          </table>
        </div>
        <div
          class="d-md-none"
          role="list"
          [attr.aria-labelledby]="columnsHeadingId"
        >
          @for (column of current.columns; track column.name) {
            <div class="card mb-2" role="listitem">
              <div class="card-body py-2 px-3">
                <div class="fw-medium mb-1">
                  <code class="text-break">{{ column.name }}</code>
                </div>
                <dl class="row mb-0 small g-0">
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.storedAs }}
                  </dt>
                  <dd class="col-7 mb-0">{{ storage(column) }}</dd>
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.inJournal }}
                  </dt>
                  <dd class="col-7 mb-0">
                    @if (modeCard(column.mode); as card) {
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
                  </dd>
                </dl>
              </div>
            </div>
          }
        </div>
        @if (hasNoise()) {
          <p class="small text-body-secondary mt-2 mb-0">
            {{ copy.noisyNote }}
          </p>
        }
      </section>
    }
  </hilos-admin-page>`,
})
export class HilosChangeLogTableColumnsPage {
  protected readonly page = HilosPages.CHANGE_LOG_TABLE_COLUMNS

  /** The project's connection, page scope and tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()
  protected readonly copy = CHANGE_LOG_TABLE_COPY
  protected readonly storage = formatChangeLogColumnStorage
  protected readonly modeCard = changeLogColumnModeCard
  private readonly seq = tableColumnsPageSeq++
  protected readonly columnsHeadingId = `hilos-change-log-table-columns-title-${this.seq}`
  private readonly router = inject(HILOS_ROUTER)!
  private readonly route = hilosSignal(this.router.currentRoute)
  protected readonly overviewPath = computed(() =>
    this.router.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
      [CHANGE_LOG_TABLE_ROUTE_PARAM]:
        this.route().params[CHANGE_LOG_TABLE_ROUTE_PARAM] ?? '',
    }),
  )
  private readonly pageTable = signal<unknown>(undefined)
  protected readonly table = computed(() =>
    readHilosChangeLogTable(this.pageTable()),
  )
  protected readonly hasNoise = computed(
    () => this.table()?.columns.some(({ mode }) => mode === 'noise') === true,
  )
  constructor() {
    effect((onCleanup) => {
      onCleanup(
        mirrorHilosSignal(
          this.context().scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION),
          this.pageTable,
        ),
      )
    })
  }
}
