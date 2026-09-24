// HilosProfileSecurityPage — the profile's security page
// (HilosPages.PROFILE_SECURITY, /profile/security, HIL-494): two-step
// verification for the person signed in. The connected authenticator apps, each
// with Remove; the backup codes left of the set, with Show; Add an app; and "If
// you lose access" — the removal wait and the delayed removal itself. Every
// mutation is a modal (the modal-only editing rule), and every one but the first
// connection, the wait and the removal starts with a code from an app or a
// backup code: a stolen live session must not strip or copy the factor.
// The section, its live copy and the actions are the core's; this view owns only
// the markup. The page is drawn from text — the mockup's node is a debt (D-113).
// Bootstrap classes only (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
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
  createHilosSecondFactorActions,
  createHilosSecondFactorStore,
  focusInitial,
  formatCalendarDate,
  subscribeSignal,
} from '@hilos/core'
import type {
  HilosBackupCodeEntry,
  HilosSecondFactorAuthenticator,
  HilosSecondFactorContext,
  HilosSecondFactorEnrollment,
  HilosSecondFactorProof,
  HilosSecondFactorState,
} from '@hilos/core'

import { HilosActionError } from '../HilosActionError.js'
import { HilosBackupCodes } from '../HilosBackupCodes.js'
import { HilosModal } from '../HilosModal.js'
import { HilosQrCode } from '../HilosQrCode.js'
import { LoadingButton } from '../LoadingButton.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'

/** One day in ms — the unit of the removal wait. */
const DAY_MS = 86_400_000

type EnrollStep = 'name' | 'proof' | 'scan' | 'codes' | 'more'
type CodesStep = 'proof' | 'list' | 'renew' | 'new'

const ENROLL_SUBMIT: Record<EnrollStep, string> = {
  name: 'Next',
  proof: 'Next',
  scan: 'Connect',
  codes: 'Done',
  more: 'Connect another',
}

const CODES_SUBMIT: Record<CodesStep, string> = {
  proof: 'Show',
  list: 'Issue new codes',
  renew: 'Issue',
  new: 'Done',
}

/**
 * Put focus on the field of the step a modal has just moved to, or on the
 * dialog when the step has none: the field that held it is gone, and the modal
 * places focus only when it opens.
 *
 * @param form The modal's form, drawn at the new step.
 */
function focusStep(form: HTMLFormElement | undefined): void {
  const dialog = form?.closest<HTMLElement>('[role="dialog"]')
  if (dialog) {
    focusInitial(dialog)
  }
}

