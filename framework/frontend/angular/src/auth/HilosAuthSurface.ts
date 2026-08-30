// The framework's Angular default of the identifier-first sign-in surface
// (HIL-425), the peer of framework/frontend/vue/src/auth/HilosAuthSurface.vue
// (HIL-423, mockup framework/guest) and of the React one (HIL-424). The
// framework auth-gate slot (HIL-165) mounts it in place of ErrorPage on an
// anonymous 401 and in the HilosModal for a gated action; it is
// presentation-agnostic and owns no route of its own — the gate resumes the page
// / closes the modal off the session upgrade with no navigation.
//
// There is no login/register/recovery switcher any more. One "email or phone"
// field is typed, the @hilos/core flow machine (authFlow) looks it up live, and
// what is revealed is a function of the reply. Everything with a rule to it —
// the lookup debounce, the re-entry and echo guards, pending, the error, the
// resend gate, the late-outcome verdict, which screen the axes add up to — lives
// in the machine; this component reads its signals, draws them, and hands three
// seams (`authActions`) back to it.
//
// Nothing here branches on a method key: the icon rows render `flow.icons`, the
// channel controls render `flow.channels`, and the main control is whatever
// `primaryAction` says it is. That is what lets HIL-427 ship the enabled set
// from settings without touching this file.
//
// The structure, the texts and every `data-id` are 1:1 with the Vue and React
// peers, and deliberately so: HIL-427 and the i18n stage edit all three surfaces
// with one feature, and HIL-426's parity specs are only parity specs while the
// name set is shared.
//
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  computed,
  effect,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core'
import type { WritableSignal } from '@angular/core'
import {
  AUTH_CONVERGE_SIGNAL,
  authAckToFlowPatch,
  authConvergeSignalSchema,
  createAuthActions,
  createAuthFlow,
  createOAuthLogin,
  oauthTrip,
  oauthTripMessage,
  oauthTripTitle,
  MAGIC_LINK_FLOW_METHOD,
  PASSKEY_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  PASSWORD_MIN_LENGTH,
  sessionPendingAck,
  sessionPendingAuthStep,
  SMS_CODE_CHANNEL,
  TELEGRAM_CODE_CHANNEL,
  subscribeSignal,
  toFlowPatch,
} from '@hilos/core'
import type {
  AuthFlow,
  AuthFlowError,
  AuthFlowForm,
  AuthFlowMethodDescriptor,
  AuthFlowPrimaryAction,
  AuthFlowScreen,
  AuthFlowState,
  AuthStep,
  CodeChannelDescriptor,
  DetectionState,
  HilosAuthContext,
  ProjectSignal,
  ReadonlySignal,
} from '@hilos/core'

import { LoadingButton } from '../LoadingButton.js'
import { hilosSignal } from '../hilosSignal.js'
import { HILOS_AUTH_GATE } from './hilosAuthGateToken.js'

/** How often the countdowns redraw — one second, the smallest unit they show. */
const COUNTDOWN_TICK_MS = 1000

/** Milliseconds in a second, for reading a remaining span as a clock. */
const MS_PER_SECOND = 1000

/** Seconds in a minute, for the same. */
const SECONDS_PER_MINUTE = 60

// What the mirrors below hold in the moment between construction and the effect
// that binds them to the machine — the machine is born from the `context` input
// and there is no input to read at field-initializer time. They are the
// machine's own starting values, and the core's types are what hold them to it:
// a field added to a flow or a form fails to compile here until it is answered.
const INITIAL_FLOW: AuthFlowState = {
  step: 'identifier',
  intent: 'login',
  methodKey: null,
  identifierKind: 'unknown',
  channelKey: null,
}

const EMPTY_FORM: AuthFlowForm = {
  identifier: '',
  password: '',
  code: '',
  newPassword: '',
  consentAccepted: false,
  usingBackupCode: false,
  trustDevice: false,
}

const IDLE_DETECTION: DetectionState = { status: 'idle', result: null }

// Bootstrap icons per method and per channel. The view owns both maps and the
// core contract carries neither: an icon is a property of this surface's design
// language, and a method the framework knows about must not be able to
// prescribe one. An unlisted key still renders — with the generic glyph — which
// is what keeps the surface agnostic about the set it is given.
const METHOD_ICONS: Record<string, string> = {
  [PASSKEY_FLOW_METHOD.key]: 'bi bi-fingerprint',
  [MAGIC_LINK_FLOW_METHOD.key]: 'bi bi-envelope',
  'oauth:github': 'bi bi-github',
  'oauth:google': 'bi bi-google',
}

/** The glyph an unlisted method or channel key falls back to. */
const GENERIC_ICON = 'bi bi-box-arrow-in-right'

const CHANNEL_ICONS: Record<string, string> = {
  [SMS_CODE_CHANNEL.key]: 'bi bi-chat-dots',
  [TELEGRAM_CODE_CHANNEL.key]: 'bi bi-telegram',
}

/**
 * What each screen is called. The surface owns its heading — the modal frame
 * stopped hard-coding one — because what the screen is called changes with the
 * step, and the machine is what knows which step the axes add up to.
 */
const HEADINGS: Record<AuthFlowScreen, string> = {
  sign_in: 'Sign in',
  create_account: 'Create your account',
  held_identifier: 'You already have a code',
  terms: 'Terms and privacy',
  confirm_identifier: 'Confirm your email',
  enter_code: 'Enter the code',
  reset_code: 'Reset your password',
  choose_password: 'Choose a new password',
  two_step: 'Two-step verification',
  waiting_external: 'Sign in',
  check_inbox: 'Check your inbox',
  done_registered: 'Your account is ready',
  done_password_changed: 'Password changed',
  done_signed_in: "You're signed in",
}

