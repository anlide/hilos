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
  createHilosProfileAddSignInFlow,
  createHilosProfilePasswordChangeFlow,
  createHilosProfileSignInMethods,
  createHilosProfileSignInActions,
  focusInitial,
  hilosProfileAddableWays,
  hilosProfileSignInSubtitle,
  hilosProfileSignInTitle,
  HILOS_PROFILE_SIGN_IN_COPY,
  HILOS_STEP_UP_COPY,
  hilosToasts,
  isHilosProfilePasskeyOnly,
  isPasskeySupported,
  PROFILE_PASSWORD_MODE_ADDED,
  sessionAuthMethods,
  subscribeSignal,
  watchHilosProfilePasswordUpdated,
  type AuthMethodEntry,
  type HilosAuthContext,
  type HilosProfileAddSignInStep,
  type HilosProfileAddableWay,
  type HilosProfilePasswordChangeStep,
  type HilosProfileSignInMethod,
} from '@hilos/core'
import { HilosProfilePasswordChange } from './HilosProfilePasswordChange.js'
import { HilosStepUpStep } from '../auth/HilosStepUpStep.js'
import { HilosActionError } from '../HilosActionError.js'
import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { HilosPageHeading } from '../HilosPageHeading.js'
import { LoadingButton } from '../LoadingButton.js'
import { createHilosTrackedAction } from '../hilosTrackedAction.js'

const PASSWORD_MIN = 8
const emptyDraft = () => ({
  email: '',
  phone: '',
  code: '',
  newPassword: '',
  confirm: '',
})
let signInSequence = 0