/** The profile's security page. */
@Component({
  selector: 'hilos-profile-security-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosActionError,
    HilosBackupCodes,
    HilosModal,
    HilosQrCode,
    LoadingButton,
    NgTemplateOutlet,
  ],
  template: `
    <ng-template #proofField let-checkboxId="checkboxId">
      <input
        type="text"
        class="form-control mb-2"
        autocomplete="one-time-code"
        aria-label="Code"
        data-id="profile-2fa-proof"
        data-autofocus
        [value]="proofCode()"
        (input)="proofCode.set(valueOf($event))"
      />
      <div class="form-check">
        <input
          [id]="checkboxId"
          class="form-check-input"
          type="checkbox"
          [checked]="proofBackup()"
          (change)="proofBackup.set(checkedOf($event))"
        />
        <label class="form-check-label small" [attr.for]="checkboxId">
          This is a backup code
        </label>
      </div>
    </ng-template>

    <section data-id="profile-security" class="mx-auto py-3">
      <h1 class="h4 mb-4">Security</h1>

      @if (state(); as section) {
        <h2 class="h6 text-uppercase text-body-secondary mb-2">
          Two-step verification
        </h2>
        @if (section.required) {
          <p class="small mb-2" data-id="profile-2fa-required">
            Your administrator requires two-step verification.
          </p>
        }
        <ul class="list-group mb-3" data-id="profile-2fa-apps">
          @for (app of section.authenticators; track app.id) {
            <li
              class="list-group-item d-flex align-items-center gap-3"
              [attr.data-id]="'profile-2fa-app-' + app.id"
            >
              <i class="bi bi-phone-vibrate fs-5" aria-hidden="true"></i>
              <div class="flex-grow-1">
                <div class="fw-semibold">{{ app.label }}</div>
                <div class="small text-body-secondary">
                  Connected {{ day(app.createdAt) }}
                </div>
                @if (removalLocked()) {
                  <div
                    class="small text-body-secondary"
                    data-id="profile-2fa-remove-locked"
                  >
                    Your administrator requires two-step verification, so the
                    last app stays.
                  </div>
                }
              </div>
              <button
                type="button"
                class="btn btn-sm btn-outline-danger"
                [disabled]="removalLocked()"
                [attr.data-id]="'profile-2fa-remove-' + app.id"
                (click)="openRemove(app)"
              >
                Remove
              </button>
            </li>
          }
          @if (factorOn()) {
            <li class="list-group-item d-flex align-items-center gap-3">
              <i class="bi bi-key fs-5" aria-hidden="true"></i>
              <div class="flex-grow-1">
                <div class="fw-semibold">Backup codes</div>
                <div
                  class="small text-body-secondary"
                  data-id="profile-2fa-codes-left"
                >
                  {{ section.backupCodesLeft }} of
                  {{ section.backupCodesTotal }} left
                </div>
              </div>
              <button
                type="button"
                class="btn btn-sm btn-outline-secondary"
                data-id="profile-2fa-codes-show"
                (click)="openCodes()"
              >
                Show
              </button>
            </li>
          } @else {
            <li
              class="list-group-item text-body-secondary small"
              data-id="profile-2fa-off"
            >
              Two-step verification is off. Connect an authenticator app to turn
              it on.
            </li>
          }
        </ul>
        <button
          type="button"
          class="btn btn-sm btn-outline-primary mb-4"
          data-id="profile-2fa-add"
          (click)="openEnroll()"
        >
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>
          Add an authenticator app
        </button>

        <h2 class="h6 text-uppercase text-body-secondary mb-2">
          If you lose access
        </h2>
        <ul class="list-group mb-3">
          <li class="list-group-item d-flex align-items-center gap-3">
            <i class="bi bi-hourglass-split fs-5" aria-hidden="true"></i>
            <div class="flex-grow-1">
              <div class="fw-semibold">Wait before removal</div>
              <div class="small text-body-secondary" data-id="profile-2fa-wait">
                {{ section.resetWait.days }} days
                @if (section.resetWait.pendingDays !== null) {
                  · {{ section.resetWait.pendingDays }} days from
                  {{ day(section.resetWait.pendingFrom ?? now()) }}
                }
              </div>
            </div>
            <button
              type="button"
              class="btn btn-sm btn-outline-secondary"
              data-id="profile-2fa-wait-edit"
              (click)="openWait()"
            >
              Change
            </button>
          </li>
        </ul>
        @if (section.reset; as reset) {
          <div
            class="alert alert-warning d-flex align-items-center gap-3"
            data-id="profile-2fa-reset-pending"
          >
            <span class="flex-grow-1">
              Two-step verification will be removed on
              <strong>{{ day(reset.effectiveAt) }}</strong
              >.
            </span>
            <button
              hilosLoadingButton
              class="btn-sm btn-outline-secondary"
              [loading]="resetCancelAction.loading()"
              [disabled]="resetCancelAction.busy()"
              data-id="profile-2fa-reset-cancel"
              (click)="cancelReset()"
            >
              Cancel
            </button>
          </div>
        } @else if (factorOn()) {
          <button
            type="button"
            class="btn btn-sm btn-outline-danger mb-3"
            data-id="profile-2fa-reset-request"
            (click)="openReset()"
          >
            Request removal
          </button>
        }
        <hilos-action-error [action]="resetCancelAction" />
        <p class="small text-body-secondary mb-0">
          While a removal waits, every channel you have is told about it, and
          any of those messages stops it. A longer wait makes the account harder
          to take — and makes you wait longer if your phone is really gone. A
          shorter wait takes effect only after the wait in force.
        </p>
      } @else {
        <div class="text-body-secondary" role="status">Loading…</div>
      }

      <hilos-modal
        [open]="enrollOpen()"
        (openChange)="enrollOpen.set($event)"
        title="Add an authenticator app"
        [confirmOnClose]="enrollStep() === 'codes' && !issuedSaved()"
        confirmTitle="Close before saving the codes?"
        confirmMessage="You have not marked these codes as saved. You can still show them later with a code from your app."
        confirmOkText="Close"
        confirmCancelText="Back to the codes"
      >
        <hilos-action-error [action]="enrollAction" />
        <form
          #enrollForm
          data-id="profile-2fa-enroll"
          (submit)="enrollSubmit($event)"
        >
          @switch (enrollStep()) {
            @case ('name') {
              <label class="form-label" for="profile-2fa-enroll-label"
                >Name of this app</label
              >
              <input
                id="profile-2fa-enroll-label"
                type="text"
                class="form-control"
                maxlength="64"
                placeholder="Authenticator app"
                data-id="profile-2fa-enroll-label"
                data-autofocus
                [value]="enrollLabel()"
                (input)="enrollLabel.set(valueOf($event))"
              />
            }
            @case ('proof') {
              <p class="small">
                Enter a code from an app you already connected, or a backup
                code.
              </p>
              <ng-container
                [ngTemplateOutlet]="proofField"
                [ngTemplateOutletContext]="{
                  checkboxId: 'profile-2fa-enroll-backup',
                }"
              />
            }
            @case ('scan') {
              @if (enrollment(); as started) {
                <p class="small">
                  Scan this code with your authenticator app, then enter the
                  code the app shows.
                </p>
                <hilos-qr-code
                  [text]="started.otpauthUri"
                  label="QR code for your authenticator app"
                  class="mb-2"
                />
                <p
                  class="font-monospace small text-center text-break"
                  data-id="profile-2fa-enroll-secret"
                >
                  {{ started.secret }}
                </p>
                <input
                  type="text"
                  inputmode="numeric"
                  class="form-control"
                  autocomplete="one-time-code"
                  aria-label="Code"
                  data-id="profile-2fa-enroll-code"
                  data-autofocus
                  [value]="enrollCode()"
                  (input)="enrollCode.set(valueOf($event))"
                />
              }
            }
            @case ('codes') {
              <p class="small">
                Keep these codes somewhere safe. Each one signs you in once if
                you lose your authenticator app.
              </p>
              <hilos-backup-codes
                [codes]="issuedCodes()"
                [saved]="issuedSaved()"
                (savedChange)="issuedSaved.set($event)"
              />
            }
            @default {
              <p class="small mb-0" data-id="profile-2fa-enroll-more">
                The app is connected. A second app is the quickest way back in
                if you lose this one — connect another now?
              </p>
            }
          }
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="enrollAction.busy()"
            (click)="requestClose()"
          >
            {{ enrollStep() === 'more' ? 'Not now' : 'Cancel' }}
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="enrollAction.loading()"
            [disabled]="enrollAction.busy() || enrollDisabled()"
            data-id="profile-2fa-enroll-submit"
            (click)="enrollSubmit()"
          >
            {{ enrollSubmitLabel() }}
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="codesOpen()"
        (openChange)="codesOpen.set($event)"
        title="Backup codes"
        initialFocus="inner"
        [confirmOnClose]="codesStep() === 'new' && !renewedSaved()"
        confirmTitle="Close before saving the codes?"
        confirmMessage="You have not marked the new codes as saved, and the old ones no longer work. You can still show them later with a code from your app."
        confirmOkText="Close"
        confirmCancelText="Back to the codes"
      >
        <hilos-action-error [action]="codesAction" />
        <form
          #codesForm
          data-id="profile-2fa-codes"
          (submit)="codesSubmit($event)"
        >
          @if (codesStep() === 'proof' || codesStep() === 'renew') {
            <p class="small">
              {{
                codesStep() === 'renew'
                  ? 'Enter the next code from your app, or a backup code. The old codes stop working.'
                  : 'Enter a code from your app, or a backup code.'
              }}
            </p>
            <ng-container
              [ngTemplateOutlet]="proofField"
              [ngTemplateOutletContext]="{
                checkboxId: 'profile-2fa-codes-backup',
              }"
            />
          } @else if (codesStep() === 'list') {
            <ul
              class="list-unstyled row row-cols-2 g-2 font-monospace mb-0"
              data-id="profile-2fa-codes-list"
            >
              @for (entry of listedCodes(); track entry.code) {
                <li class="col text-center">
                  <span
                    class="d-block border rounded py-1"
                    [class.text-decoration-line-through]="entry.used"
                    [class.text-body-secondary]="entry.used"
                    >{{ entry.code }}
                    @if (entry.used) {
                      <span class="visually-hidden"> (used)</span>
                    }
                  </span>
                </li>
              }
            </ul>
          } @else {
            <hilos-backup-codes
              [codes]="renewedCodes()"
              [saved]="renewedSaved()"
              (savedChange)="renewedSaved.set($event)"
            />
          }
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="codesAction.busy()"
            (click)="requestClose()"
          >
            Close
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="codesAction.loading()"
            [disabled]="codesAction.busy() || codesDisabled()"
            data-id="profile-2fa-codes-submit"
            (click)="codesSubmit()"
          >
            {{ codesSubmitLabel() }}
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="removeOpen()"
        (openChange)="removeOpen.set($event)"
        [title]="removeTitle()"
        initialFocus="inner"
      >
        <hilos-action-error [action]="removeAction" />
        <form data-id="profile-2fa-remove" (submit)="removeSubmit($event)">
          @if (apps().length === 1) {
            <p class="small" data-id="profile-2fa-remove-last">
              This is your last app: two-step verification turns off, your
              backup codes and trusted devices stop working, and a removal that
              waits is dropped.
            </p>
          }
          <p class="small">Enter a code from your app, or a backup code.</p>
          <ng-container
            [ngTemplateOutlet]="proofField"
            [ngTemplateOutletContext]="{
              checkboxId: 'profile-2fa-remove-backup',
            }"
          />
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="removeAction.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="removeAction.loading()"
            [disabled]="removeAction.busy() || removeDisabled()"
            data-id="profile-2fa-remove-submit"
            (click)="removeSubmit()"
          >
            Remove
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="waitOpen()"
        (openChange)="waitOpen.set($event)"
        title="Wait before removal"
      >
        <hilos-action-error [action]="waitAction" />
        <form data-id="profile-2fa-wait-form" (submit)="waitSubmit($event)">
          <label class="form-label" for="profile-2fa-wait-days">Days</label>
          <input
            id="profile-2fa-wait-days"
            type="number"
            inputmode="numeric"
            class="form-control"
            [attr.min]="state()?.resetWait?.minDays"
            [attr.max]="state()?.resetWait?.maxDays"
            data-id="profile-2fa-wait-days"
            data-autofocus
            [value]="waitDays()"
            (input)="waitDays.set(valueOf($event))"
          />
          <p class="form-text mb-0">
            From {{ state()?.resetWait?.minDays }} to
            {{ state()?.resetWait?.maxDays }} days.
            @if (waitTakesEffect(); as moment) {
              A shorter wait takes effect on {{ day(moment) }}.
            }
          </p>
        </form>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="waitAction.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-primary"
            [loading]="waitAction.loading()"
            [disabled]="waitAction.busy() || !waitChanged()"
            data-id="profile-2fa-wait-save"
            (click)="waitSubmit()"
          >
            Save
          </button>
        </ng-template>
      </hilos-modal>

      <hilos-modal
        [open]="resetOpen()"
        (openChange)="resetOpen.set($event)"
        title="Request removal"
        initialFocus="dialog"
      >
        <hilos-action-error [action]="resetAction" />
        <p class="small" data-id="profile-2fa-reset-date">
          Two-step verification will be removed on
          <strong>{{ resetDate() }}</strong
          >.
        </p>
        <p class="small mb-0">
          We tell you at once and then every day, on every channel you have —
          email, text message, push and the bell in the app — and each message
          lets you cancel.
        </p>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="resetAction.busy()"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn-danger"
            [loading]="resetAction.loading()"
            [disabled]="resetAction.busy()"
            data-id="profile-2fa-reset-submit"
            (click)="resetSubmit()"
          >
            Request removal
          </button>
        </ng-template>
      </hilos-modal>
    </section>
  `,
})
export class HilosProfileSecurityPage {
  /** The project context: connection, scope stores, and the action lifecycle. */
  readonly context = input.required<HilosSecondFactorContext>()