/** What the main button of each screen says; empty where a screen has none. */
const SUBMIT_LABELS: Record<AuthFlowScreen, string> = {
  sign_in: 'Sign in',
  create_account: 'Create account',
  held_identifier: 'Enter the code',
  terms: 'Create account',
  confirm_identifier: 'Confirm',
  enter_code: 'Continue',
  reset_code: 'Continue',
  choose_password: 'Save password',
  two_step: 'Verify',
  waiting_external: '',
  check_inbox: 'Continue',
  done_registered: 'Continue',
  done_password_changed: 'Continue',
  done_signed_in: 'Continue',
}

/**
 * The sentence for a refusal that arrived as a bare code. Hilos i18n is
 * backend-side, so a reply that carries its own message wins and this map is
 * only for the codes sent without one (and for the converge, which never
 * carries prose at all).
 */
const CODE_MESSAGES: Record<string, string> = {
  identifier_taken: 'That address already has an account — sign in instead.',
  reservation_expired:
    'That registration expired. Start again from the address.',
  reset_code_expired: 'That reset code has expired. Ask for a new one.',
  password_already_changed:
    'The password was already changed on another device. Sign in with the new one.',
  magic_link_invalid:
    'That sign-in link is no longer valid. Ask for a new one.',
  send_cap_reached: 'Too many codes have gone out. Please try again later.',
  rate_limited: 'Too many attempts. Please wait a moment and try again.',
  challenge_required: 'Please confirm you are not a robot and try again.',
}

/** What is shown when a refusal carried neither a sentence nor a known code. */
const GENERIC_ERROR = 'That did not work. Please try again.'

/**
 * The identifier-first sign-in surface: one field, and whatever the lookup makes
 * of it.
 */
