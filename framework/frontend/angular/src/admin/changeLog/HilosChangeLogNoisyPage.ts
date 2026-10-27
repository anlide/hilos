import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_NOTE,
  CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  CHANGE_LOG_NOISY_COLUMNS_TITLE,
  readHilosChangeLogNoisyColumns,
  type HilosChangeLogContext,
} from '@hilos/core/admin/change-log'
import { HilosPages } from '@hilos/core/base'

import { HilosAdminPage, mirrorHilosSignal } from '@hilos/angular/base'

// Distinct ids so two pages in one document never share an aria-labelledby
// target — what Vue and React write as useId.
let noisyHeadingSeq = 0

/** The noisy-column snapshot, unchanged for the legacy view. */
@Component({
  selector: 'hilos-change-log-noisy-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage],
  template: `
    @let noisy = noisyColumns();
    <hilos-admin-page [page]="page">
      <section class="mt-4" data-id="hilos-change-log-noisy-columns">
        <h2 [id]="noisyHeadingId" class="h5">{{ text.noisyTitle }}</h2>
        <p class="small text-body-secondary">{{ text.noisyNote }}</p>
        @if (noisy === null) {
          <p class="text-body-secondary">—</p>
        } @else if (noisy.length === 0) {
          <p class="text-body-secondary">{{ text.noisyEmpty }}</p>
        } @else {
          <div class="d-none d-md-block">
            <table
              class="table table-sm align-middle mb-0"
              [attr.aria-labelledby]="noisyHeadingId"
            >
              <thead>
                <tr>
                  <th scope="col">{{ text.noisyColumn }}</th>
                  <th scope="col">{{ text.noisyReason }}</th>
                </tr>
              </thead>
              <tbody>
                @for (
                  column of noisy;
                  track column.table + '.' + column.column
                ) {
                  <tr>
                    <td class="font-monospace text-break">
                      {{ column.table }}.{{ column.column }}
                    </td>
                    <td class="small text-break">{{ column.reason }}</td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
          <div class="d-md-none d-flex flex-column gap-2">
            @for (column of noisy; track column.table + '.' + column.column) {
              <div class="border rounded-3 p-3">
                <div class="font-monospace text-break mb-1">
                  {{ column.table }}.{{ column.column }}
                </div>
                <p class="small text-body-secondary text-break mb-0">
                  {{ column.reason }}
                </p>
              </div>
            }
          </div>
        }
      </section>
    </hilos-admin-page>
  `,
})
export class HilosChangeLogNoisyPage {
  /** The project's connection and page scope. */
  readonly context = input.required<HilosChangeLogContext>()

  protected readonly page = HilosPages.CHANGE_LOG_NOISY
  protected readonly text = {
    noisyTitle: CHANGE_LOG_NOISY_COLUMNS_TITLE,
    noisyNote: CHANGE_LOG_NOISY_COLUMNS_NOTE,
    noisyEmpty: CHANGE_LOG_NOISY_COLUMNS_EMPTY,
    noisyColumn: CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL,
    noisyReason: CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL,
  } as const
  protected readonly noisyHeadingId = `hilos-change-log-noisy-columns-title-${noisyHeadingSeq++}`
  // Mirrored from the page's noisy-column section, which derives from the context input.
  private readonly pageNoisyColumns = signal<unknown>(undefined)
  protected readonly noisyColumns = computed(() =>
    readHilosChangeLogNoisyColumns(this.pageNoisyColumns()),
  )
  constructor() {
    effect((onCleanup) => {
      onCleanup(
        mirrorHilosSignal(
          this.context().scopes.pageDataSignal(
            CHANGE_LOG_NOISY_COLUMNS_SECTION,
          ),
          this.pageNoisyColumns,
        ),
      )
    })
  }
}