  private readonly store = computed(() =>
    createHilosSecondFactorStore(this.context()),
  )
  private readonly actions = computed(() =>
    createHilosSecondFactorActions(this.context()),
  )

  protected readonly state = signal<HilosSecondFactorState | null>(null)
  protected readonly apps = computed(() => this.state()?.authenticators ?? [])
  protected readonly factorOn = computed(() => this.apps().length > 0)
  protected readonly removalLocked = computed(
    () => (this.state()?.required ?? false) && this.apps().length === 1,
  )

  // The proof a modal starts with.
  protected readonly proofCode = signal('')
  protected readonly proofBackup = signal(false)

  // Connect an app.
  protected readonly enrollOpen = signal(false)
  protected readonly enrollStep = signal<EnrollStep>('name')
  protected readonly enrollLabel = signal('')
  protected readonly enrollCode = signal('')
  protected readonly enrollment = signal<HilosSecondFactorEnrollment | null>(
    null,
  )
  protected readonly issuedCodes = signal<readonly string[]>([])
  protected readonly issuedSaved = signal(false)
  protected readonly enrollAction = createHilosTrackedAction()
  private readonly enrollForm =
    viewChild<ElementRef<HTMLFormElement>>('enrollForm')
  protected readonly enrollSubmitLabel = computed(
    () => ENROLL_SUBMIT[this.enrollStep()],
  )
  protected readonly enrollDisabled = computed(() => {
    switch (this.enrollStep()) {
      case 'proof':
        return this.proofCode().trim() === ''
      case 'scan':
        return this.enrollCode().trim() === ''
      case 'codes':
        return !this.issuedSaved()
      default:
        return false
    }
  })