@Component({
  selector: 'hilos-auth-surface',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [LoadingButton],
  template: `
    <section data-id="auth-surface" class="mx-auto" style="max-width: 24rem">
      <h2 class="h5 mb-3" data-id="auth-heading">{{ heading() }}</h2>

      <!-- OAuth email-collision re-auth prompt (HIL-282): the provider address
      already has an account, so ask the person to sign in with an existing
      method to finish linking. The pending link token is redeemed globally once
      the session upgrades. -->
      @if (linkPrompt() && state().step === 'identifier') {
        <div
          class="alert alert-info py-2"
          role="status"
          data-id="auth-link-prompt"
        >
          That email already has an account. Sign in to finish linking it.
        </div>
      }

      <!-- News about a move nobody on this screen asked for. Its own region,
      above the form: it is not this step's refusal, and the step it lands on is
      usually the identifier field, where the error region belongs to what is
      typed next. -->
      @if (notice(); as message) {
        <div
          class="alert alert-warning py-2"
          role="status"
          data-id="auth-notice"
        >
          {{ message }}
        </div>
      }

      <!-- The single identifier field: one screen, whatever it turns out to be.
      The icon row stands FIRST because a device key and a provider are the short
      road and the field is the long one; both live only on an empty field. -->
      @if (state().step === 'identifier') {
        <form novalidate (submit)="submit($event)">
          @if (rowIcons().length > 0) {
            <div class="d-flex justify-content-center gap-2">
              @for (method of rowIcons(); track method.key) {
                <button
                  hilosLoadingButton
                  class="btn-outline-secondary"
                  [loading]="pending() && state().methodKey === method.key"
                  [disabled]="pending()"
                  [attr.aria-label]="method.label"
                  [title]="method.label"
                  [attr.data-id]="methodDataId(method.key)"
                  (click)="chooseMethod(method.key)"
                >
                  <i [class]="methodIcon(method.key)" aria-hidden="true"></i>
                </button>
              }
            </div>
            <div class="d-flex align-items-center gap-2 my-3">
              <hr class="flex-grow-1 my-0" />
              <span class="small text-body-secondary">or</span>
              <hr class="flex-grow-1 my-0" />
            </div>
          }

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-identifier">
              Email or phone
            </label>
            <input
              #identifierInput
              id="auth-identifier"
              type="text"
              class="form-control"
              autocomplete="username"
              placeholder="you@example.com"
              data-autofocus
              data-id="auth-identifier"
              [value]="form().identifier"
              (input)="updateIdentifier($event)"
            />
            @if (identifierHint(); as hint) {
              <div class="form-text" data-id="auth-identifier-hint">
                {{ hint }}
              </div>
            }
          </div>

          <!-- The password lives inside this step, revealed by the reply. Beside
          it stand the ways past it: the envelope for an account that would
          rather have a link, the key for one that forgot the password. -->
          @if (showPassword()) {
            <div class="mb-3">
              <label class="form-label small fw-semibold" for="auth-password">
                Password
              </label>
              <div class="d-flex align-items-center gap-2">
                <input
                  id="auth-password"
                  type="password"
                  class="form-control"
                  [attr.autocomplete]="
                    state().intent === 'register'
                      ? 'new-password'
                      : 'current-password'
                  "
                  data-id="auth-password"
                  [value]="form().password"
                  (input)="updatePassword($event)"
                />
                @for (method of adjacentIcons(); track method.key) {
                  <button
                    hilosLoadingButton
                    class="btn-outline-secondary"
                    [loading]="pending() && state().methodKey === method.key"
                    [disabled]="pending()"
                    [attr.aria-label]="method.label"
                    [title]="method.label"
                    [attr.data-id]="methodDataId(method.key)"
                    (click)="chooseMethod(method.key)"
                  >
                    <i [class]="methodIcon(method.key)" aria-hidden="true"></i>
                  </button>
                }
                @if (showRecovery()) {
                  <button
                    type="button"
                    class="btn btn-outline-secondary"
                    aria-label="Forgot your password?"
                    title="Forgot your password?"
                    data-id="auth-recovery"
                    (click)="startRecovery()"
                  >
                    <i class="bi bi-key" aria-hidden="true"></i>
                  </button>
                }
              </div>
              @if (state().intent === 'register') {
                <div class="form-text">
                  At least {{ passwordMinLength }} characters.
                </div>
              }
            </div>
          }

          @if (errorMessage(); as message) {
            <div
              class="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {{ message }}
            </div>
          }

          <!-- The main control is whatever the machine says it is: the submit, a
          passwordless method promoted to the button, or a code channel — for a
          phone the channel choice IS the send, so there is no separate button. -->
          @if (primaryAction()?.kind === 'submit') {
            <button
              hilosLoadingButton
              type="submit"
              class="btn-primary w-100"
              [loading]="pending()"
              [disabled]="!submittable()"
              data-id="auth-submit"
            >
              {{ submitLabel() }}
            </button>
          } @else if (primaryAction()?.kind === 'resume_code') {
            <button
              type="button"
              class="btn btn-primary w-100"
              data-id="auth-resume-code"
              (click)="resumeHeldRegistration()"
            >
              {{ submitLabel() }}
            </button>
          } @else if (primaryMethod(); as method) {
            <button
              hilosLoadingButton
              class="btn-primary w-100"
              [loading]="pending()"
              [disabled]="pending()"
              [attr.data-id]="methodDataId(method.key)"
              (click)="chooseMethod(method.key)"
            >
              <i
                class="me-2"
                [class]="methodIcon(method.key)"
                aria-hidden="true"
              ></i>
              {{ method.label }}
            </button>
          } @else if (primaryChannel(); as channel) {
            <button
              hilosLoadingButton
              class="btn-primary w-100"
              [loading]="pending()"
              [disabled]="pending() || unavailableChannels().has(channel.key)"
              [attr.data-id]="channelDataId(channel.key)"
              (click)="chooseChannel(channel.key)"
            >
              Send a code by {{ channel.label }}
            </button>

            @if (otherChannels().length > 0) {
              <div class="d-flex align-items-center gap-2 my-3">
                <hr class="flex-grow-1 my-0" />
                <span class="small text-body-secondary">or send it to</span>
                <hr class="flex-grow-1 my-0" />
              </div>
              <div class="d-flex justify-content-center gap-2">
                @for (other of otherChannels(); track other.key) {
                  <button
                    hilosLoadingButton
                    class="btn-outline-secondary"
                    [loading]="pending() && state().channelKey === other.key"
                    [disabled]="
                      pending() || unavailableChannels().has(other.key)
                    "
                    [attr.aria-label]="'Send the code via ' + other.label"
                    [title]="channelTitle(other)"
                    [attr.data-id]="channelDataId(other.key)"
                    (click)="chooseChannel(other.key)"
                  >
                    <i [class]="channelIcon(other.key)" aria-hidden="true"></i>
                  </button>
                }
              </div>
            }
          }
        </form>
      } @else if (state().step === 'consent') {
        <!-- The terms screen. Registration is unreachable without it: the
        machine's submit on the identifier step moves here, and the dispatch that
        creates anything happens from this button.

        STOPGAP (HIL-499 in epic HIL-496 replaces it): one never-pre-ticked
        checkbox covering both documents, links to their full texts, and NO
        acceptance record of any kind — a record names a revision, and revisions
        do not exist yet. -->
        <form novalidate (submit)="submit($event)">
          <p class="text-body-secondary small mb-3">
            This project runs on the standard Hilos terms.
          </p>

          <div class="form-check mb-3">
            <input
              #consentInput
              id="auth-consent-accept"
              class="form-check-input"
              type="checkbox"
              data-id="auth-consent-accept"
              [checked]="form().consentAccepted"
              (change)="updateConsent($event)"
            />
            <label class="form-check-label small" for="auth-consent-accept">
              I agree to the
              <a [href]="context().termsPath" target="_blank" rel="noopener">
                Terms
              </a>
              and the
              <a [href]="context().privacyPath" target="_blank" rel="noopener">
                Privacy Policy </a
              >.
            </label>
          </div>

          @if (errorMessage(); as message) {
            <div
              class="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {{ message }}
            </div>
          }

          <button
            hilosLoadingButton
            type="submit"
            class="btn-primary w-100 mb-2"
            [loading]="pending()"
            [disabled]="!submittable()"
            data-id="auth-submit"
          >
            {{ submitLabel() }}
          </button>

          <button
            type="button"
            class="btn btn-link btn-sm w-100"
            data-id="auth-restart"
            (click)="backToIdentifier()"
          >
            Back
          </button>
        </form>
      } @else if (state().step === 'code') {
        <!-- The one code screen, whichever code it is: confirming an address,
        signing a number in, proving a mailbox for a reset, or typing the digits
        that came in a sign-in letter (HIL-606). What differs is the heading, the
        line naming where the code went, and the way out. -->
        <form novalidate (submit)="submit($event)">
          <div
            class="d-flex align-items-center gap-2 mb-3 px-3 py-2 rounded bg-body-tertiary"
          >
            <i
              class="bi bi-envelope text-body-secondary"
              aria-hidden="true"
            ></i>
            <span class="small fw-semibold flex-grow-1">
              {{ form().identifier }}
            </span>
            @if (state().intent === 'register') {
              <button
                type="button"
                class="btn btn-sm btn-link p-0 small"
                data-id="auth-restart"
                (click)="abandon()"
              >
                Not that address?
              </button>
            }
          </div>

          @if (deliveredChannel(); as channel) {
            <p
              class="text-body-secondary small mb-3"
              data-id="auth-delivered-channel"
            >
              Sent via {{ channel }}.
            </p>
          }

          <!-- The letter went out with two ways back in it, so the screen says
          so before it asks for one: the link is still the shorter road for
          whoever can click it, and the field below is for whoever cannot. -->
          @if (screenKey() === 'check_inbox') {
            <div
              class="alert alert-success small py-2"
              role="status"
              data-id="auth-link-sent"
            >
              <i class="bi bi-envelope-check me-1" aria-hidden="true"></i>
              We've sent a sign-in link to
              <strong>{{ form().identifier }}</strong
              >. Open it to continue.
            </div>
          }

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-code">
              Code
            </label>
            <input
              #codeInput
              id="auth-code"
              type="text"
              inputmode="numeric"
              class="form-control"
              autocomplete="one-time-code"
              data-id="auth-code"
              [value]="form().code"
              (input)="updateCode($event)"
            />
            @if (expiresIn(); as left) {
              <div class="form-text" data-id="auth-expires-in">
                <i class="bi bi-clock me-1" aria-hidden="true"></i>
                Expires in {{ left }}.
              </div>
            }
          </div>

          @if (errorMessage(); as message) {
            <div
              class="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {{ message }}
            </div>
          }

          <button
            hilosLoadingButton
            type="submit"
            class="btn-primary w-100 mb-2"
            [loading]="pending()"
            [disabled]="!submittable()"
            data-id="auth-submit"
          >
            {{ submitLabel() }}
          </button>

          <!-- The gate is the backend's (the address owns the cooldown, not this
          tab): while it holds, the button is a countdown instead. -->
          @if (resendIn(); as left) {
            <div
              class="small text-body-secondary text-center"
              data-id="auth-resend-in"
            >
              <i class="bi bi-clock me-1" aria-hidden="true"></i>
              Send a new code in {{ left }}
            </div>
          } @else {
            <button
              type="button"
              class="btn btn-link btn-sm w-100"
              [disabled]="pending()"
              data-id="auth-resend"
              (click)="resend()"
            >
              <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>
              Send a new code
            </button>
          }
        </form>
      } @else if (state().step === 'set_password') {
        <!-- The new password of a recovery. The address is not asked for again:
        the accepted code left a grant on this session, and that is what names
        the account. -->
        <form novalidate (submit)="submit($event)">
          <p class="text-body-secondary small mb-3">
            The code was accepted. Choose a new password.
          </p>

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-new-password">
              New password
            </label>
            <input
              #newPasswordInput
              id="auth-new-password"
              type="password"
              class="form-control"
              autocomplete="new-password"
              data-id="auth-new-password"
              [value]="form().newPassword"
              (input)="updateNewPassword($event)"
            />
            <div class="form-text">
              At least {{ passwordMinLength }} characters.
            </div>
          </div>

          @if (errorMessage(); as message) {
            <div
              class="alert alert-danger py-2"
              role="alert"
              aria-live="polite"
              data-id="auth-error"
            >
              {{ message }}
            </div>
          }

          <button
            hilosLoadingButton
            type="submit"
            class="btn-primary w-100"
            [loading]="pending()"
            [disabled]="!submittable()"
            data-id="auth-submit"
          >
            {{ submitLabel() }}
          </button>
        </form>
      } @else if (state().step === 'external') {
        <!-- Parked on a ceremony. A link waits on the inbox, everything else
        waits on the device; both are the same step and both can be taken back —
        cancelling ends the ceremony itself rather than merely forgetting its
        outcome. -->
        @if (screenKey() === 'check_inbox') {
          <div class="alert alert-success small py-2" role="status">
            <i class="bi bi-envelope-check me-1" aria-hidden="true"></i>
            We've sent a sign-in link to
            <strong>{{ form().identifier }}</strong
            >. Open it to continue.
          </div>
        } @else {
          <div class="text-center py-4">
            <div class="spinner-border text-primary mb-3" role="status">
              <span class="visually-hidden">Waiting</span>
            </div>
            @if (trip()) {
              <div class="fw-semibold mb-1">{{ waitingTitle() }}</div>
            }
            <div class="small text-body-secondary">
              {{ waitingMessage() }}
            </div>
          </div>
        }

        @if (errorMessage(); as message) {
          <div
            class="alert alert-danger py-2"
            role="alert"
            aria-live="polite"
            data-id="auth-error"
          >
            {{ message }}
          </div>
        }

        @if (tripCancelable()) {
          <button
            type="button"
            class="btn btn-outline-secondary w-100"
            data-id="auth-cancel"
            (click)="cancelMethod()"
          >
            Cancel
          </button>
        }
      } @else if (state().step === 'done') {
        <!-- The end of a flow is a screen with a button, not a fading toast:
        what was achieved is said once, and Continue is what closes it and lets
        the page through. -->
        <div class="text-center py-3">
          <i
            class="bi bi-check-circle-fill text-success mb-3 fs-1"
            aria-hidden="true"
          ></i>
          <p class="text-body-secondary small mb-4">
            @if (screenKey() === 'done_registered') {
              Your address is confirmed and you are signed in.
            } @else if (screenKey() === 'done_password_changed') {
              Your new password is saved. Codes left on other devices no longer
              work.
            } @else {
              You are signed in.
            }
          </p>

          <button
            hilosLoadingButton
            class="btn-primary w-100"
            [loading]="pending()"
            data-id="auth-continue"
            (click)="continueFromDone()"
          >
            {{ submitLabel() }}
          </button>
        </div>
      }
    </section>
  `,
})
export class HilosAuthSurface {
  /** The project context: its stores, its method registry, its terms paths. */
  readonly context = input.required<HilosAuthContext>()

