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
  HILOS_PROFILE_EMAIL_CHANGE_COPY,
  HILOS_PROFILE_EMAIL_CHANGE_STEPS,
  HILOS_STEP_UP_COPY,
  subscribeSignal,
  type HilosProfileEmailChangeFlow,
  type HilosProfileEmailChangeStep,
  type HilosStepUpOpening,
  type ReadonlySignal,
  type Unsubscribe,
} from '@hilos/core'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'

/**
 * Put a core signal's value into an Angular one now and on every change.
 *
 * @param source The core signal.
 * @param target The Angular signal that mirrors it.
 */
function mirror<T>(
  source: ReadonlySignal<T>,
  target: { set(value: T): void },
): Unsubscribe {
  target.set(source.get())
  return subscribeSignal(source, (value) => target.set(value))
}

/**
 * Draw the profile root's email window (HIL-299, HIL-1169): one modal, five
 * steps, the content changing in place. Every step is one server-confirmed
 * submit; a refusal stays on the step in the room hilos-form-error holds for
 * it. Closing on steps 2 to 4 asks first, because a code is already out.
 */
@Component({
  selector: 'hilos-profile-email-change',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, HilosModal, HilosStepUpStep, LoadingButton],
  template: `
    <hilos-modal
      [open]="step() !== 'closed'"
      (openChange)="onOpenChange($event)"
      [title]="title()"
      [confirmOnClose]="asksBeforeClosing()"
      initialFocus="inner"
    >
      <!-- This dialog's own voice; one region for all the steps, since only
      one step is on screen at a time. -->
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="profile-email-live-assertive"
      >
        {{ step() === 'step-up' ? stepUpRefusal() : refusal() }}
      </div>
      <div #body>
        @if (step() === 'step-up') {
          <hilos-step-up-step [controller]="flow().stepUp" />
        }
        @if (step() !== 'step-up' && step() !== 'done') {
          <ol
            class="list-unstyled d-flex flex-column gap-1 mb-3 small"
            data-id="profile-email-steps"
          >
            @for (label of copy.steps; track label; let index = $index) {
              <li
                class="d-flex align-items-center gap-2"
                [class.fw-semibold]="index + 1 === stepNumber()"
                [class.text-body-secondary]="index + 1 !== stepNumber()"
                [attr.aria-current]="index + 1 === stepNumber() ? 'step' : null"
              >
                <span
                  class="badge rounded-pill"
                  [class.text-bg-primary]="index + 1 === stepNumber()"
                  [class.text-bg-secondary]="index + 1 !== stepNumber()"
                  >{{ index + 1 }}</span
                >
                <span>{{ label }}</span>
              </li>
            }
          </ol>
        }
        @switch (step()) {
          @case ('send-current') {
            <form (submit)="$event.preventDefault(); flow().submit()">
              <p class="small text-body-secondary mb-0">
                {{ copy.sendCurrentLead }} <strong>{{ was() }}</strong>
                {{ copy.sendCurrentTail }}
              </p>
              <hilos-form-error
                [message]="refusal()"
                dataId="profile-email-error"
              />
            </form>
          }
          @case ('confirm-current') {
            <form (submit)="$event.preventDefault(); flow().submit()">
              <label class="form-label" for="profile-email-code-current">{{
                copy.code
              }}</label>
              <input
                id="profile-email-code-current"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                class="form-control"
                data-autofocus
                data-id="profile-email-code-current"
                [value]="currentCode()"
                (input)="flow().currentCode.set(valueOf($event))"
              />
              <div class="form-text">{{ sentTo(was()) }}</div>
              <hilos-form-error
                [message]="refusal()"
                dataId="profile-email-error"
              />
            </form>
          }
          @case ('new-address') {
            <form (submit)="$event.preventDefault(); flow().submit()">
              <label class="form-label" for="profile-email-new">{{
                copy.newEmail
              }}</label>
              <input
                id="profile-email-new"
                type="email"
                autocomplete="email"
                class="form-control"
                data-autofocus
                data-id="profile-email-new"
                [value]="newEmail()"
                (input)="flow().newEmail.set(valueOf($event))"
              />
              <div class="form-text">{{ copy.newEmailHint }}</div>
              <hilos-form-error
                [message]="refusal()"
                dataId="profile-email-error"
              />
            </form>
          }
          @case ('confirm-new') {
            <form (submit)="$event.preventDefault(); flow().submit()">
              <label class="form-label" for="profile-email-code-new">{{
                copy.code
              }}</label>
              <input
                id="profile-email-code-new"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                class="form-control"
                data-autofocus
                data-id="profile-email-code-new"
                [value]="newCode()"
                (input)="flow().newCode.set(valueOf($event))"
              />
              <div class="form-text">{{ sentTo(newEmail().trim()) }}</div>
              <hilos-form-error
                [message]="refusal()"
                dataId="profile-email-error"
              />
            </form>
          }
          @case ('done') {
            <div class="text-center py-2" data-id="profile-email-outcome">
              <i
                class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"
                aria-hidden="true"
              ></i>
              <div class="fw-semibold mb-1">{{ copy.changed }}</div>
              <p class="small text-body-secondary mb-0">
                {{ copy.outcomeWas }}
                <s data-id="profile-email-was">{{ was() }}</s
                >, {{ copy.outcomeNow }}
                <strong data-id="profile-email-now">{{ now() }}</strong
                >. {{ copy.outcomeTail }}
              </p>
            </div>
          }
        }
      </div>
      <ng-template #modalActions let-requestClose="requestClose">
        @if (step() === 'step-up') {
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-email-cancel"
            (click)="requestClose()"
          >
            {{ copy.cancel }}
          </button>
          @if (stepUpOpening() !== null) {
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="busy()"
              data-id="profile-email-step-up-confirm"
              (click)="flow().submit()"
            >
              {{ stepUpCopy.confirm }}
            </button>
          }
        } @else if (step() !== 'done') {
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-email-cancel"
            (click)="requestClose()"
          >
            {{ copy.cancel }}
          </button>
          @switch (step()) {
            @case ('send-current') {
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="busy()"
                data-autofocus
                data-id="profile-email-send-current"
                (click)="flow().submit()"
              >
                {{ copy.sendCode }}
              </button>
            }
            @case ('confirm-current') {
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="busy()"
                [disabled]="!canSubmit()"
                data-id="profile-email-confirm-current"
                (click)="flow().submit()"
              >
                {{ copy.continue }}
              </button>
            }
            @case ('new-address') {
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="busy()"
                [disabled]="!canSubmit()"
                data-id="profile-email-send-new"
                (click)="flow().submit()"
              >
                {{ copy.sendCode }}
              </button>
            }
            @default {
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="busy()"
                [disabled]="!canSubmit()"
                data-id="profile-email-confirm-new"
                (click)="flow().submit()"
              >
                {{ copy.changeEmail }}
              </button>
            }
          }
        } @else {
          <button
            type="button"
            class="btn btn-outline-secondary"
            data-id="profile-email-again"
            (click)="flow().again()"
          >
            {{ copy.again }}
          </button>
          <button
            type="button"
            class="btn btn-primary"
            data-autofocus
            data-id="profile-email-done"
            (click)="requestClose()"
          >
            {{ copy.done }}
          </button>
        }
      </ng-template>
    </hilos-modal>
  `,
})
export class HilosProfileEmailChange {
  readonly flow = input.required<HilosProfileEmailChangeFlow>()
  protected readonly copy = HILOS_PROFILE_EMAIL_CHANGE_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  protected readonly step = signal<HilosProfileEmailChangeStep>('closed')
  protected readonly was = signal('')
  protected readonly currentCode = signal('')
  protected readonly newEmail = signal('')
  protected readonly newCode = signal('')
  protected readonly now = signal('')
  protected readonly busy = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly canSubmit = signal(false)
  protected readonly asksBeforeClosing = signal(false)
  protected readonly stepUpOpening = signal<HilosStepUpOpening | null>(null)
  protected readonly stepUpRefusal = signal<string | null>(null)
  private readonly body = viewChild<ElementRef<HTMLElement>>('body')
  private previous: HilosProfileEmailChangeStep = 'closed'
  protected readonly title = computed(() =>
    this.step() === 'step-up'
      ? this.stepUpCopy.title
      : this.step() === 'done'
        ? this.copy.doneTitle
        : this.copy.title,
  )
  /** The step's place in the list: 1 to 5, 0 on the confirmation. */
  protected readonly stepNumber = computed(
    () =>
      HILOS_PROFILE_EMAIL_CHANGE_STEPS.indexOf(
        this.step() as (typeof HILOS_PROFILE_EMAIL_CHANGE_STEPS)[number],
      ) + 1,
  )

