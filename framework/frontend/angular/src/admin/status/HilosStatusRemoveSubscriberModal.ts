// The removal window keeps the core controller's captured target and reply.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  HIDDEN_VALUE,
  hilosAdminAccess,
  hilosToasts,
  type ActionError,
} from '@hilos/core/base'
import {
  HILOS_STATUS_SUBSCRIBERS_COPY,
  type HilosStatusSubscriberController,
  type HilosStatusSubscriberDialog,
  type HilosStatusSubscriberRow,
} from '@hilos/core/admin/status'
import {
  HilosEditNotice,
  HilosFormError,
  HilosHideable,
  HilosModal,
  LoadingButton,
  hilosSignal,
  mirrorHilosSignal,
} from '@hilos/angular/base'

let subscriberFormSequence = 0

/** Confirm removal of one captured Status subscription. */
@Component({
  selector: 'hilos-status-remove-subscriber-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosEditNotice,
    HilosFormError,
    HilosHideable,
    HilosModal,
    LoadingButton,
  ],
  template: `
    <hilos-modal
      [open]="dialog() !== null"
      [title]="copy.remove"
      [busy]="busy()"
      initialFocus="dialog"
      (openChange)="controller().close()"
    >
      @if (dialog(); as dialog) {
        <form
          [id]="formId"
          [attr.aria-label]="copy.remove"
          [attr.aria-describedby]="descriptionId"
          data-id="hilos-status-subscriber-remove-form"
          (submit)="submit($event)"
        >
          <hilos-form-error
            [message]="error()"
            [errorType]="failure()?.errorType ?? null"
            [errorDetail]="failure()?.errorDetail ?? null"
            [detailsTitle]="copy.remove"
            [copyText]="copyText()"
            dataId="hilos-status-subscriber-remove-error"
            [announce]="true"
          />
          <p
            class="fw-semibold text-break"
            data-id="hilos-status-subscriber-remove-target"
          >
            <hilos-hideable [value]="email()" /> · #{{ dialog.subscriberId }}
          </p>
          <p [id]="descriptionId">{{ copy.removeLead }}</p>
          <p class="text-body-secondary">{{ copy.removeHint }}</p>
          <hilos-edit-notice
            [kind]="blockedReason() ? 'deleted' : null"
            [text]="blockedReason() ?? ''"
            dataId="hilos-status-subscriber-remove-blocked"
          />
        </form>
      }
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="busy()"
          data-id="hilos-status-subscriber-remove-cancel"
          (click)="requestClose()"
        >
          {{ copy.cancel }}
        </button>
        <button
          hilosLoadingButton
          [attr.form]="formId"
          type="submit"
          class="btn-danger"
          [loading]="busy()"
          [disabled]="!busy() && !canSubmit()"
          data-id="hilos-status-subscriber-remove-confirm"
        >
          {{
            !busy() && target() === null
              ? copy.deletedLabel
              : copy.removeConfirm
          }}
        </button>
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosStatusRemoveSubscriberModal {
  /** The page-owned removal window and its correlated action lifecycle. */
  readonly controller = input.required<HilosStatusSubscriberController>()

  protected readonly copy = HILOS_STATUS_SUBSCRIBERS_COPY
  protected readonly formId = `hilos-status-subscriber-remove-form-${subscriberFormSequence++}`
  protected readonly descriptionId = `${this.formId}-description`
  protected readonly dialog = signal<HilosStatusSubscriberDialog | null>(null)
  protected readonly target = signal<HilosStatusSubscriberRow | null>(null)
  protected readonly email = computed(() =>
    this.adminAccess() === 'full'
      ? (this.dialog()?.email ?? HIDDEN_VALUE)
      : HIDDEN_VALUE,
  )
  protected readonly busy = signal(false)
  protected readonly error = signal<string | null>(null)
  protected readonly failure = signal<ActionError | null>(null)
  protected readonly blockedReason = signal<string | null>(null)
  protected readonly canSubmit = signal(false)
  private readonly adminAccess = hilosSignal(hilosAdminAccess)
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
        mirrorHilosSignal(controller.dialog, this.dialog),
        mirrorHilosSignal(controller.target, this.target),
        mirrorHilosSignal(controller.busy, this.busy),
        mirrorHilosSignal(controller.error, this.error),
        mirrorHilosSignal(controller.failure, this.failure),
        mirrorHilosSignal(controller.blockedReason, this.blockedReason),
        mirrorHilosSignal(controller.canSubmit, this.canSubmit),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
      })
    })
  }

  /** Close and report only the correlated success from this still-mounted window. */
  protected async submit(event: Event): Promise<void> {
    event.preventDefault()
    const controller = this.controller()
    if (!(await controller.submit())) return
    const message = controller.success.get()
    if (message !== null) hilosToasts.push(message, { severity: 'success' })
  }
}