/** The account's methods and the dialogs that add, change and remove them. */
@Component({
  selector: 'hilos-profile-sign-in-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    HilosProfilePasswordChange,
    HilosActionError,
    HilosFormError,
    HilosModal,
    HilosPageHeading,
    HilosStepUpStep,
    LoadingButton,
  ],
  template: `
    <section data-id="profile-sign-in-view">
      <hilos-page-heading />
      @if (passkeyOnly()) {
        <div
          class="alert alert-warning d-flex gap-2"
          data-id="profile-sign-in-passkey-only"
        >
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
          <div>
            <div class="fw-semibold">{{ copy.passkeyOnlyTitle }}</div>
            <p class="mb-2">{{ copy.passkeyOnlyReason }}</p>
            @if (passkeyOnlyWays().length > 0) {
              <p class="mb-2">{{ copy.passkeyOnlyAdd }}</p>
              <div class="d-flex flex-wrap gap-2">
                @for (way of passkeyOnlyWays(); track wayKey(way)) {
                  <button
                    hilosLoadingButton
                    class="btn btn-sm btn-outline-secondary"
                    type="button"
                    [loading]="
                      step() === 'opening' && addPressed() === wayKey(way)
                    "
                    [disabled]="step() === 'opening'"
                    [attr.data-id]="passkeyOnlyWayId(way)"
                    (click)="openAdd(way)"
                  >
                    {{ passkeyOnlyWayLabel(way) }}
                  </button>
                }
              </div>
            } @else {
              <p class="mb-0" data-id="profile-sign-in-passkey-only-none">
                {{ copy.passkeyOnlyNone }}
              </p>
            }
          </div>
        </div>
      }
      @if (methods().length === 0) {
        <p class="text-body-secondary">No ways to sign in.</p>
      }
      <div data-id="profile-identities-list">
        @for (method of methods(); track method.key) {
          <div
            class="d-flex flex-wrap align-items-center gap-3 py-3 border-bottom"
            data-id="profile-identity-item"
            [attr.data-identity-key]="method.key"
          >
            <div class="flex-grow-1 text-break">
              <div class="fw-semibold" data-id="identity-type">
                {{ title(method) }}
              </div>
              <div
                class="small text-body-secondary"
                [attr.data-id]="
                  method.type === 'passkey'
                    ? 'identity-passkey-added'
                    : 'identity-identifier'
                "
              >
                {{ subtitle(method) }}
              </div>
              @if (method.verified) {
                <span class="badge text-bg-success" data-id="identity-verified"
                  >Verified</span
                >
              } @else {
                <span
                  class="badge text-bg-secondary"
                  data-id="identity-unverified"
                  >Unverified</span
                >
              }
              @if (!method.canUnlink) {
                <div
                  class="small text-warning-emphasis"
                  data-id="identity-unlink-blocked"
                >
                  {{ copy.onlyMethod }}
                </div>
              }
            </div>
            <div class="d-flex gap-2">
              @if (method.type === 'password') {
                <button
                  class="btn btn-sm btn-outline-secondary"
                  type="button"
                  hilosLoadingButton
                  [loading]="passwordStep() === 'opening'"
                  data-id="profile-password-change"
                  (click)="passwordFlow().open()"
                >
                  Change
                </button>
              }
              <button
                class="btn btn-sm btn-outline-secondary"
                type="button"
                [disabled]="!method.canUnlink"
                data-id="identity-unlink"
                (click)="askUnlink(method)"
              >
                {{ method.type === 'oauth' ? 'Unlink' : 'Remove' }}
              </button>
            </div>
          </div>
        }
      </div>
      <button
        hilosLoadingButton
        class="btn btn-sm btn-outline-primary mt-3"
        type="button"
        [loading]="step() === 'opening' && addPressed() === 'add'"
        [disabled]="step() === 'opening'"
        data-id="profile-sign-in-add"
        (click)="openAdd()"
      >
        Add a way to sign in
      </button>
      <hilos-modal
        [open]="unlinkKey() !== null"
        (openChange)="closeUnlink($event)"
        [title]="unlinkTitle()"
        initialFocus="dialog"
        [closeOnEsc]="!unlinkAction.busy()"
        [closeOnBackdrop]="!unlinkAction.busy()"
      >
        <div data-id="profile-unlink-modal">
          <p>
            You will no longer be able to sign in with {{ unlinkName() }}. You
            can still use: {{ remaining() }}.
          </p>
          <div data-id="profile-unlink-error">
            <hilos-action-error
              [action]="unlinkAction"
              detailsTitle="Couldn't remove the sign-in method"
            />
          </div>
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            [disabled]="unlinkAction.busy()"
            data-id="identity-unlink-cancel"
            (click)="requestClose()"
          >
            Cancel
          </button>
          <button
            hilosLoadingButton
            class="btn btn-danger"
            [loading]="unlinkAction.loading()"
            [disabled]="unlinkAction.busy()"
            data-id="identity-unlink-yes"
            (click)="remove()"
          >
            Remove
          </button>
        </ng-template>
      </hilos-modal>
      <hilos-profile-password-change [flow]="passwordFlow()" />

      <hilos-modal
        [open]="step() !== 'closed' && step() !== 'opening'"
        (openChange)="closeAdd($event)"
        [title]="addTitle()"
        initialFocus="dialog"
        [confirmOnClose]="addDirty()"
      >
        <div #addBody data-id="profile-sign-in-add-modal">
          <div
            class="visually-hidden"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
            data-id="profile-sign-in-add-live"
          >
            {{ step() === 'step-up' ? stepUpRefusal() : refusal() }}
          </div>
          <div class="hilos-stack">
            <div class="invisible" aria-hidden="true" inert>
              @for (
                label of ['Code', 'New password', 'Confirm new password'];
                track label
              ) {
                <div class="mb-3">
                  <div class="form-label">{{ label }}</div>
                  <div class="form-control">&nbsp;</div>
                </div>
              }
              <div class="form-text">{{ copy.passwordHint }}</div>
            </div>
            <div
              class="invisible d-flex flex-column gap-2"
              aria-hidden="true"
              inert
            >
              @for (way of ways(); track wayKey(way)) {
                @if (way.kind === 'password') {
                  <span class="btn btn-outline-secondary"
                    ><span class="d-flex align-items-center gap-3 text-start"
                      ><i class="bi bi-lock fs-5" aria-hidden="true"></i
                      ><span
                        ><span class="d-block fw-semibold small">Password</span
                        ><span class="d-block small text-body-secondary">{{
                          copy.passwordDescription
                        }}</span></span
                      ></span
                    ></span
                  >
                } @else if (way.kind === 'phone') {
                  <span class="btn btn-outline-secondary"
                    ><span class="d-flex align-items-center gap-3 text-start"
                      ><i class="bi bi-phone fs-5" aria-hidden="true"></i
                      ><span
                        ><span class="d-block fw-semibold small">Phone</span
                        ><span class="d-block small text-body-secondary">{{
                          copy.phoneDescription
                        }}</span></span
                      ></span
                    ></span
                  >
                } @else if (way.kind === 'provider') {
                  <span class="btn btn-outline-secondary"
                    ><span class="d-flex align-items-center gap-3 text-start"
                      ><i
                        class="bi bi-box-arrow-in-right fs-5"
                        aria-hidden="true"
                      ></i
                      ><span
                        ><span class="d-block fw-semibold small">{{
                          way.label
                        }}</span
                        ><span class="d-block small text-body-secondary">{{
                          copy.providerDescription
                        }}</span></span
                      ></span
                    ></span
                  >
                } @else {
                  <span class="btn btn-outline-secondary"
                    ><span class="d-flex align-items-center gap-3 text-start"
                      ><i class="bi bi-fingerprint fs-5" aria-hidden="true"></i
                      ><span
                        ><span class="d-block fw-semibold small">Passkey</span
                        ><span class="d-block small text-body-secondary">{{
                          copy.passkeyDescription
                        }}</span></span
                      ></span
                    ></span
                  >
                }
              }
            </div>
            @if (step() === 'step-up') {
              <form
                [id]="baseId + '-step-up'"
                class="align-self-start"
                data-id="profile-sign-in-add-step-up"
                (submit)="$event.preventDefault(); flow().confirmStepUp()"
              >
                <hilos-step-up-step [controller]="flow().stepUp" />
              </form>
            } @else if (step() === 'refused') {
              <div class="align-self-start"></div>
            } @else if (step() === 'choose') {
              <div class="d-flex flex-column gap-2 align-self-start">
                @for (way of ways(); track wayKey(way)) {
                  @if (way.kind === 'password') {
                    <button
                      type="button"
                      class="btn btn-outline-secondary"
                      [disabled]="busy()"
                      data-id="profile-sign-in-choose-password"
                      (click)="flow().choosePassword()"
                    >
                      <span class="d-flex align-items-center gap-3 text-start"
                        ><i class="bi bi-lock fs-5" aria-hidden="true"></i
                        ><span
                          ><span class="d-block fw-semibold small"
                            >Password</span
                          ><span class="d-block small text-body-secondary">{{
                            copy.passwordDescription
                          }}</span></span
                        ></span
                      >
                    </button>
                  } @else if (way.kind === 'phone') {
                    <button
                      type="button"
                      class="btn btn-outline-secondary"
                      [disabled]="busy()"
                      data-id="profile-sign-in-choose-phone"
                      (click)="flow().choosePhone()"
                    >
                      <span class="d-flex align-items-center gap-3 text-start"
                        ><i class="bi bi-phone fs-5" aria-hidden="true"></i
                        ><span
                          ><span class="d-block fw-semibold small">Phone</span
                          ><span class="d-block small text-body-secondary">{{
                            copy.phoneDescription
                          }}</span></span
                        ></span
                      >
                    </button>
                  } @else if (way.kind === 'provider') {
                    <button
                      hilosLoadingButton
                      class="btn btn-outline-secondary"
                      [loading]="busy() && pendingProvider() === way.key"
                      [disabled]="busy()"
                      [attr.data-id]="'profile-oauth-link-' + way.key"
                      (click)="flow().chooseProvider(way.key)"
                    >
                      <span class="d-flex align-items-center gap-3 text-start"
                        ><i
                          class="bi bi-box-arrow-in-right fs-5"
                          aria-hidden="true"
                        ></i
                        ><span
                          ><span class="d-block fw-semibold small">{{
                            way.label
                          }}</span
                          ><span class="d-block small text-body-secondary">{{
                            copy.providerDescription
                          }}</span></span
                        ></span
                      >
                    </button>
                  } @else {
                    <button
                      hilosLoadingButton
                      class="btn btn-outline-secondary"
                      [loading]="busy() && pendingProvider() === null"
                      [disabled]="busy()"
                      data-id="profile-passkey-add"
                      (click)="flow().choosePasskey()"
                    >
                      <span class="d-flex align-items-center gap-3 text-start"
                        ><i
                          class="bi bi-fingerprint fs-5"
                          aria-hidden="true"
                        ></i
                        ><span
                          ><span class="d-block fw-semibold small">Passkey</span
                          ><span class="d-block small text-body-secondary">{{
                            copy.passkeyDescription
                          }}</span></span
                        ></span
                      >
                    </button>
                  }
                }
              </div>
            } @else {
              <form
                [id]="baseId + '-add'"
                class="align-self-start"
                (submit)="$event.preventDefault(); submitAdd()"
              >
                @for (field of fields(); track field.key; let first = $first) {
                  <div class="mb-3">
                    <label
                      [attr.for]="baseId + '-add-' + field.key"
                      class="form-label"
                      >{{ field.label }}</label
                    >
                    <input
                      [id]="baseId + '-add-' + field.key"
                      [value]="draft()[field.key]"
                      (input)="setDraftField(field.key, valueOf($event))"
                      [type]="field.type"
                      [attr.autocomplete]="field.autocomplete"
                      class="form-control"
                      [attr.data-id]="field.dataId"
                      [attr.aria-describedby]="
                        field.type === 'password'
                          ? baseId + '-add-password-hint'
                          : null
                      "
                      [attr.data-autofocus]="first ? '' : null"
                    />
                  </div>
                }
                @if (step() === 'password-new' || step() === 'password-code') {
                  <div
                    [id]="baseId + '-add-password-hint'"
                    class="form-text"
                    data-id="profile-add-password-hint"
                  >
                    {{ copy.passwordHint }}
                  </div>
                }
              </form>
            }
          </div>
          <hilos-form-error [message]="refusal()" [dataId]="errorId()" />
        </div>
        <ng-template #modalActions let-requestClose="requestClose">
          <button
            type="button"
            class="btn btn-secondary"
            data-id="profile-sign-in-add-cancel"
            (click)="requestClose()"
          >
            Cancel
          </button>
          @if (step() === 'step-up') {
            <button
              hilosLoadingButton
              type="submit"
              [attr.form]="baseId + '-step-up'"
              class="btn btn-primary"
              [loading]="stepUpBusy()"
              [disabled]="stepUpBusy()"
              data-id="profile-sign-in-add-step-up-confirm"
            >
              {{ stepUpCopy.confirm }}
            </button>
          } @else if (step() !== 'choose' && step() !== 'refused') {
            <button
              type="button"
              class="btn btn-outline-secondary"
              [disabled]="busy()"
              data-id="profile-sign-in-add-back"
              (click)="flow().back()"
            >
              Back
            </button>
            <button
              hilosLoadingButton
              type="submit"
              [attr.form]="baseId + '-add'"
              class="btn btn-primary"
              [loading]="busy()"
              [disabled]="!addValid() || busy()"
              [attr.data-id]="submitId()"
            >
              {{
                step() === 'password-email' || step() === 'phone-number'
                  ? 'Send code'
                  : 'Save'
              }}
            </button>
          }
        </ng-template>
      </hilos-modal>
    </section>
  `,
})
export class HilosProfileSignInPage {
  readonly context = input.required<HilosAuthContext>()
  protected readonly methods = signal<readonly HilosProfileSignInMethod[]>([])
  private readonly methodsSignal = computed(() =>
    createHilosProfileSignInMethods(this.context().scopes),
  )
  private readonly offered = signal<readonly AuthMethodEntry[]>([])
  protected readonly passkeySupported = isPasskeySupported()
  /** What the account can still add: the chooser's buttons and the warning's. */
  protected readonly ways = computed(() =>
    hilosProfileAddableWays(
      this.methods(),
      this.offered(),
      this.passkeySupported,
    ),
  )
  protected readonly passkeyOnly = computed(() =>
    isHilosProfilePasskeyOnly(this.methods()),
  )
  protected readonly passkeyOnlyWays = computed(() =>
    this.ways().filter((way) => way.kind !== 'passkey'),
  )
  /** The button that asked for the dialog; it alone spins while it opens. */
  protected readonly addPressed = signal<string | null>(null)
  private readonly actions = computed(() =>
    createHilosProfileSignInActions(this.context()),
  )
  protected readonly baseId = `hilos-profile-sign-in-${signInSequence++}`
  protected readonly copy = HILOS_PROFILE_SIGN_IN_COPY
  protected readonly stepUpCopy = HILOS_STEP_UP_COPY
  protected readonly subtitle = hilosProfileSignInSubtitle
  protected readonly unlinkKey = signal<string | null>(null)
  protected readonly unlinkMethod = computed(() =>
    this.methods().find((method) => method.key === this.unlinkKey()),
  )
  protected readonly unlinkName = computed(() => {
    const method = this.unlinkMethod()
    return method ? this.title(method) : 'this method'
  })
  protected readonly unlinkTitle = computed(
    () => `Remove ${this.unlinkName()}?`,
  )
  protected readonly remaining = computed(() =>
    this.methods()
      .filter((method) => method.key !== this.unlinkKey())
      .map((method) => this.title(method))
      .join(', '),
  )
  protected readonly unlinkAction = createHilosTrackedAction()
  protected readonly passwordFlow = computed(() =>
    createHilosProfilePasswordChangeFlow(this.context()),
  )
  protected readonly passwordStep =
    signal<HilosProfilePasswordChangeStep>('closed')
  protected readonly flow = computed(() =>
    createHilosProfileAddSignInFlow(this.context(), this.methodsSignal()),
  )
  protected readonly step = signal<HilosProfileAddSignInStep>('closed')
  protected readonly busy = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly stepUpRefusal = signal<string | null>(null)
  protected readonly stepUpBusy = signal(false)
  protected readonly pendingProvider = signal<string | null>(null)
  /** The dialog's title: the confirmation step names itself. */
  protected readonly addTitle = computed(() =>
    this.step() === 'step-up' || this.step() === 'refused'
      ? this.stepUpCopy.title
      : 'Add a way to sign in',
  )
  protected readonly draft = signal(emptyDraft())
  private readonly addBody = viewChild<ElementRef<HTMLElement>>('addBody')
  protected readonly addDirty = computed(() =>
    Object.values(this.draft()).some((value) => value !== ''),
  )
  protected readonly fields = computed(() => {
    const step = this.step()
    const passwordFields = [
      {
        key: 'newPassword' as const,
        label: 'New password',
        type: 'password',
        autocomplete: 'new-password',
        dataId: 'profile-add-password-new',
      },
      {
        key: 'confirm' as const,
        label: 'Confirm new password',
        type: 'password',
        autocomplete: 'new-password',
        dataId: 'profile-add-password-confirm',
      },
    ]
    const codeField = {
      key: 'code' as const,
      label: 'Code',
      type: 'text',
      autocomplete: 'one-time-code',
      dataId:
        step === 'phone-code'
          ? 'profile-add-sms-code'
          : 'profile-add-password-code',
    }
    return step === 'password-email'
      ? [
          {
            key: 'email' as const,
            label: 'Email',
            type: 'email',
            autocomplete: 'email',
            dataId: 'profile-add-password-email',
          },
        ]
      : step === 'phone-number'
        ? [
            {
              key: 'phone' as const,
              label: 'Phone number',
              type: 'tel',
              autocomplete: 'tel',
              dataId: 'profile-add-sms-phone',
            },
          ]
        : step === 'phone-code'
          ? [codeField]
          : step === 'password-new'
            ? passwordFields
            : step === 'password-code'
              ? [codeField, ...passwordFields]
              : []
  })
  protected readonly addValid = computed(
    () =>
      this.fields().every(
        (field) =>
          (field.type === 'password'
            ? this.draft()[field.key]
            : this.draft()[field.key].trim()) !== '',
      ) &&
      (!this.step().startsWith('password-') ||
        this.step() === 'password-email' ||
        (this.draft().newPassword.length >= PASSWORD_MIN &&
          this.draft().newPassword === this.draft().confirm)),
  )
  protected readonly submitId = computed(() =>
    this.step() === 'phone-number'
      ? 'profile-add-sms-request'
      : this.step() === 'phone-code'
        ? 'profile-add-sms-confirm'
        : this.step() === 'password-email'
          ? 'profile-add-password-request'
          : 'profile-add-password-save',
  )
  protected readonly errorId = computed(() =>
    this.step().startsWith('phone-')
      ? 'profile-add-sms-error'
      : this.step().startsWith('password-')
        ? 'profile-add-password-error'
        : 'profile-sign-in-add-error',
  )

