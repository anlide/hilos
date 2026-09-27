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
  createHilosProfileSignInActions,
  createSignal,
  focusInitial,
  hilosProfileLinkableProviders,
  hilosProfilePasswordState,
  hilosProfileSignInSubtitle,
  hilosProfileSignInTitle,
  HILOS_PROFILE_SIGN_IN_COPY,
  hilosToasts,
  isPasskeySupported,
  PROFILE_PASSWORD_MODE_ADDED,
  sessionAuthMethods,
  subscribeSignal,
  watchHilosProfilePasswordUpdated,
  type AuthMethodEntry,
  type HilosAuthContext,
  type HilosProfileAddSignInStep,
  type HilosProfilePasswordChangeStep,
  type HilosProfileSignInMethod,
} from '@hilos/core'
import { HilosProfilePasswordChange } from './HilosProfilePasswordChange.js'
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
    LoadingButton,
  ],
  template: `
    <section data-id="profile-sign-in-view">
      <hilos-page-heading />
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
        class="btn btn-sm btn-outline-primary mt-3"
        type="button"
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
        [open]="step() !== 'closed'"
        (openChange)="closeAdd($event)"
        title="Add a way to sign in"
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
            {{ refusal() }}
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
              @if (!passwordState().hasPassword) {
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
              }
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
              @for (entry of providers(); track entry.key) {
                <span class="btn btn-outline-secondary"
                  ><span class="d-flex align-items-center gap-3 text-start"
                    ><i
                      class="bi bi-box-arrow-in-right fs-5"
                      aria-hidden="true"
                    ></i
                    ><span
                      ><span class="d-block fw-semibold small">{{
                        entry.label
                      }}</span
                      ><span class="d-block small text-body-secondary">{{
                        copy.providerDescription
                      }}</span></span
                    ></span
                  ></span
                >
              }
              @if (passkeySupported) {
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
            </div>
            @if (step() === 'choose') {
              <div class="d-flex flex-column gap-2 align-self-start">
                @if (!passwordState().hasPassword) {
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
                        ><span class="d-block fw-semibold small">Password</span
                        ><span class="d-block small text-body-secondary">{{
                          copy.passwordDescription
                        }}</span></span
                      ></span
                    >
                  </button>
                }
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
                @for (entry of providers(); track entry.key) {
                  <button
                    hilosLoadingButton
                    class="btn btn-outline-secondary"
                    [loading]="busy() && pendingProvider() === entry.key"
                    [disabled]="busy()"
                    [attr.data-id]="'profile-oauth-link-' + entry.key"
                    (click)="flow().chooseProvider(entry.key)"
                  >
                    <span class="d-flex align-items-center gap-3 text-start"
                      ><i
                        class="bi bi-box-arrow-in-right fs-5"
                        aria-hidden="true"
                      ></i
                      ><span
                        ><span class="d-block fw-semibold small">{{
                          entry.label
                        }}</span
                        ><span class="d-block small text-body-secondary">{{
                          copy.providerDescription
                        }}</span></span
                      ></span
                    >
                  </button>
                }
                @if (passkeySupported) {
                  <button
                    hilosLoadingButton
                    class="btn btn-outline-secondary"
                    [loading]="busy() && pendingProvider() === null"
                    [disabled]="busy()"
                    data-id="profile-passkey-add"
                    (click)="flow().choosePasskey()"
                  >
                    <span class="d-flex align-items-center gap-3 text-start"
                      ><i class="bi bi-fingerprint fs-5" aria-hidden="true"></i
                      ><span
                        ><span class="d-block fw-semibold small">Passkey</span
                        ><span class="d-block small text-body-secondary">{{
                          copy.passkeyDescription
                        }}</span></span
                      ></span
                    >
                  </button>
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
          @if (step() !== 'choose') {
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
  readonly methods = input.required<readonly HilosProfileSignInMethod[]>()
  private readonly methodsSignal = createSignal<
    readonly HilosProfileSignInMethod[]
  >([])
  private readonly offered = signal<readonly AuthMethodEntry[]>([])
  protected readonly providers = computed(() =>
    hilosProfileLinkableProviders(this.methods(), this.offered()),
  )
  protected readonly passwordState = computed(() =>
    hilosProfilePasswordState(this.methods()),
  )
  protected readonly passkeySupported = isPasskeySupported()
  private readonly actions = computed(() =>
    createHilosProfileSignInActions(this.context()),
  )
  protected readonly baseId = `hilos-profile-sign-in-${signInSequence++}`
  protected readonly copy = HILOS_PROFILE_SIGN_IN_COPY
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
    createHilosProfileAddSignInFlow(this.context(), this.methodsSignal),
  )
  protected readonly step = signal<HilosProfileAddSignInStep>('closed')
  protected readonly busy = signal(false)
  protected readonly refusal = signal<string | null>(null)
  protected readonly pendingProvider = signal<string | null>(null)
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
    effect(() => this.methodsSignal.set(this.methods()))
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
      this.pendingProvider.set(flow.provider.get())
      const stops = [
        subscribeSignal(flow.step, (value) => this.step.set(value)),
        subscribeSignal(flow.busy, (value) => this.busy.set(value)),
        subscribeSignal(flow.refusal, (value) => this.refusal.set(value)),
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
  protected openAdd(): void {
    this.draft.set(emptyDraft())
    this.flow().open()
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
