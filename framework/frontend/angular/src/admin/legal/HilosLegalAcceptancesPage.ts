import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosLegalAcceptancesExport,
  createHilosLegalAcceptancesTable,
  hilosLegalAcceptancesExportFilterLine,
  hilosLegalAcceptancesExportStatus,
  hilosLegalAcceptancesExportStatusRoom,
  hilosLegalDocumentLabel,
  HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY,
  HILOS_STEP_UP_COPY,
  HilosLegalRowKey,
  HILOS_TABLE_ACTIONS_KEY,
  HilosPages,
  LEGAL_ACCEPTANCES_EXPORT_DOWNLOAD_PATH,
  resolveHilosPath,
  subscribeSignal,
  type HilosLegalAcceptancesExportNode,
  type HilosLegalContext,
} from '@hilos/core'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosFormError } from '../../HilosFormError.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosTableCell } from '../../HilosTableCell.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { LoadingButton } from '../../LoadingButton.js'
import { HilosStepUpStep } from '../../auth/HilosStepUpStep.js'

/** Immutable acceptance records with the complete server-supplied filter vocabulary, and their export. */
@Component({
  selector: 'hilos-legal-acceptances-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosAdminPage,
    HilosFormError,
    HilosHideable,
    HilosLink,
    HilosModal,
    HilosStepUpStep,
    HilosTableCell,
    HilosViewportTable,
    LoadingButton,
  ],
  template: `
    <hilos-admin-page [page]="page">
      <div class="d-flex flex-column align-items-end mb-3">
        <div class="visually-hidden" role="status" aria-live="polite">
          {{ status() }}
        </div>
        <div class="visually-hidden" role="alert" aria-live="assertive">
          {{ open() ? '' : refusal() }}
        </div>
        <div class="d-flex flex-wrap justify-content-end gap-2">
          @if (node()?.state === 'ready') {
            <a
              class="btn btn-sm btn-primary"
              [href]="downloadPath"
              download
              data-id="legal-acceptances-export-download"
              >{{ copy.download }}</a
            >
          }
          <button
            hilosLoadingButton
            class="btn-sm btn-outline-primary"
            [loading]="busy()"
            [disabled]="node()?.state === 'preparing'"
            data-id="legal-acceptances-export"
            (click)="exporter().export()"
          >
            <i class="bi bi-download me-1" aria-hidden="true"></i
            >{{ copy.button }}
          </button>
        </div>
        <div class="hilos-stack w-100 text-end mt-2">
          <div class="invisible" aria-hidden="true" inert>
            <p class="small mb-0">{{ statusRoom }}</p>
            <p class="small text-body-secondary text-truncate mb-0">
              {{ copy.all }}
            </p>
          </div>
          @if (node(); as current) {
            <div>
              <p
                class="small mb-0"
                [attr.data-id]="'legal-acceptances-export-' + current.state"
              >
                {{ status() }}
              </p>
              <p
                class="small text-body-secondary text-truncate mb-0"
                data-id="legal-acceptances-export-filter"
              >
                {{ filterLine() }}
              </p>
            </div>
          }
        </div>
        <div class="w-100">
          <hilos-form-error
            [message]="open() ? null : refusal()"
            dataId="legal-acceptances-export-error"
          />
        </div>
      </div>
      <div data-id="legal-acceptances-table">
        <hilos-viewport-table [controller]="table().controller">
          <ng-template [hilosTableCell]="keys.name" let-row
            ><div
              data-id="legal-acceptance-row"
              [attr.data-record]="row.rowKey"
            >
              <strong><hilos-hideable [value]="row.name" /></strong>
              <div class="small text-body-secondary">
                <hilos-hideable [value]="row.email"
                  ><ng-template let-email>{{
                    email ?? 'No verified email'
                  }}</ng-template></hilos-hideable
                >
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
      <hilos-modal
        [open]="open()"
        (openChange)="onOpenChange($event)"
        [title]="stepUpCopy.title"
        initialFocus="inner"
      >
        <div class="visually-hidden" role="alert" aria-live="assertive">
          {{ stepRefusal() ?? refusal() }}
        </div>
        <form
          id="hilos-legal-acceptances-export-proof"
          data-id="legal-acceptances-export-step-up"
          (submit)="confirm($event)"
        >
          <hilos-step-up-step [controller]="exporter().stepUp" />
          <hilos-form-error
            [message]="refusal()"
            dataId="legal-acceptances-export-order-error"
          />
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-outline-secondary"
            (click)="requestClose()"
          >
            {{ stepUpCopy.cancel }}
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            type="submit"
            form="hilos-legal-acceptances-export-proof"
            [loading]="busy()"
            data-id="legal-acceptances-export-confirm"
          >
            {{ stepUpCopy.confirm }}
          </button>
        </ng-template>
      </hilos-modal>
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
  protected readonly documentLabel = hilosLegalDocumentLabel
  protected readonly copy = HILOS_LEGAL_ACCEPTANCES_EXPORT_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  protected readonly downloadPath = LEGAL_ACCEPTANCES_EXPORT_DOWNLOAD_PATH
  protected readonly statusRoom = hilosLegalAcceptancesExportStatusRoom()
  protected readonly table = computed(() =>
    createHilosLegalAcceptancesTable(this.context()),
  )
  protected readonly exporter = computed(() =>
    createHilosLegalAcceptancesExport(this.context(), this.table().controller),
  )
  protected readonly node = signal<HilosLegalAcceptancesExportNode | null>(null)
  protected readonly busy = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly stepRefusal = signal<string | null>(null)
  protected readonly open = signal(false)
  protected readonly status = computed(() =>
    hilosLegalAcceptancesExportStatus(this.node()),
  )
  protected readonly filterLine = computed(() => {
    const node = this.node()
    return node === null ? '' : hilosLegalAcceptancesExportFilterLine(node)
  })
  constructor() {
    effect((onCleanup) => {
      const table = this.table()
      const exporter = this.exporter()
      table.start()
      exporter.start()
      this.node.set(exporter.state.get())
      this.busy.set(exporter.busy.get())
      this.open.set(exporter.open.get())
      this.refusal.set(exporter.refusal.get())
      this.stepRefusal.set(exporter.stepUp.refusal.get())
      const off = [
        subscribeSignal(exporter.state, (value) => this.node.set(value)),
        subscribeSignal(exporter.busy, (value) => this.busy.set(value)),
        subscribeSignal(exporter.open, (value) => this.open.set(value)),
        subscribeSignal(exporter.refusal, (value) => this.refusal.set(value)),
        subscribeSignal(exporter.stepUp.refusal, (value) =>
          this.stepRefusal.set(value),
        ),
      ]
      onCleanup(() => {
        off.forEach((stop) => stop())
        exporter.dispose()
        table.dispose()
      })
    })
  }

  protected onOpenChange(open: boolean): void {
    if (!open) this.exporter().close()
  }
  protected confirm(event: Event): void {
    event.preventDefault()
    void this.exporter().confirm()
  }
}
