// Cancellation follows the live caption of the captured public identity.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  formatMoment,
  hilosTimeZone,
  hilosToasts,
  type ActionError,
} from '@hilos/core/base'
import type {
  HilosStatusWorkCancel,
  HilosStatusWorkRow,
} from '@hilos/core/admin/status'
import {
  HilosEditNotice,
  HilosFormError,
  HilosModal,
  LoadingButton,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

let cancelWorkFormSequence = 0

/** Confirm cancellation of one captured announcement. */
@Component({
  selector: 'hilos-status-cancel-work-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosEditNotice, HilosFormError, HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="opened()"
      title="Cancel scheduled maintenance"
      [busy]="busy()"
      initialFocus="dialog"
      (openChange)="controller().close()"
    >
      @if (opened()) {
        <form
          [id]="formId"
          aria-label="Cancel scheduled maintenance"
          [attr.aria-describedby]="descriptionId"
          data-id="hilos-status-work-cancel-form"
          (submit)="submit($event)"
        >
          <hilos-form-error
            [message]="error()"
            [errorType]="failure()?.errorType ?? null"
            [errorDetail]="failure()?.errorDetail ?? null"
            detailsTitle="Cancel scheduled maintenance"
            [copyText]="copyText()"
            dataId="hilos-status-work-cancel-error"
            [announce]="true"
          />
          <p
            class="fw-semibold text-break"
            data-id="hilos-status-work-cancel-target"
          >
            {{ target()?.title ?? 'Announcement unavailable' }}
          </p>
          @if (target(); as target) {
            <p data-id="hilos-status-work-cancel-interval">
              {{ moment(target.startsAt) }} – {{ moment(target.endsAt) }}
            </p>
          }
          <p [id]="descriptionId" class="mb-0 text-body-secondary">
            This announcement will leave the upcoming list. Subscribers will be
            notified of the cancellation.
          </p>
          <hilos-edit-notice
            [kind]="blockedReason() ? 'deleted' : null"
            [text]="blockedReason() ?? ''"
            dataId="hilos-status-work-cancel-blocked"
          />
        </form>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="busy()"
          data-id="hilos-status-work-cancel-close"
          (click)="requestClose()"
        >
          Back
        </button>
        <button
          hilosLoadingButton
          [attr.form]="formId"
          type="submit"
          class="btn-danger"
          [loading]="busy()"
          [disabled]="!busy() && !canSubmit()"
          data-id="hilos-status-work-cancel-confirm"
        >
          {{
            !busy() && target() === undefined ? 'Deleted' : 'Cancel maintenance'
          }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosStatusCancelWorkModal {
  /** The page-owned focus and correlated cancellation lifecycle. */
  readonly controller = input.required<HilosStatusWorkCancel>()

  protected readonly formId = `hilos-status-work-cancel-form-${cancelWorkFormSequence++}`
  protected readonly descriptionId = `${this.formId}-description`
  protected readonly opened = signal(false)
  protected readonly target = signal<HilosStatusWorkRow | undefined>(undefined)
  protected readonly busy = signal(false)
  protected readonly canSubmit = signal(false)
  protected readonly blockedReason = signal<string | null>(null)
  protected readonly error = signal<string | null>(null)
  protected readonly failure = signal<ActionError | null>(null)
  private readonly timeZone = hilosSignal(hilosTimeZone)
  protected readonly copyText = computed(() => {
    const failure = this.failure()
    return failure?.errorDetail
      ? [failure.errorType, failure.errorDetail].filter(Boolean).join('\n')
      : (this.error() ?? '')
  })

  constructor() {
    effect((onCleanup) => {
      const controller = this.controller()
      const stops = [
        mirrorHilosSignal(controller.opened, this.opened),
        mirrorHilosSignal(controller.target, this.target),
        mirrorHilosSignal(controller.busy, this.busy),
        mirrorHilosSignal(controller.canSubmit, this.canSubmit),
        mirrorHilosSignal(controller.blockedReason, this.blockedReason),
        mirrorHilosSignal(controller.error, this.error),
        mirrorHilosSignal(controller.failure, this.failure),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
      })
    })
  }

  protected moment(at: number): string {
    this.timeZone()
    return (
      formatMoment(at, {
        format: {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          timeZoneName: 'short',
        },
      }) ?? '—'
    )
  }

  protected async submit(event: Event): Promise<void> {
    event.preventDefault()
    const controller = this.controller()
    if (!(await controller.submit())) return
    const message = controller.success.get()
    if (message !== null) hilosToasts.push(message, { severity: 'success' })
  }
}