  constructor() {
    effect((onCleanup) => {
      const flow = this.passwordFlow()
      this.passwordStep.set(flow.step.get())
      const off = subscribeSignal(flow.step, (value) =>
        this.passwordStep.set(value),
      )
      onCleanup(() => {
        off()
        flow.dispose()
      })
    })
    effect((onCleanup) => {
      const methods = this.methodsSignal()
      this.methods.set(methods.get())
      onCleanup(subscribeSignal(methods, (value) => this.methods.set(value)))
    })
    effect(() => {
      if (this.unlinkKey() !== null && !this.unlinkMethod())
        this.unlinkKey.set(null)
    })
    effect((onCleanup) => {
      const offered = sessionAuthMethods(this.context().scopes)
      this.offered.set(offered.get())
      onCleanup(subscribeSignal(offered, (value) => this.offered.set(value)))
    })
    effect((onCleanup) => {
      const flow = this.flow()
      this.step.set(flow.step.get())
      this.busy.set(flow.busy.get())
      this.refusal.set(flow.refusal.get())
      this.stepUpRefusal.set(flow.stepUp.refusal.get())
      this.stepUpBusy.set(flow.stepUp.busy.get())
      this.pendingProvider.set(flow.provider.get())
      const stops = [
        subscribeSignal(flow.step, (value) => this.step.set(value)),
        subscribeSignal(flow.busy, (value) => this.busy.set(value)),
        subscribeSignal(flow.refusal, (value) => this.refusal.set(value)),
        subscribeSignal(flow.stepUp.refusal, (value) =>
          this.stepUpRefusal.set(value),
        ),
        subscribeSignal(flow.stepUp.busy, (value) =>
          this.stepUpBusy.set(value),
        ),
        subscribeSignal(flow.provider, (value) =>
          this.pendingProvider.set(value),
        ),
      ]
      onCleanup(() => {
        for (const stop of stops) stop()
        flow.dispose()
      })
    })
    effect((onCleanup) => {
      onCleanup(
        watchHilosProfilePasswordUpdated(this.context().connection, (data) => {
          if (this.passwordFlow().step.get() !== 'closed') return
          hilosToasts.push(
            data.mode === PROFILE_PASSWORD_MODE_ADDED
              ? this.copy.passwordAdded
              : this.copy.passwordChanged,
            { severity: 'success' },
          )
        }),
      )
    })
    afterRenderEffect(() => {
      this.step()
      const dialog =
        this.addBody()?.nativeElement.closest<HTMLElement>('[role="dialog"]')
      if (dialog) focusInitial(dialog)
    })
  }
  protected title(method: HilosProfileSignInMethod): string {
    return hilosProfileSignInTitle(method, this.offered())
  }
  protected valueOf(event: Event): string {
    return (event.target as HTMLInputElement).value
  }
  protected setDraftField(
    key: keyof ReturnType<typeof emptyDraft>,
    value: string,
  ): void {
    this.draft.update((draft) => ({ ...draft, [key]: value }))
  }
  protected askUnlink(method: HilosProfileSignInMethod): void {
    if (!method.canUnlink || this.unlinkAction.busy()) return
    this.unlinkAction.clearError()
    this.unlinkKey.set(method.key)
  }
  protected closeUnlink(open: boolean): void {
    if (!open && !this.unlinkAction.busy()) this.unlinkKey.set(null)
  }
  protected async remove(): Promise<void> {
    const key = this.unlinkKey()
    if (key === null || this.unlinkAction.busy()) return
    if (await this.unlinkAction.run(this.actions().unlinkIdentity(Number(key))))
      this.unlinkKey.set(null)
  }
  protected openAdd(way?: HilosProfileAddableWay): void {
    this.addPressed.set(way === undefined ? 'add' : this.wayKey(way))
    this.draft.set(emptyDraft())
    void this.flow().open(way)
  }
  protected wayKey(way: HilosProfileAddableWay): string {
    return way.kind === 'provider' ? way.key : way.kind
  }
  protected passkeyOnlyWayId(way: HilosProfileAddableWay): string {
    return way.kind === 'provider'
      ? `profile-sign-in-passkey-only-link-${way.key}`
      : `profile-sign-in-passkey-only-${way.kind}`
  }
  protected passkeyOnlyWayLabel(way: HilosProfileAddableWay): string {
    switch (way.kind) {
      case 'password':
        return this.copy.addPassword
      case 'phone':
        return this.copy.addPhone
      case 'provider':
        return this.copy.linkProvider.replace('{name}', way.name)
      case 'passkey':
        return 'Passkey'
    }
  }
  protected closeAdd(open: boolean): void {
    if (!open) this.flow().close()
  }
  protected async submitAdd(): Promise<void> {
    if (!this.addValid() || this.busy()) return
    const draft = this.draft()
    switch (this.step()) {
      case 'password-new':
        await this.flow().submitPasswordNew(draft.newPassword)
        break
      case 'password-email':
        await this.flow().submitPasswordEmail(draft.email.trim())
        break
      case 'password-code':
        await this.flow().submitPasswordCode(
          draft.code.trim(),
          draft.newPassword,
        )
        break
      case 'phone-number':
        await this.flow().submitPhone(draft.phone.trim())
        break
      case 'phone-code':
        await this.flow().submitPhoneCode(draft.code.trim())
        break
    }
  }
}