  // Show the backup codes, and issue a new set.
  protected readonly codesOpen = signal(false)
  protected readonly codesStep = signal<CodesStep>('proof')
  protected readonly listedCodes = signal<readonly HilosBackupCodeEntry[]>([])
  protected readonly renewedCodes = signal<readonly string[]>([])
  protected readonly renewedSaved = signal(false)
  protected readonly codesAction = createHilosTrackedAction()
  private readonly codesForm =
    viewChild<ElementRef<HTMLFormElement>>('codesForm')
  protected readonly codesSubmitLabel = computed(
    () => CODES_SUBMIT[this.codesStep()],
  )
  protected readonly codesDisabled = computed(() =>
    this.codesStep() === 'new'
      ? !this.renewedSaved()
      : this.codesStep() !== 'list' && this.proofCode().trim() === '',
  )

  // Remove an app.
  protected readonly removeOpen = signal(false)
  protected readonly removeTarget =
    signal<HilosSecondFactorAuthenticator | null>(null)
  protected readonly removeAction = createHilosTrackedAction()
  protected readonly removeDisabled = computed(
    () => this.proofCode().trim() === '',
  )
  protected readonly removeTitle = computed(() => {
    const target = this.removeTarget()

    return target ? `Remove ${target.label}` : 'Remove app'
  })