  protected readonly passwordMinLength = PASSWORD_MIN_LENGTH

  // Everything born from the context is a computed over it, so it is built once
  // per context and never per change detection: `createAuthFlow` carries the
  // whole machine, and the two session factories return a NEW signal per call —
  // calling either from the template or from a getter would re-subscribe the
  // surface on every pass.
  private readonly authActions = computed(() =>
    createAuthActions(this.context()),
  )
  private readonly oauth = computed(() => createOAuthLogin(this.context()))
  private readonly auth = computed<AuthFlow>(() => {
    const context = this.context()
    const actions = this.authActions()

    return createAuthFlow({
      methods: context.methods,
      channels: context.channels,
      onDetect: (identifier) => actions.onDetect(identifier),
      onSubmit: actions.onSubmit,
      onMethodAction: actions.onMethodAction,
    })
  })
  // The two pending facts the surface resumes from are DERIVED from the session
  // scope by the framework's own factories, never handed in: a project cannot
  // pass a stale copy of state the framework already owns.
  private readonly pendingAuthStep = computed(() =>
    sessionPendingAuthStep(this.context().scopes),
  )
  private readonly pendingAck = computed(() =>
    sessionPendingAck(this.context().scopes),
  )

  // Taken at field-initializer time because DI is available at construction
  // where an input is not, and optional because the surface must work with no
  // provider: on a 401 it stands IN PLACE of the page, and then Continue simply
  // has nothing to close.
  private readonly gate = inject(HILOS_AUTH_GATE, { optional: true })

