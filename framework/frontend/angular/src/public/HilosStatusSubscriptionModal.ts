// The page owns the controller's lifetime; this window only draws its form.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  STATUS_SUBSCRIPTION_COPY,
  type StatusSubscription,
} from '@hilos/core/status'
import {
  HilosFormError,
  HilosModal,
  LoadingButton,
  mirrorHilosSignal,
} from '@hilos/angular/base'

let subscriptionFormSequence = 0

/** Subscribe by email or confirm an explicitly previewed unsubscribe link. */
@Component({
  selector: 'hilos-status-subscription-modal',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, HilosModal, LoadingButton],
  template: `
    <hilos-modal
      [open]="dialog()"
      [title]="title()"
      [busy]="busy()"
      [initialFocus]="operation() === 'subscribe' ? '' : 'dialog'"
      (openChange)="close($event)"
    >
      <form
        [id]="formId"
        novalidate
        data-id="status-subscription-form"
        (submit)="submit($event)"
        (keydown)="onEnter($event)"
      >
        @if (operation() === 'subscribe') {
          <p class="text-body-secondary mb-2">
            Letters about outages. You do not need an account.
          </p>
          <label class="form-label" [for]="emailId">Email address</label>
          <input
            [id]="emailId"
            [value]="draft().email"
            type="email"
            name="email"
            autocomplete="email"
            class="form-control form-control-sm"
            data-autofocus
            data-id="status-subscription-email"
            [attr.aria-describedby]="hintId + ' ' + errorId"
            [attr.aria-invalid]="error() ? true : null"
            (input)="setEmail($event)"
          />
          <p [id]="hintId" class="small text-body-secondary mt-2 mb-0">
            We will send a link to confirm the address. Nothing else is sent
            until you confirm, and every letter has a link to unsubscribe. If
            you did not ask for the email, ignore it.
          </p>
        } @else if (operation() === 'unsubscribe') {
          <p class="mb-0 text-break" data-id="status-subscription-address">
            Stop sending status updates to
            <strong>{{ maskedEmail() ?? '…' }}</strong
            >?
          </p>
        }
        <div class="hilos-stack small mt-2">
          <p class="invisible mb-0" aria-hidden="true" inert>
            {{ copy.waiting }}
          </p>
          <p class="mb-0" data-id="status-subscription-progress">
            {{ progress() }}
          </p>
        </div>
        <div
          class="visually-hidden"
          role="status"
          aria-live="polite"
          aria-atomic="true"
          data-id="status-subscription-live"
        >
          {{ progress() }}
        </div>
        <div [id]="errorId">
          <hilos-form-error
            [message]="error()"
            dataId="status-subscription-error"
            [announce]="true"
          />
        </div>
      </form>
      <ng-template #modalActions let-requestClose="requestClose">
        <button
          type="button"
          class="btn btn-secondary"
          [disabled]="busy()"
          data-id="status-subscription-cancel"
          (click)="requestClose()"
        >
          {{ operation() === 'subscribe' ? 'Cancel' : copy.keep }}
        </button>
        @if (operation() === 'subscribe' || maskedEmail() !== null) {
          <button
            hilosLoadingButton
            type="submit"
            [attr.form]="formId"
            [class]="operation() === 'subscribe' ? 'btn-primary' : 'btn-danger'"
            [loading]="busy()"
            [disabled]="!busy() && !canSubmit()"
            data-id="status-subscription-submit"
          >
            {{ operation() === 'subscribe' ? 'Subscribe' : copy.unsubscribe }}
          </button>
        } @else if (operation() === 'unsubscribe') {
          <span class="btn btn-danger invisible" aria-hidden="true" inert>{{
            copy.unsubscribe
          }}</span>
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosStatusSubscriptionModal {
  /** The single mounted public page's subscription controller. */
  readonly controller = input.required<StatusSubscription>()

  protected readonly copy = STATUS_SUBSCRIPTION_COPY
  protected readonly operation =
    signal<ReturnType<StatusSubscription['operation']['get']>>(null)
  protected readonly dialog = signal(false)
  protected readonly draft = signal({ email: '' })
  protected readonly busy = signal(false)
  protected readonly usable = signal(false)
  protected readonly step =
    signal<ReturnType<StatusSubscription['step']['get']>>('idle')
  protected readonly error = signal<string | null>(null)
  protected readonly maskedEmail = signal<string | null>(null)
  protected readonly canSubmit = signal(false)
  protected readonly formId = `status-subscription-${subscriptionFormSequence++}`
  protected readonly emailId = `${this.formId}-email`
  protected readonly hintId = `${this.formId}-hint`
  protected readonly errorId = `${this.formId}-error`
  protected readonly title = computed(() =>
    this.operation() === 'subscribe'
      ? this.copy.subscribe
      : this.copy.unsubscribe,
  )
  protected readonly progress = computed(() =>
    !this.usable() || this.step() === 'waiting'
      ? this.copy.waiting
      : this.step() !== 'pending'
        ? ''
        : this.operation() === 'unsubscribe' && this.maskedEmail() === null
          ? 'Checking your unsubscribe link…'
          : 'Waiting for confirmation…',
  )

  constructor() {
    effect((onCleanup) => {
      const controller = this.controller()
      const stops = [
        mirrorHilosSignal(controller.operation, this.operation),
        mirrorHilosSignal(controller.dialog, this.dialog),
        mirrorHilosSignal(controller.draft, this.draft),
        mirrorHilosSignal(controller.busy, this.busy),
        mirrorHilosSignal(controller.usable, this.usable),
        mirrorHilosSignal(controller.step, this.step),
        mirrorHilosSignal(controller.error, this.error),
        mirrorHilosSignal(controller.maskedEmail, this.maskedEmail),
        mirrorHilosSignal(controller.canSubmit, this.canSubmit),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
      })
    })
  }

  /** Mirror the typed address into the page's core controller. */
  protected setEmail(event: Event): void {
    this.controller().setDraft({
      email: (event.target as HTMLInputElement).value,
    })
  }
  /** A modal gesture closes the controller only after its own guards pass. */
  protected close(open: boolean): void {
    if (!open) this.controller().close()
  }
  /** Enter and the primary button share the controller's submission verdict. */
  protected submit(event: Event): void {
    event.preventDefault()
    void this.controller().submit()
  }

  /** Confirm from the email field while leaving nested buttons to their own actions. */
  protected onEnter(event: Event): void {
    const key = event as KeyboardEvent
    if (key.key !== 'Enter') return
    if (key.target instanceof Element && key.target.closest('button, a')) return
    this.submit(event)
  }
}