  // The removal wait.
  protected readonly waitOpen = signal(false)
  protected readonly waitDays = signal('')
  protected readonly waitAction = createHilosTrackedAction()
  private readonly currentWait = computed(
    () =>
      this.state()?.resetWait.pendingDays ?? this.state()?.resetWait.days ?? 0,
  )
  private readonly waitChosen = computed(() =>
    Number.parseInt(this.waitDays(), 10),
  )
  protected readonly waitChanged = computed(
    () =>
      !Number.isNaN(this.waitChosen()) &&
      this.waitChosen() !== this.currentWait(),
  )
  /** A shorter wait waits out the wait in force first; the modal names that day. */
  protected readonly waitTakesEffect = computed(() => {
    const wait = this.state()?.resetWait
    const chosen = this.waitChosen()
    if (wait === undefined || Number.isNaN(chosen) || chosen >= wait.days) {
      return null
    }

    return Date.now() + wait.days * DAY_MS
  })

  // The delayed removal.
  protected readonly resetOpen = signal(false)
  protected readonly resetAction = createHilosTrackedAction()
  protected readonly resetCancelAction = createHilosTrackedAction()
  protected readonly resetDate = computed(() =>
    formatCalendarDate(
      Date.now() + (this.state()?.resetWait.days ?? 0) * DAY_MS,
    ),
  )