  // The machine's signals mirrored into Angular signals by the effect below.
  protected readonly state = signal<AuthFlowState>(INITIAL_FLOW)
  protected readonly form = signal<AuthFlowForm>(EMPTY_FORM)
  protected readonly detection = signal<DetectionState>(IDLE_DETECTION)
  protected readonly pending = signal(false)
  protected readonly error = signal<AuthFlowError | null>(null)
  protected readonly submittable = signal(false)
  protected readonly icons = signal<readonly AuthFlowMethodDescriptor[]>([])
  protected readonly channels = signal<readonly CodeChannelDescriptor[]>([])
  protected readonly primaryAction = signal<AuthFlowPrimaryAction>(null)
  protected readonly screenKey = signal<AuthFlowScreen>('sign_in')
  protected readonly resendAvailableAt = signal<number | null>(null)
  protected readonly expiresAt = signal<number | null>(null)
  protected readonly ack = signal<string | null>(null)

  // Set on mount when an OAuth email collision armed a pending link (HIL-282):
  // the account already exists, so the surface pre-fills its address and shows a
  // "finish linking" prompt asking the person to sign in with an existing
  // method. The token replay itself is the global watcher's job (oauthLogin).
  protected readonly linkPrompt = signal(false)

  // Channels that answered "cannot reach this number" (HIL-492). Client state,
  // not stored anywhere: it is true of a number and not of an account, so it is
  // cleared the moment the number changes. The Set is REBUILT on add, or the
  // signal never reports the change.
  protected readonly unavailableChannels = signal<ReadonlySet<string>>(
    new Set(),
  )

  // What a converge said when it moved this surface without being asked (an
  // expired reservation, a password another device already changed). It is view
  // state because the machine's external door clears the error region on purpose
  // — a step rebuilt under somebody's hands must not inherit the old screen's
  // complaint — while this sentence is the news ABOUT that move and belongs on
  // the screen it lands on. The next dispatch clears it: by then the person is
  // acting on the new screen, and news about the past is over.
  protected readonly notice = signal<string | null>(null)

  // The OAuth trip running behind this screen, when the parked ceremony is one
  // (HIL-633). The park is the same step for every icon method, but an OAuth wait
  // is the one that has somewhere for the person to LOOK — another window — so it
  // says where, names the provider, and drops its Cancel once the window has
  // closed itself. Read from the trip and not from the flow, because the phase is
  // the trip's own and the flow parks in `external` for both of them.
  protected readonly trip = hilosSignal(oauthTrip)

  protected readonly waitingTitle = computed(() => {
    const running = this.trip()

    return running === null ? '' : oauthTripTitle(running)
  })

  protected readonly tripCancelable = computed(() => {
    const running = this.trip()

    return running === null || running.phase === 'authorizing'
  })

  protected readonly waitingMessage = computed(() => {
    const running = this.trip()

    return running === null
      ? 'Waiting for your device…'
      : oauthTripMessage(running)
  })

  // The clock the countdowns are read against, ticked by the interval below: a
  // bare Date.now() inside a computed would freeze the number at whatever it was
  // when the screen opened, because a computed only recomputes when something it
  // read changes.
  private readonly now = signal(Date.now())

  private readonly identifierInput =
    viewChild<ElementRef<HTMLInputElement>>('identifierInput')
  private readonly codeInput =
    viewChild<ElementRef<HTMLInputElement>>('codeInput')
  private readonly newPasswordInput =
    viewChild<ElementRef<HTMLInputElement>>('newPasswordInput')
  private readonly consentInput =
    viewChild<ElementRef<HTMLInputElement>>('consentInput')

  // The step the focus effect last acted on. An Angular effect runs on its first
  // binding exactly as a React effect runs on mount, and the first render must
  // not steal focus — that belongs to the modal frame.
  private focusedStep: AuthStep = INITIAL_FLOW.step

  protected readonly heading = computed(() =>
    this.screenKey() === 'confirm_identifier' &&
    this.state().identifierKind === 'phone'
      ? 'Confirm your number'
      : HEADINGS[this.screenKey()],
  )

  protected readonly submitLabel = computed(
    () => SUBMIT_LABELS[this.screenKey()],
  )

  // The inline refusal: the backend's own sentence when it sent one, its
  // semantic code turned into ours when it did not.
  protected readonly errorMessage = computed<string | null>(() => {
    const shown = this.error()
    if (shown === null) {
      return null
    }

    return (
      shown.message ??
      (shown.code === null ? null : CODE_MESSAGES[shown.code]) ??
      GENERIC_ERROR
    )
  })

  // The icon row above the field, and the passwordless exits that live next to
  // the password itself. Both are the machine's visible set split by placement —
  // which icons are visible at all (empty field, typed kind, intent) was decided
  // there.
  protected readonly rowIcons = computed(() =>
    this.icons().filter((method) => method.placement !== 'password_adjacent'),
  )
  protected readonly adjacentIcons = computed(() =>
    this.icons().filter((method) => method.placement === 'password_adjacent'),
  )