  constructor() {
    effect((onCleanup) => {
      const flow = this.flow()
      const off = [
        mirror(flow.step, this.step),
        mirror(flow.was, this.was),
        mirror(flow.currentCode, this.currentCode),
        mirror(flow.newEmail, this.newEmail),
        mirror(flow.newCode, this.newCode),
        mirror(flow.now, this.now),
        mirror(flow.busy, this.busy),
        mirror(flow.refusal, this.refusal),
        mirror(flow.canSubmit, this.canSubmit),
        mirror(flow.asksBeforeClosing, this.asksBeforeClosing),
        mirror(flow.stepUp.opening, this.stepUpOpening),
        mirror(flow.stepUp.refusal, this.stepUpRefusal),
      ]
      onCleanup(() => off.forEach((stop) => stop()))
    })
    // The confirmation's body is replaced by step one: focus it.
    afterRenderEffect(() => {
      const step = this.step()
      const from = this.previous
      this.previous = step
      if (from !== 'step-up' || step !== 'send-current') return
      const dialog =
        this.body()?.nativeElement.closest<HTMLElement>('[role="dialog"]')
      if (dialog) focusInitial(dialog)
    })
  }
  protected sentTo(address: string): string {
    return this.copy.sentTo.replace('{address}', address)
  }
  protected onOpenChange(open: boolean): void {
    if (!open) this.flow().close()
  }
  protected valueOf(event: Event): string {
    return (event.target as HTMLInputElement).value
  }
}