  constructor() {
    // Take the section once the context input is bound; let it go on destroy.
    effect((onCleanup) => {
      const store = this.store()
      store.start()
      this.state.set(store.state.get())
      const off = subscribeSignal(store.state, (next) => this.state.set(next))
      onCleanup(() => {
        off()
        store.dispose()
      })
    })
    // The step a modal moves to takes the focus once it is drawn.
    afterRenderEffect(() => {
      this.enrollStep()
      focusStep(this.enrollForm()?.nativeElement)
    })
    afterRenderEffect(() => {
      this.codesStep()
      focusStep(this.codesForm()?.nativeElement)
    })
  }

  /**
   * A moment as the day it falls on.
   *
   * @param moment The LOCAL epoch-ms moment.
   */
  protected day(moment: number): string {
    return formatCalendarDate(moment)
  }

  /** This moment, for a pending wait that names no day of its own. */
  protected now(): number {
    return Date.now()
  }

  /**
   * The text an input event carries.
   *
   * @param event The input event.
   */
  protected valueOf(event: Event): string {
    return (event.target as HTMLInputElement).value
  }

  /**
   * The state a checkbox event carries.
   *
   * @param event The change event.
   */
  protected checkedOf(event: Event): boolean {
    return (event.target as HTMLInputElement).checked
  }

  private resetProof(): void {
    this.proofCode.set('')
    this.proofBackup.set(false)
  }

  private proof(): HilosSecondFactorProof {
    return { code: this.proofCode().trim(), backupCode: this.proofBackup() }
  }

  protected openEnroll(): void {
    this.enrollAction.clearError()
    this.resetProof()
    this.enrollLabel.set('')
    this.enrollCode.set('')
    this.enrollment.set(null)
    this.issuedCodes.set([])
    this.issuedSaved.set(false)
    this.enrollStep.set('name')
    this.enrollOpen.set(true)
  }