  // The password reveals INSIDE the identifier step as a function of the reply:
  // an account that signs in with one asks for it, a free address that may be
  // registered with one offers it, and everything else (a phone, a passwordless
  // account) never shows the field at all.
  protected readonly showPassword = computed(() => {
    const result = this.detection().result
    if (
      this.state().step !== 'identifier' ||
      result === null ||
      result.kind !== 'email'
    ) {
      return false
    }

    return result.status === 'active'
      ? result.methods.includes(PASSWORD_METHOD_KEY)
      : result.status === 'none' &&
          result.registerable.includes(PASSWORD_METHOD_KEY)
  })

  // The recovery key sits beside the password and only for an account that HAS
  // one: there is nothing to reset for an address that signs in by link, and
  // nothing at all for one that has no account yet.
  protected readonly showRecovery = computed(() => {
    const result = this.detection().result

    return (
      this.showPassword() &&
      result !== null &&
      result.status === 'active' &&
      result.methods.includes(PASSWORD_METHOD_KEY)
    )
  })

  /**
   * The line under the identifier field. It never says "wrong": an unrecognized
   * value is answered by describing what the field takes, and a resolved lookup
   * says what it found — which is the whole conversation the reveal is having.
   */
  protected readonly identifierHint = computed<string | null>(() => {
    if (this.state().step !== 'identifier') {
      return null
    }
    if (this.form().identifier.trim() === '') {
      return 'Your email address or phone number.'
    }
    if (this.state().identifierKind === 'unknown') {
      return 'That does not look like an email address or a phone number yet.'
    }
    const result = this.detection().result
    if (result === null) {
      return null
    }
    if (result.status === 'none') {
      return result.registerable.length > 0
        ? 'No account yet — this creates one.'
        : 'No account for this, and registration is closed.'
    }
    // A held address has no account to describe — it has a code in flight, and
    // saying so is the whole point of the screen the return lands on (HIL-651).
    if (result.status === 'pending') {
      return result.kind === 'phone'
        ? 'A code is already on its way to this number.'
        : 'A code is already on its way to this address.'
    }

    return this.showPassword() ? null : 'This account has no password.'
  })

  /** The channel the main button sends over, or null when the screen has none. */
  protected readonly primaryChannel = computed<CodeChannelDescriptor | null>(
    () => {
      const action = this.primaryAction()
      if (action === null || action.kind !== 'channel') {
        return null
      }

      return (
        this.channels().find((channel) => channel.key === action.key) ?? null
      )
    },
  )

  /** The other channels this number can be reached on, offered as icons. */
  protected readonly otherChannels = computed<readonly CodeChannelDescriptor[]>(
    () => {
      const primary = this.primaryChannel()

      return primary === null
        ? []
        : this.channels().filter((channel) => channel.key !== primary.key)
    },
  )

  /** The method the machine promoted to the main button, or null. */
  protected readonly primaryMethod = computed<AuthFlowMethodDescriptor | null>(
    () => {
      const action = this.primaryAction()
      if (action === null || action.kind !== 'method') {
        return null
      }

      return (
        this.context().methods.find((method) => method.key === action.key) ??
        null
      )
    },
  )

  /** The channel a delivered code went over, named on the code screen. */
  protected readonly deliveredChannel = computed<string | null>(() => {
    const key = this.state().channelKey
    if (key === null) {
      return null
    }

    return (
      this.context().channels.find((channel) => channel.key === key)?.label ??
      key
    )
  })

  protected readonly resendIn = computed(() =>
    this.remaining(this.resendAvailableAt()),
  )
  protected readonly expiresIn = computed(() =>
    this.remaining(this.expiresAt()),
  )

