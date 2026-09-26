// HilosAccountDeletion — the danger zone at the bottom of a profile page and its
// one window (HIL-302). Without a scheduled deletion the zone offers "Delete my
// account…", and the window walks the operation's confirmation (when asked),
// step 1 "what will happen" and step 2 "the code"; with one, the zone is a
// warning with the date and the days left, and the window is "Deletion in
// progress" with a plain "Keep my account". Every open tab follows the person's
// state. The state, the actions and the window's steps are the core's
// (createHilosAccountDeletionStore / createHilosAccountDeletionFlow); this view
// owns only the markup. Bootstrap classes only (styling-rules.md).
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
  ACCOUNT_DELETION_TICK_MS,
  accountDeletionDaysLeft,
  createHilosAccountDeletionFlow,
  createHilosAccountDeletionStore,
  focusInitial,
  formatAccountDeletionDays,
  formatCalendarDate,
  HILOS_ACCOUNT_DELETION_COPY,
  HILOS_STEP_UP_COPY,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosAccountDeletionOpening,
  HilosAccountDeletionState,
  HilosAccountDeletionStep,
  HilosSecondFactorContext,
} from '@hilos/core'

import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'

@Component({
  selector: 'hilos-account-deletion',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, HilosModal, HilosStepUpStep, LoadingButton],
  template: `
    <section class="mt-5" data-id="account-deletion">
      @if (deletion() !== null) {
        <div
          class="alert alert-warning d-flex flex-wrap align-items-center gap-3"
          data-id="account-deletion-scheduled"
        >
          <div class="flex-grow-1">
            <div class="fw-semibold small mb-1">
              <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i
              >{{ copy.scheduledTitle }}
            </div>
            <p class="small mb-0">{{ fill(copy.scheduledText) }}</p>
          </div>
          <button
            type="button"
            class="btn btn-sm btn-outline-secondary"
            data-id="account-deletion-manage"
            (click)="flow().open()"
          >
            {{ copy.scheduledButton }}
          </button>
        </div>
      } @else {
        <div class="border border-danger-subtle rounded bg-danger-subtle p-3">
          <div class="fw-semibold small mb-1">
            <i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i
            >{{ copy.zoneTitle }}
          </div>
          <p class="small text-body-secondary mb-3">{{ copy.zoneText }}</p>
          <button
            hilosLoadingButton
            class="btn-sm btn-outline-danger"
            [loading]="step() === 'opening'"
            data-id="account-deletion-open"
            (click)="flow().open()"
          >
            {{ copy.zoneButton }}
          </button>
        </div>
      }

      <hilos-modal
        [open]="open()"
        (openChange)="onOpenChange($event)"
        [title]="step() === 'in-progress' ? copy.progressTitle : copy.title"
        initialFocus="inner"
      >
        <div
          class="visually-hidden"
          role="alert"
          aria-live="assertive"
          data-id="account-deletion-live"
        >
          {{ step() === 'step-up' ? stepUpRefusal() : refusal() }}
        </div>
        <div #body data-id="account-deletion-modal">
          @if (step() === 'step-up') {
            <hilos-step-up-step [controller]="flow().stepUp" />
          } @else if (step() === 'in-progress' && deletion()) {
            <div class="text-center py-2">
              <i
                class="bi bi-hourglass-split text-danger d-block mb-2 fs-2"
                aria-hidden="true"
              ></i>
              <div class="fw-semibold mb-1">{{ fill(copy.progressLead) }}</div>
              <p class="small text-body-secondary mb-0">
                {{ fill(copy.progressText) }}
              </p>
            </div>
          } @else if (step() === 'explain' || step() === 'code') {
            <ol
              class="list-unstyled d-flex flex-column gap-1 mb-3 small"
              data-id="account-deletion-steps"
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
                  >
                  <span>{{ label }}</span>
                </li>
              }
            </ol>
            @if (step() === 'explain') {
              <div class="alert alert-danger small py-2 mb-3">
                <ul class="mb-0 ps-3">
                  <li>{{ fill(copy.explainErase) }}</li>
                  <li>{{ copy.explainProviders }}</li>
                  <li>{{ copy.explainSubscriptions }}</li>
                </ul>
              </div>
              <p class="small text-body-secondary mb-0">
                {{ copy.explainChangeMind }}
              </p>
            } @else {
              <form (submit)="$event.preventDefault(); flow().start()">
                <p class="small text-body-secondary mb-3">
                  {{ fill(copy.codeSent) }}
                </p>
                <label class="form-label" for="hilos-account-deletion-code">{{
                  copy.codeLabel
                }}</label>
                <input
                  id="hilos-account-deletion-code"
                  class="form-control"
                  autocomplete="one-time-code"
                  inputmode="numeric"
                  data-id="account-deletion-code"
                  data-autofocus
                  [value]="code()"
                  (input)="setCode($event)"
                />
              </form>
            }
          }
          @if (step() !== 'step-up') {
            <hilos-form-error
              [message]="refusal()"
              dataId="account-deletion-error"
            />
          }
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          @if (step() === 'in-progress') {
            <button
              type="button"
              class="btn btn-outline-secondary"
              data-id="account-deletion-close"
              (click)="requestClose()"
            >
              {{ copy.close }}
            </button>
            <button
              hilosLoadingButton
              class="btn-primary"
              [loading]="busy()"
              data-id="account-deletion-keep"
              (click)="flow().cancel()"
            >
              {{ copy.keep }}
            </button>
          } @else {
            <button
              type="button"
              class="btn btn-outline-secondary"
              data-id="account-deletion-cancel"
              (click)="requestClose()"
            >
              {{ copy.cancel }}
            </button>
            @if (step() === 'step-up' && stepUpOpening() !== null) {
              <button
                hilosLoadingButton
                class="btn-primary"
                [loading]="busy()"
                data-id="account-deletion-confirm"
                (click)="flow().confirmStepUp()"
              >
                {{ stepUpCopy.confirm }}
              </button>
            } @else if (step() === 'explain') {
              <button
                hilosLoadingButton
                class="btn-danger"
                [loading]="busy()"
                data-autofocus
                data-id="account-deletion-continue"
                (click)="flow().next()"
              >
                {{ opening()?.channel == null ? copy.start : copy.continue }}
              </button>
            } @else if (step() === 'code') {
              <button
                hilosLoadingButton
                class="btn-danger"
                [loading]="busy()"
                data-id="account-deletion-start"
                (click)="flow().start()"
              >
                {{ copy.start }}
              </button>
            }
          }
        </ng-template>
      </hilos-modal>
    </section>
  `,
})
export class HilosAccountDeletion {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosSecondFactorContext>()

