import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
} from '@angular/core'
import {
  createHilosLegalAcceptancesTable,
  hiddenAsWord,
  hilosLegalDocumentLabel,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  resolveHilosPath,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'

/** Immutable acceptance records with the complete server-supplied filter vocabulary. */
@Component({
  selector: 'hilos-legal-acceptances-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosAdminPage, HilosLink, HilosTableCell, HilosViewportTable],
  template: `
    <hilos-admin-page [page]="page">
      <div data-id="legal-acceptances-table">
        <hilos-viewport-table [controller]="table().controller">
          <ng-template [hilosTableCell]="keys.name" let-row
            ><div
              data-id="legal-acceptance-row"
              [attr.data-record]="row.rowKey"
            >
              <strong>{{ hiddenAsWord(row.name) }}</strong>
              <div class="small text-body-secondary">
                {{ hiddenAsWord(row.email) ?? 'No verified email' }}
              </div>
            </div></ng-template
          >
          <ng-template [hilosTableCell]="keys.document" let-row>{{
            documentLabel(row.document)
          }}</ng-template>
          <ng-template [hilosTableCell]="keys.revisionId" let-row
            >{{ row.revisionId }}
            @if (row.declared === false) {
              <span class="badge text-bg-warning">Not in code</span>
            }
          </ng-template>
          <ng-template [hilosTableCell]="actionsKey" let-row
            ><a
              [hilosLink]="path(userPage, { userId: '' + row.userId })"
              class="btn btn-sm btn-outline-secondary"
              data-id="legal-acceptance-person"
              >Account</a
            ></ng-template
          >
        </hilos-viewport-table>
      </div>
      <p class="small text-body-secondary mt-3">
        Acceptance records cannot be edited or deleted here. A record names one
        person, one document, one exact revision and the time of acceptance.
        Refusal and silence create no acceptance record.
      </p>
    </hilos-admin-page>
  `,
})
export class HilosLegalAcceptancesPage {
  readonly context = input.required<HilosLegalContext>()
  protected readonly page = HilosPages.LEGAL_ACCEPTANCES
  protected readonly userPage = HilosPages.USER
  protected readonly keys = HilosLegalRowKey
  protected readonly actionsKey = HILOS_TABLE_ACTIONS_KEY
  protected readonly path = resolveHilosPath
  protected readonly hiddenAsWord = hiddenAsWord
  protected readonly documentLabel = hilosLegalDocumentLabel
  protected readonly table = computed(() =>
    createHilosLegalAcceptancesTable(this.context()),
  )
  constructor() {
    effect((onCleanup) => {
      const table = this.table()
      table.start()
      onCleanup(() => table.dispose())
    })
  }
}