  protected async enrollSubmit(event?: Event): Promise<void> {
    event?.preventDefault()
    // Enter submits the form past the disabled button, so the guard is here too.
    if (this.enrollAction.busy() || this.enrollDisabled()) {
      return
    }
    const step = this.enrollStep()
    if (step === 'name' && this.factorOn()) {
      this.enrollStep.set('proof')

      return
    }
    if (step === 'name' || step === 'proof') {
      const handle = this.actions().enrollStart(
        this.factorOn() ? this.proof() : null,
      )
      if (await this.enrollAction.run(handle)) {
        this.enrollment.set((await handle.done).reply ?? null)
        this.enrollStep.set('scan')
      }

      return
    }
    const started = this.enrollment()
    if (step === 'scan' && started !== null) {
      const handle = this.actions().enrollConfirm(
        started.authenticatorId,
        this.enrollCode().trim(),
        this.enrollLabel().trim(),
      )
      if (await this.enrollAction.run(handle)) {
        const codes = (await handle.done).reply?.backupCodes
        if (codes !== undefined) {
          this.issuedCodes.set(codes)
          this.enrollStep.set('codes')
        } else {
          this.enrollStep.set('more')
        }
      }

      return
    }
    if (step === 'codes') {
      this.enrollStep.set('more')

      return
    }
    this.openEnroll()
  }

  protected openCodes(): void {
    this.codesAction.clearError()
    this.resetProof()
    this.listedCodes.set([])
    this.renewedCodes.set([])
    this.renewedSaved.set(false)
    this.codesStep.set('proof')
    this.codesOpen.set(true)
  }

  protected async codesSubmit(event?: Event): Promise<void> {
    event?.preventDefault()
    if (this.codesAction.busy() || this.codesDisabled()) {
      return
    }
    const step = this.codesStep()
    if (step === 'list') {
      // A new set asks for the NEXT code: the one that opened the list is spent.
      this.resetProof()
      this.codesAction.clearError()
      this.codesStep.set('renew')

      return
    }
    if (step === 'new') {
      this.codesOpen.set(false)

      return
    }
    if (step === 'proof') {
      const handle = this.actions().showCodes(this.proof())
      if (await this.codesAction.run(handle)) {
        this.listedCodes.set((await handle.done).reply?.codes ?? [])
        this.codesStep.set('list')
      }

      return
    }
    const handle = this.actions().renewCodes(this.proof())
    if (await this.codesAction.run(handle)) {
      this.renewedCodes.set((await handle.done).reply?.backupCodes ?? [])
      this.codesStep.set('new')
    }
  }

  protected openRemove(app: HilosSecondFactorAuthenticator): void {
    this.removeAction.clearError()
    this.resetProof()
    this.removeTarget.set(app)
    this.removeOpen.set(true)
  }

  protected async removeSubmit(event?: Event): Promise<void> {
    event?.preventDefault()
    const target = this.removeTarget()
    if (target === null || this.removeAction.busy() || this.removeDisabled()) {
      return
    }
    if (
      await this.removeAction.run(
        this.actions().remove(target.id, this.proof()),
      )
    ) {
      this.removeOpen.set(false)
    }
  }

  protected openWait(): void {
    this.waitAction.clearError()
    this.waitDays.set(String(this.currentWait()))
    this.waitOpen.set(true)
  }

  protected async waitSubmit(event?: Event): Promise<void> {
    event?.preventDefault()
    const chosen = this.waitChosen()
    if (this.waitAction.busy() || !this.waitChanged()) {
      return
    }
    if (await this.waitAction.run(this.actions().setResetWait(chosen))) {
      this.waitOpen.set(false)
    }
  }

  protected openReset(): void {
    this.resetAction.clearError()
    this.resetOpen.set(true)
  }

  protected async resetSubmit(): Promise<void> {
    if (this.resetAction.busy()) {
      return
    }
    if (await this.resetAction.run(this.actions().requestReset())) {
      this.resetOpen.set(false)
    }
  }

  protected cancelReset(): void {
    void this.resetCancelAction.run(this.actions().cancelReset())
  }
}