  protected readonly copy = HILOS_ACCOUNT_DELETION_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  private readonly store = computed(() =>
    createHilosAccountDeletionStore(this.context()),
  )
  protected readonly flow = computed(() =>
    createHilosAccountDeletionFlow(this.context(), this.store()),
  )
  protected readonly state = signal<HilosAccountDeletionState | null>(null)
  protected readonly step = signal<HilosAccountDeletionStep>('closed')
  protected readonly opening = signal<HilosAccountDeletionOpening | null>(null)
  protected readonly code = signal('')
  protected readonly busy = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly stepUpOpening = signal<unknown>(null)
  protected readonly stepUpRefusal = signal<string | null>(null)
  private readonly now = signal(Date.now())
  private readonly body = viewChild<ElementRef<HTMLElement>>('body')

  protected readonly deletion = computed(() => this.state()?.deletion ?? null)
  protected readonly open = computed(
    () => this.step() !== 'closed' && this.step() !== 'opening',
  )
  protected readonly stepNumber = computed(() =>
    this.step() === 'code' ? 2 : 1,
  )
  private readonly daysLeft = computed(() => {
    const deletion = this.deletion()
    return deletion === null
      ? ''
      : formatAccountDeletionDays(
          accountDeletionDaysLeft(deletion.effectiveAt, this.now()),
        )
  })

  constructor() {
    // Follow the state and the window once the context is bound; let them go on destroy.
    effect((onCleanup) => {
      const store = this.store()
      const flow = this.flow()
      store.start()
      flow.follow()
      this.state.set(store.state.get())
      const off = [
        subscribeSignal(store.state, (value) => this.state.set(value)),
        subscribeSignal(flow.step, (value) => this.step.set(value)),
        subscribeSignal(flow.opening, (value) => this.opening.set(value)),
        subscribeSignal(flow.code, (value) => this.code.set(value)),
        subscribeSignal(flow.busy, (value) => this.busy.set(value)),
        subscribeSignal(flow.refusal, (value) => this.refusal.set(value)),
        subscribeSignal(flow.stepUp.opening, (value) =>
          this.stepUpOpening.set(value),
        ),
        subscribeSignal(flow.stepUp.refusal, (value) =>
          this.stepUpRefusal.set(value),
        ),
      ]
      const tick = setInterval(
        () => this.now.set(Date.now()),
        ACCOUNT_DELETION_TICK_MS,
      )
      onCleanup(() => {
        clearInterval(tick)
        off.forEach((unsubscribe) => unsubscribe())
        flow.dispose()
        store.dispose()
      })
    })
    // The step the window moves to takes the focus - the code field on step 2 -
    // since the window stays and only its content changes.
    afterRenderEffect(() => {
      this.step()
      const dialog =
        this.body()?.nativeElement.closest<HTMLElement>('[role="dialog"]')
      if (dialog) {
        focusInitial(dialog)
      }
    })
  }

  /**
   * Close the window when the modal asks to.
   *
   * @param value Whether the modal stays open.
   */
  protected onOpenChange(value: boolean): void {
    if (!value) {
      this.flow().close()
    }
  }

  /**
   * Keep the typed code in the window.
   *
   * @param event The input event of the code field.
   */
  protected setCode(event: Event): void {
    this.flow().code.set((event.target as HTMLInputElement).value)
  }

  /**
   * A copy line with its places filled.
   *
   * @param template The line, with its `{…}` places.
   */
  protected fill(template: string): string {
    const deletion = this.deletion()
    const opening = this.opening()
    return template
      .replace(
        '{graceDays}',
        formatAccountDeletionDays(opening?.graceDays ?? 0),
      )
      .replace('{destination}', opening?.destination ?? '')
      .replace('{days}', this.daysLeft())
      .replace(
        '{date}',
        deletion === null ? '' : formatCalendarDate(deletion.effectiveAt),
      )
      .replace(
        '{requestedDate}',
        deletion === null ? '' : formatCalendarDate(deletion.requestedAt),
      )
  }
}