  constructor() {
    // The machine arrives through the context input (not at construction) and
    // carries core signals, so mirror them into the Angular signals above once
    // it is bound; the cleanup drops every subscription when the context is
    // swapped or the component is destroyed.
    effect((onCleanup) => {
      const auth = this.auth()
      const bind = <T>(
        source: ReadonlySignal<T>,
        target: WritableSignal<T>,
      ): (() => void) => {
        target.set(source.get())

        return subscribeSignal(source, (value) => target.set(value))
      }
      const subscriptions = [
        bind(auth.flow, this.state),
        bind(auth.form, this.form),
        bind(auth.detection, this.detection),
        bind(auth.pending, this.pending),
        bind(auth.error, this.error),
        bind(auth.submittable, this.submittable),
        bind(auth.icons, this.icons),
        bind(auth.channels, this.channels),
        bind(auth.primaryAction, this.primaryAction),
        bind(auth.screenKey, this.screenKey),
        bind(auth.resendAvailableAt, this.resendAvailableAt),
        bind(auth.expiresAt, this.expiresAt),
        bind(this.pendingAck(), this.ack),
      ]
      onCleanup(() => {
        for (const unsubscribe of subscriptions) {
          unsubscribe()
        }
      })
    })

    // Mount and unmount, in one effect over the computed machine: it runs once
    // per context, never per change detection. Everything it does is local (the
    // machine and two subscriptions) and the cleanup is complete — nothing on
    // mount touches the wire.
    effect((onCleanup) => {
      const context = this.context()
      const auth = this.auth()
      const authActions = this.authActions()

      // Start every mount clean: the surface may be re-shown for a new gated
      // action.
      auth.reset()
      this.notice.set(null)
      this.unavailableChannels.set(new Set())

      const stopWatchingChannels = authActions.subscribeCodeChannelUnavailable(
        (channel) => {
          this.unavailableChannels.update((current) =>
            new Set(current).add(channel),
          )
        },
      )

      // Liveness (HIL-415/416/486): the step can be taken away by somebody else
      // — another tab confirming the code, a reservation expiring, a recovery
      // finished on another device. A converge about a different address is
      // ignored rather than applied to whatever is being typed here.
      const stopWatchingConverge = context.connection.on(
        'projectSignal',
        (signal: ProjectSignal) => {
          if (signal.type !== AUTH_CONVERGE_SIGNAL) {
            return
          }
          const data = signal.data as ReturnType<
            typeof authConvergeSignalSchema.parse
          >
          if (!this.isCurrentIdentifier(auth, data.identifier)) {
            return
          }
          this.applyFromServer(auth, data.step, data.intent, data.code)
        },
      )

      const clock = setInterval(() => {
        this.now.set(Date.now())
      }, COUNTDOWN_TICK_MS)

      // Answer an OAuth trip that ended while this screen was parked on it
      // (HIL-633). Only a park is answered: a trip can also be a profile link
      // running in another page of the same tab, and that one is somebody
      // else's wait. What comes back from a trip is news about a move nobody on
      // this screen asked for — which is what the notice region is — while the
      // error region stays for what the person types next.
      const stopWatchingTrip = this.oauth().subscribeOAuthOutcome((outcome) => {
        if (auth.flow.get().step !== 'external') {
          return
        }
        if (outcome.kind === 'signed_in') {
          // The gate closes this surface on the upgrade; saying anything here
          // would be saying it to a screen already on its way out (HIL-422).
          return
        }
        auth.cancelMethod()
        if (outcome.kind === 'reauth_pending') {
          this.promptToFinishLink(auth)

          return
        }
        this.notice.set(outcome.kind === 'error' ? outcome.message : null)
      })

      this.promptToFinishLink(auth)
      // The unfinished registration comes back from the SESSION, not from
      // anything this tab remembers, so a reload, a second tab and another
      // device all resume the same screen. Both pending facts are read from
      // their signals rather than from the mirrors, which the effect must not
      // depend on: a mirror changing would re-run the whole mount.
      auth.resume(this.pendingAuthStep().get())
      const patch = authAckToFlowPatch(this.pendingAck().get())
      if (patch !== null) {
        auth.applyExternal(patch)
      }

      onCleanup(() => {
        stopWatchingChannels()
        stopWatchingConverge()
        stopWatchingTrip()
        clearInterval(clock)
      })
    })

    // A step CHANGE moves focus; a reveal inside the identifier step
    // deliberately does not. The password appears 300ms after a keystroke, and
    // taking the cursor out of the field somebody is still typing in would be
    // the surface fighting them. The field is read alongside the step so the
    // effect re-runs once it is actually in the DOM — the shape HilosModal's
    // focus trap already relies on.
    effect(() => {
      const step = this.state().step
      const field = {
        identifier: this.identifierInput(),
        consent: this.consentInput(),
        code: this.codeInput(),
        set_password: this.newPasswordInput(),
        second_factor: this.codeInput(),
        external: null,
        done: null,
      }[step]
      if (this.focusedStep === step) {
        return
      }
      // Undefined is a field this step HAS and the view has not rendered yet:
      // leave the step unsettled so the query resolving re-runs this effect.
      if (field === undefined) {
        return
      }
      this.focusedStep = step
      field?.nativeElement.focus()
    })

    // Any dispatch settles the news about a move nobody asked for: by then the
    // person is acting on the new screen and whatever answers them belongs in
    // the error region instead.
    effect(() => {
      if (this.pending()) {
        this.notice.set(null)
      }
    })

    // The ack is what a finished flow left to say — including in a tab that
    // finished nothing (another window of the same session). The gate opens the
    // surface for it; this is what draws the right panel.
    effect(() => {
      const patch = authAckToFlowPatch(this.ack())
      if (patch !== null) {
        this.auth().applyExternal(patch)
      }
    })
  }

  /**
   * The stable `data-id` of one method's control.
   *
   * @param key The method key, e.g. `oauth:github`.
   * @returns The slug the e2e specs address it by, e.g. `auth-icon-oauth-github`.
   */
  protected methodDataId(key: string): string {
    return `auth-icon-${key.replace(/[:_]/g, '-')}`
  }

  /**
   * The stable `data-id` of one channel's control.
   *
   * @param key The channel key, e.g. `sms`.
   * @returns The slug the e2e specs address it by, e.g. `auth-channel-sms`.
   */
  protected channelDataId(key: string): string {
    return `auth-channel-${key}`
  }

  /**
   * The glyph of one method, or the generic one for a key this view has no icon
   * for — a method set that grows must still render.
   *
   * @param key The method key.
   * @returns The Bootstrap icon classes.
   */
  protected methodIcon(key: string): string {
    return METHOD_ICONS[key] ?? GENERIC_ICON
  }

  /**
   * The glyph of one channel, on the same terms as {@link methodIcon}.
   *
   * @param key The channel key.
   * @returns The Bootstrap icon classes.
   */
  protected channelIcon(key: string): string {
    return CHANNEL_ICONS[key] ?? GENERIC_ICON
  }

  /**
   * The hover text of a secondary channel control, which says why it is dimmed
   * when it is.
   *
   * @param channel The channel the control sends over.
   * @returns The title text.
   */
  protected channelTitle(channel: CodeChannelDescriptor): string {
    return this.unavailableChannels().has(channel.key)
      ? `${channel.label} cannot reach this number`
      : channel.label
  }

  /**
   * Mirror the identifier field into the machine, which restarts the flow from
   * it.
   *
   * @param event The input event.
   */
  protected updateIdentifier(event: Event): void {
    this.auth().setField('identifier', (event.target as HTMLInputElement).value)
    // A dimmed channel is dimmed about a NUMBER, not about the person: editing
    // the number makes every channel worth asking again.
    this.unavailableChannels.set(new Set())
    this.notice.set(null)
  }

  /**
   * Mirror the password field into the machine.
   *
   * @param event The input event.
   */
  protected updatePassword(event: Event): void {
    this.auth().setField('password', (event.target as HTMLInputElement).value)
  }

  /**
   * Mirror the one-time code field into the machine.
   *
   * @param event The input event.
   */
  protected updateCode(event: Event): void {
    this.auth().setField('code', (event.target as HTMLInputElement).value)
  }

