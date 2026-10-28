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
  formatChangeLogTableOwner,
  formatChangeLogTrigger,
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

let tableTriggersPageSeq = 0

@Component({
  selector: 'hilos-change-log-table-triggers-page',
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
      <section class="mb-4" data-id="hilos-change-log-table-triggers">
        <h2 [id]="triggersHeadingId" class="h5">{{ copy.triggers }}</h2>
        <p class="small text-body-secondary mb-2">
          {{ copy.triggersLead }}
        </p>
        <div class="table-responsive d-none d-md-block">
          <table
            class="table table-sm align-middle mb-0"
            [attr.aria-labelledby]="triggersHeadingId"
          >
            <thead>
              <tr>
                <th scope="col">{{ copy.trigger }}</th>
                <th scope="col">{{ copy.owner }}</th>
                <th scope="col">{{ copy.fires }}</th>
                <th scope="col">{{ copy.writesTo }}</th>
                <th scope="col">{{ copy.validFrom }}</th>
              </tr>
            </thead>
            <tbody>
              @for (trigger of current.triggers; track trigger.name) {
                @let line = describeTrigger(trigger);
                <tr>
                  <td>
                    <code class="text-break">{{ trigger.name }}</code>
                  </td>
                  <td>
                    <span class="badge bg-body-secondary text-body border">{{
                      copy.service
                    }}</span>
                  </td>
                  <td class="small text-nowrap">{{ line.fires }}</td>
                  <td class="small">{{ line.writesTo }}</td>
                  <td class="small text-nowrap">{{ line.validFrom }}</td>
                </tr>
              }
            </tbody>
          </table>
        </div>
        <div
          class="d-md-none"
          role="list"
          [attr.aria-labelledby]="triggersHeadingId"
        >
          @for (trigger of current.triggers; track trigger.name) {
            @let line = describeTrigger(trigger);
            <div class="card mb-2" role="listitem">
              <div class="card-body py-2 px-3">
                <div
                  class="d-flex flex-wrap align-items-center gap-1 fw-medium mb-1"
                >
                  <code class="text-break">{{ trigger.name }}</code>
                  <span class="badge bg-body-secondary text-body border">{{
                    copy.service
                  }}</span>
                </div>
                <dl class="row mb-0 small g-0">
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.fires }}
                  </dt>
                  <dd class="col-7 mb-0">{{ line.fires }}</dd>
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.writesTo }}
                  </dt>
                  <dd class="col-7 mb-0">{{ line.writesTo }}</dd>
                  <dt class="col-5 fw-normal text-body-secondary">
                    {{ copy.validFrom }}
                  </dt>
                  <dd class="col-7 mb-0">{{ line.validFrom }}</dd>
                </dl>
              </div>
            </div>
          }
        </div>
        <p class="small text-body-secondary mt-2 mb-0">
          {{ copy.triggersFootnote }}
        </p>
      </section>
    }
  </hilos-admin-page>`,
})
export class HilosChangeLogTableTriggersPage {
  protected readonly page = HilosPages.CHANGE_LOG_TABLE_TRIGGERS

  /** The project's connection, page scope and tracked actions. */
  readonly context = input.required<HilosChangeLogReceiptContext>()
  protected readonly copy = CHANGE_LOG_TABLE_COPY
  protected readonly owner = formatChangeLogTableOwner
  protected readonly describeTrigger = formatChangeLogTrigger
  private readonly seq = tableTriggersPageSeq++
  protected readonly triggersHeadingId = `hilos-change-log-table-triggers-title-${this.seq}`
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
