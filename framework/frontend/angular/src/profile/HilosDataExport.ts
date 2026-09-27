import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  DATA_EXPORT_DOWNLOAD_PATH,
  HILOS_DATA_EXPORT_COPY,
  HILOS_STEP_UP_COPY,
  dataExportStatusText,
  subscribeSignal,
  type DataExportNode,
  type HilosDataExportStore,
  type HilosDataExportFlow,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'

/** The shared personal-copy surface over its owner's running state and actions. */
@Component({
  selector: 'hilos-data-export',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, HilosModal, HilosStepUpStep, LoadingButton],
  template: `
    <section class="border rounded p-3 mb-3" data-id="data-export">
      <h3 class="h6 mb-2">{{ copy.title }}</h3>
      <p class="small text-body-secondary mb-2">{{ copy.lead }} {{ lead() }}</p>
      <div class="visually-hidden" role="status" aria-live="polite">
        {{ status() }}
      </div>
      <div class="visually-hidden" role="alert" aria-live="assertive">
        {{ open() ? '' : refusal() }}
      </div>
      <div class="hilos-stack">
        <p class="small mb-2 invisible" aria-hidden="true" inert>
          {{ preparingRoom }}
        </p>
        @if (node(); as current) {
          <p class="small mb-2" [attr.data-id]="'data-export-' + current.state">
            {{ status() }}
          </p>
        }
      </div>
      <hilos-form-error
        [message]="open() ? null : refusal()"
        dataId="data-export-error"
      />
      <div class="hilos-stack">
        <div
          class="d-flex flex-column gap-2 invisible"
          aria-hidden="true"
          inert
        >
          <span class="btn btn-sm btn-primary">{{ copy.download }}</span>
          <span class="btn btn-sm btn-outline-secondary">{{
            copy.prepareNew
          }}</span>
        </div>
        <div class="d-flex flex-column gap-2">
          @if (node()?.state === 'ready') {
            <a
              class="btn btn-sm btn-primary"
              [href]="downloadPath"
              download
              data-id="data-export-download"
              >{{ copy.download }}</a
            >
            <button
              hilosLoadingButton
              class="btn-sm btn-outline-secondary"
              [loading]="busy()"
              data-id="data-export-prepare-new"
              (click)="flow().prepare()"
            >
              {{ copy.prepareNew }}
            </button>
          } @else if (node()?.state !== 'preparing') {
            <button
              hilosLoadingButton
              class="btn-sm btn-primary"
              [loading]="busy()"
              [attr.data-id]="
                node()?.state === 'failed'
                  ? 'data-export-retry'
                  : 'data-export-prepare'
              "
              (click)="flow().prepare()"
            >
              {{ node()?.state === 'failed' ? copy.retry : copy.prepare }}
            </button>
          }
        </div>
      </div>
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
          id="hilos-data-export-proof"
          data-id="data-export-step-up"
          (submit)="confirm($event)"
        >
          <hilos-step-up-step [controller]="flow().stepUp" />
          <hilos-form-error
            [message]="refusal()"
            dataId="data-export-order-error"
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
            form="hilos-data-export-proof"
            [loading]="busy()"
            data-id="data-export-confirm"
          >
            {{ stepUpCopy.confirm }}
          </button>
        </ng-template>
      </hilos-modal>
    </section>
  `,
})
export class HilosDataExport {
  readonly store = input.required<HilosDataExportStore>()
  readonly flow = input.required<HilosDataExportFlow>()
  readonly lead = input('')
  protected readonly copy = HILOS_DATA_EXPORT_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  protected readonly downloadPath = DATA_EXPORT_DOWNLOAD_PATH
  protected readonly node = signal<DataExportNode | null>(null)
  protected readonly busy = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly stepRefusal = signal<string | null>(null)
  protected readonly open = signal(false)
  protected readonly status = computed(() => dataExportStatusText(this.node()))
  protected readonly preparingRoom = HILOS_DATA_EXPORT_COPY.preparing.replace(
    '{time}',
    new Date().toLocaleString(),
  )

  constructor() {
    effect((onCleanup) => {
      const store = this.store()
      const flow = this.flow()
      this.node.set(store.state.get())
      this.busy.set(flow.busy.get())
      this.open.set(flow.open.get())
      this.refusal.set(flow.refusal.get())
      this.stepRefusal.set(flow.stepUp.refusal.get())
      const off = [
        subscribeSignal(store.state, (value) => this.node.set(value)),
        subscribeSignal(flow.busy, (value) => this.busy.set(value)),
        subscribeSignal(flow.open, (value) => this.open.set(value)),
        subscribeSignal(flow.refusal, (value) => this.refusal.set(value)),
        subscribeSignal(flow.stepUp.refusal, (value) =>
          this.stepRefusal.set(value),
        ),
      ]
      onCleanup(() => off.forEach((stop) => stop()))
    })
  }

  protected onOpenChange(open: boolean): void {
    if (!open) this.flow().close()
  }
  protected confirm(event: Event): void {
    event.preventDefault()
    void this.flow().confirm()
  }
}