  /**
   * Mirror the new-password field into the machine.
   *
   * @param event The input event.
   */
  protected updateNewPassword(event: Event): void {
    this.auth().setField(
      'newPassword',
      (event.target as HTMLInputElement).value,
    )
  }

  /**
   * Mirror the consent checkbox into the machine.
   *
   * @param event The change event.
   */
  protected updateConsent(event: Event): void {
    this.auth().setField(
      'consentAccepted',
      (event.target as HTMLInputElement).checked,
    )
  }

  /**
   * Hand the step's form to the machine.
   *
   * @param event The submit event, whose default reload is what a step never
   *   wants: the surface owns no route.
   */
  protected submit(event: Event): void {
    event.preventDefault()
    void this.auth().submit()
  }

  protected resend(): void {
    void this.auth().resend()
  }

  /**
   * Hand off to an icon method's ceremony.
   *
   * @param key The chosen method key.
   */
  protected chooseMethod(key: string): void {
    void this.auth().chooseMethod(key)
  }

  /**
   * Send the code over one channel — choosing it IS the send.
   *
   * @param key The chosen channel key.
   */
  protected chooseChannel(key: string): void {
    void this.auth().chooseChannel(key)
  }

  // The key icon: enter recovery and ask for the code in one move, so the first
  // send and every re-send afterwards travel the same path.
  protected startRecovery(): void {
    const auth = this.auth()
    auth.startRecovery()
    void auth.resend()
  }

  // "Not that address?": give up the registration this session started — every
  // tab of it goes back to the field — while the hold on the address itself is
  // left alone, so one session cannot free an address another is registering.
  // What was typed survives; only the reservation is dropped.
  protected abandon(): void {
    void this.authActions().abandonRegistration()
    this.auth().backToIdentifier()
  }

  // The way back into a code this browser is already holding, offered by the
  // screen a return to a held address draws. Purely local: the code is already
  // in flight, so nothing is sent and no second letter is ordered.
  protected resumeHeldRegistration(): void {
    this.auth().resumeHeldRegistration()
  }

  // The consent screen's way back. Nothing was reserved yet, so unlike "not that
  // address?" there is nothing to give up — this is the plain return to the
  // field.
  protected backToIdentifier(): void {
    this.auth().backToIdentifier()
  }

  // Cancel a running ceremony: the machine aborts its signal, so the device
  // dialog closes rather than being left open for a late finger to satisfy.
  protected cancelMethod(): void {
    this.auth().cancelMethod()
  }

  // Continue on a finished flow: clear the announcement on the server, then let
  // the gate play out the resume it was holding — closing the surface and
  // un-gating the page in one move.
  //
  // Only when the server heard it. The ack is a ROW (HIL-422), so a surface
  // closed over a refused dispatch would leave the mark standing and meet the
  // person again with the same panel on the next handshake — while the error
  // explaining why is gone with the screen that held it.
  protected continueFromDone(): void {
    const auth = this.auth()
    void auth.submit().then(() => {
      if (auth.error.get() === null) {
        this.gate?.dismiss()
      }
    })
  }

  /**
   * A server moment read as the `m:ss` still to run, or null once it is spent.
   *
   * @param moment The local-scale epoch-ms moment, or null when nothing is
   *   armed.
   * @returns The remaining span as a clock, or null.
   */
  private remaining(moment: number | null): string | null {
    if (moment === null) {
      return null
    }
    const left = moment - this.now()
    if (left <= 0) {
      return null
    }
    const seconds = Math.ceil(left / MS_PER_SECOND)

    return (
      Math.floor(seconds / SECONDS_PER_MINUTE) +
      ':' +
      String(seconds % SECONDS_PER_MINUTE).padStart(2, '0')
    )
  }

  /**
   * Whether a converge is about the identifier this surface is waiting on.
   *
   * The server converges on the NORMALIZED identifier (a lowercased address), so
   * the comparison is case-insensitive and also accepts the normalized form the
   * lookup answered with — otherwise a person who typed their address in
   * capitals would never be told their own registration finished elsewhere.
   *
   * Both sides are read from the MACHINE rather than from the mirrors: the
   * listener is registered once and would otherwise compare against whatever was
   * typed when it was, which is nothing.
   *
   * @param auth The machine the listener was registered for.
   * @param identifier The identifier the converge names.
   * @returns Whether it is the one on screen.
   */
  private isCurrentIdentifier(auth: AuthFlow, identifier: string): boolean {
    const typed = auth.form.get().identifier.trim().toLowerCase()
    const normalized =
      auth.detection.get().result?.normalized.toLowerCase() ?? null
    const converged = identifier.trim().toLowerCase()

    return converged === typed || converged === normalized
  }

  /**
   * Show the pending link the collision arm armed: pre-fill the colliding address
   * and ask the person to sign in with a method they already have (HIL-282).
   *
   * @param auth The machine the pre-filled address is written into.
   */
  private promptToFinishLink(auth: AuthFlow): void {
    const pendingLink = this.oauth().peekOAuthLink()
    this.linkPrompt.set(pendingLink !== null)
    if (pendingLink !== null) {
      auth.setField('identifier', pendingLink.email)
    }
  }

  /**
   * Apply what the server says this surface should be showing, whoever asked for
   * it: a converge about the address being waited on, or an ack this connection
   * still owes its person.
   *
   * @param auth The machine the patch is applied to.
   * @param step The step off the wire.
   * @param intent The intent off the wire.
   * @param code The semantic reason of a rollback, or null.
   */
  private applyFromServer(
    auth: AuthFlow,
    step: unknown,
    intent: unknown,
    code: string | null,
  ): void {
    const patch = toFlowPatch(step, intent)
    if (patch === null) {
      return
    }
    auth.applyExternal(patch)
    this.notice.set(code === null ? null : (CODE_MESSAGES[code] ?? null))
  }
}
