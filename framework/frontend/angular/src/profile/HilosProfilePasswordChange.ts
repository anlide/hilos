import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  effect,
  input,
  signal,
  viewChild,
} from '@angular/core'
import {
  focusInitial,
  HILOS_PROFILE_PASSWORD_CHANGE_COPY,
  HILOS_STEP_UP_COPY,
  subscribeSignal,
  type HilosProfilePasswordChangeFlow,
  type HilosProfilePasswordChangeStep,
  type HilosProfilePasswordChangeOpening,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'

let passwordChangeSequence = 0

/** Draw the password-change flow owned by the profile page. */
@Component({
  selector: 'hilos-profile-password-change',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, HilosModal, HilosStepUpStep, LoadingButton],
  template: `
    <hilos-modal
      [open]="open()"
      (openChange)="onOpenChange($event)"
      [title]="title()"
      initialFocus="inner"
      [confirmOnClose]="asksBeforeClosing()"
    >
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        aria-atomic="true"
        data-id="profile-password-live"
      >
        {{ step() === 'step-up' ? stepUpRefusal() : refusal() }}
      </div>
      <div #body data-id="profile-password-modal">
        <div class="hilos-stack">
          <div class="invisible" aria-hidden="true" inert>
            <ol class="list-unstyled d-flex flex-column gap-1 mb-3 small">
              @for (label of copy.steps; track label; let index = $index) {
                <li class="d-flex align-items-center gap-2">
                  <span class="badge rounded-pill text-bg-secondary">{{
                    index + 1
                  }}</span
                  ><span>{{ label }}</span>
                </li>
              }
            </ol>
            <div class="form-label">{{ copy.newPassword }}</div>
            <div class="form-control">&nbsp;</div>
            <div class="form-text">{{ copy.newPasswordHint }}</div>
            <div class="form-check mt-3">
              <span class="form-check-label">{{ copy.signOutOthers }}</span>
            </div>
          </div>
          <div class="align-self-start">
            @if (step() === 'step-up') {
              <hilos-step-up-step [controller]="flow().stepUp" />
            } @else if (step() !== 'refused') {
              @if (step() !== 'done') {
                <ol
                  class="list-unstyled d-flex flex-column gap-1 mb-3 small"
                  data-id="profile-password-steps"
                >
                  @for (label of copy.steps; track label; let index = $index) {
                    <li
                      class="d-flex align-items-center gap-2"
                      [class.fw-semibold]="index + 1 === stepNumber()"
                      [class.text-body-secondary]="index + 1 !== stepNumber()"
                      [attr.aria-current]="
                        index + 1 === stepNumber() ? 'step' : null
                      "
                    >
                      <span
                        class="badge rounded-pill"
                        [class.text-bg-primary]="index + 1 === stepNumber()"
                        [class.text-bg-secondary]="index + 1 !== stepNumber()"
                        >{{ index + 1 }}</span
                      ><span>{{ label }}</span>
                    </li>
                  }
                </ol>
              }
              @if (step() === 'start') {
                <p
                  class="small text-body-secondary mb-0 text-break"
                  data-id="profile-password-destination"
                >
                  {{ fill(copy.start) }}
                </p>
              }
              @if (step() === 'code') {
                <form
                  [id]="id + '-code-form'"
                  (submit)="$event.preventDefault(); flow().confirmCode()"
                >
                  <label [for]="id + '-code'" class="form-label">{{
                    copy.code
                  }}</label>
                  <input
                    [id]="id + '-code'"
                    class="form-control"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    data-autofocus
                    data-id="profile-password-code"
                    [value]="code()"
                    [attr.aria-describedby]="id + '-code-hint'"
                    (input)="flow().code.set(valueOf($event))"
                  />
                  <div [id]="id + '-code-hint'" class="form-text text-break">
                    {{ fill(copy.codeHint) }}
                  </div>
                </form>
              }
              @if (step() === 'password') {
                <form
                  [id]="id + '-password-form'"
                  (submit)="$event.preventDefault(); flow().save()"
                >
                  <label [for]="id + '-password'" class="form-label">{{
                    copy.newPassword
                  }}</label>
                  <input
                    [id]="id + '-password'"
                    type="password"
                    class="form-control"
                    autocomplete="new-password"
                    data-autofocus
                    data-id="profile-password-new"
                    [value]="newPassword()"
                    [attr.aria-describedby]="id + '-password-hint'"
                    (input)="flow().newPassword.set(valueOf($event))"
                  />
                  <div [id]="id + '-password-hint'" class="form-text">
                    {{ copy.newPasswordHint }}
                  </div>
                  <div class="form-check mt-3">
                    <input
                      [id]="id + '-others'"
                      type="checkbox"
                      class="form-check-input"
                      data-id="profile-password-sign-out-others"
                      [checked]="signOutOthers()"
                      (change)="flow().signOutOthers.set(checkedOf($event))"
                    />
                    <label [for]="id + '-others'" class="form-check-label">{{
                      copy.signOutOthers
                    }}</label>
                  </div>
                </form>
              }
              @if (step() === 'done') {
                <div
                  class="text-center py-2"
                  data-id="profile-password-outcome"
                >
                  <i
                    class="bi bi-check-circle-fill text-success d-block mb-2 fs-2"
                    aria-hidden="true"
                  ></i>
                  <div class="fw-semibold mb-1">{{ copy.doneTitle }}</div>
                  <p
                    class="small text-body-secondary mb-0"
                    data-id="profile-password-outcome-sessions"
                  >
                    {{ signedOutOthers() ? copy.doneSignedOut : copy.doneKept }}
                  </p>
                  <p class="small text-body-secondary mb-0">
                    {{ copy.doneResetCodes }}
                  </p>
                </div>
              }
            }
          </div>
        </div>
        <hilos-form-error
          [message]="refusal()"
          dataId="profile-password-error"
        />
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        @if (step() === 'done') {
          <button
            hilosLoadingButton
            class="btn-outline-secondary"
            [loading]="busy()"
            data-id="profile-password-again"
            (click)="flow().again()"
          >
            {{ copy.again }}
          </button>
          <button
            type="button"
            class="btn btn-primary"
            data-id="profile-password-done"
            (click)="requestClose()"
          >
            {{ copy.done }}
          </button>
        } @else {
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-password-cancel"
            (click)="requestClose()"
          >
            {{ copy.cancel }}
          </button>
          @if (step() === 'step-up') {
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="busy()"
              data-id="profile-password-step-up-confirm"
              (click)="flow().confirmStepUp()"
            >
              {{ stepUpCopy.confirm }}
            </button>
          }
          @if (step() === 'start') {
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="busy()"
              data-id="profile-password-send-code"
              (click)="flow().sendCode()"
            >
              {{ copy.sendCode }}
            </button>
          }
          @if (step() === 'code') {
            <button
              hilosLoadingButton
              type="submit"
              [attr.form]="id + '-code-form'"
              class="btn-primary"
              [loading]="busy()"
              [disabled]="code().trim() === '' || busy()"
              data-id="profile-password-confirm-code"
            >
              {{ copy.continue }}
            </button>
          }
          @if (step() === 'password') {
            <button
              hilosLoadingButton
              type="submit"
              [attr.form]="id + '-password-form'"
              class="btn-primary"
              [loading]="busy()"
              [disabled]="newPassword() === '' || busy()"
              data-id="profile-password-save"
            >
              {{ copy.save }}
            </button>
          }
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosProfilePasswordChange {
  readonly flow = input.required<HilosProfilePasswordChangeFlow>()
  protected readonly copy = HILOS_PROFILE_PASSWORD_CHANGE_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  protected readonly id = `hilos-password-change-${passwordChangeSequence++}`
  protected readonly step = signal<HilosProfilePasswordChangeStep>('closed')
  protected readonly opening = signal<HilosProfilePasswordChangeOpening | null>(
    null,
  )
  protected readonly code = signal('')
  protected readonly newPassword = signal('')
  protected readonly signOutOthers = signal(true)
  protected readonly signedOutOthers = signal(true)
  protected readonly busy = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly stepUpRefusal = signal<string | null>(null)
  protected readonly asksBeforeClosing = signal(false)
  private readonly body = viewChild<ElementRef<HTMLElement>>('body')
  protected readonly open = computed(
    () => this.step() !== 'closed' && this.step() !== 'opening',
  )
  protected readonly title = computed(() =>
    this.step() === 'step-up' || this.step() === 'refused'
      ? this.stepUpCopy.title
      : this.step() === 'done'
        ? this.copy.doneTitle
        : this.copy.title,
  )
  protected readonly stepNumber = computed(() =>
    this.step() === 'code'
      ? 2
      : this.step() === 'password'
        ? 3
        : this.step() === 'done'
          ? 4
          : 1,
  )

  constructor() {
    effect((onCleanup) => {
      const flow = this.flow()
      this.step.set(flow.step.get())
      this.opening.set(flow.opening.get())
      this.code.set(flow.code.get())
      this.newPassword.set(flow.newPassword.get())
      this.signOutOthers.set(flow.signOutOthers.get())
      this.signedOutOthers.set(flow.signedOutOthers.get())
      this.busy.set(flow.busy.get())
      this.refusal.set(flow.refusal.get())
      this.stepUpRefusal.set(flow.stepUp.refusal.get())
      this.asksBeforeClosing.set(flow.asksBeforeClosing.get())
      const off = [
        subscribeSignal(flow.step, (value) => this.step.set(value)),
        subscribeSignal(flow.opening, (value) => this.opening.set(value)),
        subscribeSignal(flow.code, (value) => this.code.set(value)),
        subscribeSignal(flow.newPassword, (value) =>
          this.newPassword.set(value),
        ),
        subscribeSignal(flow.signOutOthers, (value) =>
          this.signOutOthers.set(value),
        ),
        subscribeSignal(flow.signedOutOthers, (value) =>
          this.signedOutOthers.set(value),
        ),
        subscribeSignal(flow.busy, (value) => this.busy.set(value)),
        subscribeSignal(flow.refusal, (value) => this.refusal.set(value)),
        subscribeSignal(flow.stepUp.refusal, (value) =>
          this.stepUpRefusal.set(value),
        ),
        subscribeSignal(flow.asksBeforeClosing, (value) =>
          this.asksBeforeClosing.set(value),
        ),
      ]
      onCleanup(() => off.forEach((stop) => stop()))
    })
    afterRenderEffect(() => {
      this.step()
      const dialog =
        this.body()?.nativeElement.closest<HTMLElement>('[role="dialog"]')
      if (dialog) focusInitial(dialog)
    })
  }
  protected fill(text: string): string {
    return text.replace('{destination}', this.opening()?.destination ?? '')
  }
  protected onOpenChange(open: boolean): void {
    if (!open) this.flow().close()
  }
  protected valueOf(event: Event): string {
    return (event.target as HTMLInputElement).value
  }
  protected checkedOf(event: Event): boolean {
    return (event.target as HTMLInputElement).checked
  }
}
