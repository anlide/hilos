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
  AUTH_SURFACE_HEADING_ID,
  SIGNAL_HANDSHAKE_RESPONSE,
  authAckToFlowPatch,
  authConvergeSignalSchema,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  createAuthActions,
  createAuthFlow,
  createOAuthLogin,
  hilosCodeSendProgress,
  handshakeResponseAck,
  oauthTrip,
  oauthTripMessage,
  oauthTripTitle,
  MAGIC_LINK_FLOW_METHOD,
  PASSKEY_FLOW_METHOD,
  PASSWORD_METHOD_KEY,
  PASSWORD_MIN_LENGTH,
  sessionCodeDelivery,
  sessionPendingAck,
  sessionPendingAuthStep,
  shouldLowerAckPanel,
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
  CodeDelivery,
  CodeSendProgress,
  DetectionState,
  HilosAuthContext,
  PendingAuthStep,
  ProjectSignal,
  ReadonlySignal,
} from '@hilos/core'

import { HilosFormError } from '../HilosFormError.js'
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
  sendProgress: null,
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
  proven_identifier: 'Your address is confirmed',
  terms: 'Terms and privacy',
  confirm_identifier: 'Confirm your email',
  enter_code: 'Enter the code',
  reset_code: 'Reset your password',
  choose_password: 'Choose a new password',
  set_first_password: 'Choose a password',
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
  proven_identifier: 'Choose a password',
  terms: 'Create account',
  confirm_identifier: 'Confirm',
  enter_code: 'Continue',
  reset_code: 'Continue',
  choose_password: 'Save password',
  set_first_password: 'Save password',
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
  reservation_expired: 'That registration expired. Ask for a new code.',
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
 * The sentences the screen hard-codes in its markup. They live here because the
 * live region says them a second time, and two copies of a sentence drift. The
 * letter's line is split around the address the visible block prints in bold;
 * the expired one is the SCREEN's news rather than a refusal of anything the
 * person did, which is why the calm region is where it is said (HIL-828).
 */
const LINK_PROMPT_MESSAGE =
  'That email already has an account. Sign in to finish linking it.'
const LINK_SENT_LEAD = "We've sent a sign-in link to"

/**
 * How the code screen says where the code has got to (HIL-826). The states
 * travel as stable keys and the copy lives here, the way the outcome reasons
 * already work; only the provider's refusal sentence comes off the wire as
 * words, because they are not ours to phrase.
 */
const SEND_PROGRESS_COPY: Record<
  string,
  { icon: string; tone: string; text: (target: string) => string }
> = {
  [CODE_SEND_STATE_QUEUED]: {
    icon: 'bi-hourglass-split',
    tone: 'text-body-secondary',
    text: () => 'Queued for sending…',
  },
  [CODE_SEND_STATE_SENDING]: {
    icon: 'bi-arrow-repeat',
    tone: 'text-primary',
    text: (target) => `Sending to ${target}…`,
  },
  [CODE_SEND_STATE_SENT]: {
    icon: 'bi-check-circle-fill',
    tone: 'text-success',
    text: (target) => `Sent to ${target}`,
  },
  [CODE_SEND_STATE_FAILED]: {
    icon: 'bi-exclamation-triangle-fill',
    tone: 'text-danger',
    text: () => 'Could not send',
  },
  [CODE_SEND_STATE_NOT_SENT]: {
    icon: 'bi-flask',
    tone: 'text-body-secondary',
    text: (target) =>
      `Not really sent to ${target} — letters are written here, not mailed`,
  },
}

/**
 * The line under the identifier row: where the code being waited for has got to,
 * or null when there is nothing to say (HIL-826).
 *
 * A missing line is a legal state and reads as silence, never as an error - the
 * server takes it away with an empty frame, and a state this build has no words
 * for is treated the same way rather than drawn as a raw key.
 *
 * @param progress The reported step, or null when the session is owed no line.
 * @param target The address or number the code is going to.
 * @returns What to draw, or null when there is nothing to draw.
 */
function sendProgressLine(
  progress: CodeSendProgress | null,
  target: string,
): { icon: string; tone: string; text: string } | null {
  if (progress === null) {
    return null
  }
  const copy = SEND_PROGRESS_COPY[progress.state]
  if (copy === undefined) {
    return null
  }
  const text = copy.text(target)

  return {
    icon: copy.icon,
    tone: copy.tone,
    text: progress.detail === null ? text : `${text}: ${progress.detail}`,
  }
}
const LINK_SENT_TAIL = 'Open it to continue.'
const CODE_EXPIRED_MESSAGE = 'That code has expired.'

/**
 * The identifier-first sign-in surface: one field, and whatever the lookup makes
 * of it.
 */
@Component({
  selector: 'hilos-auth-surface',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosFormError, LoadingButton],
  template: `
    <section data-id="auth-surface" class="mx-auto" style="max-width: 24rem">
      <h2 [id]="headingId" class="h5 mb-3" data-id="auth-heading">
        {{ heading() }}
      </h2>

      <!-- The two live regions of the screen, declared in advance and on the
      section rather than inside a form: the five forms replace one another as
      the machine steps, so a region living in one of them would die with its
      step — the very illness this cures. Two of them, because only a refusal is
      allowed to interrupt what the listener is hearing. The visible blocks below
      carry the same words for the eye and no role of their own. -->
      <div
        class="visually-hidden"
        role="alert"
        aria-live="assertive"
        data-id="auth-live-assertive"
      >
        {{ errorMessage() }}
      </div>
      <div
        class="visually-hidden"
        role="status"
        aria-live="polite"
        data-id="auth-live-polite"
      >
        @for (item of announcedNews(); track item.key) {
          <div>{{ item.text }}</div>
        }
      </div>

      <!-- OAuth email-collision re-auth prompt (HIL-282): the provider address
      already has an account, so ask the person to sign in with an existing
      method to finish linking. The pending link token is redeemed globally once
      the session upgrades. -->
      @if (linkPrompt() && state().step === 'identifier') {
        <div class="alert alert-info py-2" data-id="auth-link-prompt">
          {{ linkPromptMessage }}
        </div>
      }

      <!-- News about a move nobody on this screen asked for. Its own region,
      above the form: it is not this step's refusal, and the step it lands on is
      usually the identifier field, where the error region belongs to what is
      typed next. -->
      @if (notice(); as message) {
        <div class="alert alert-warning py-2" data-id="auth-notice">
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
            <!-- One room under the field for the whole conversation with it: the
            reveal when the reply is an account that signs in with a password, the
            grey line otherwise, and nothing while the first lookup runs. The
            reveal is the tallest of the three, so an invisible twin of it holds
            the room and the line lies over that twin — the content it covers is
            the twin and nothing else (styling-rules.md, "The room a live message
            takes"). A free address has no row here at all: its one way on is the
            main button (mockup node new_email). A found account has one because
            the envelope and the key walk past the FIELD standing beside them —
            with no field there is nothing to walk past. -->
            <div class="position-relative" data-id="auth-reveal-slot">
              @if (showPassword()) {
                <label class="form-label small fw-semibold" for="auth-password">
                  Password
                </label>
                <div class="d-flex align-items-center gap-2">
                  <input
                    id="auth-password"
                    type="password"
                    class="form-control"
                    autocomplete="current-password"
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
                      <i
                        [class]="methodIcon(method.key)"
                        aria-hidden="true"
                      ></i>
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
              } @else {
                @if (roomHeld()) {
                  <div
                    class="invisible"
                    aria-hidden="true"
                    data-id="auth-reveal-idle"
                  >
                    <!-- Spans and divs where the real row has a control: the twin
                    holds room, it does not take focus or name anything. The label
                    stays a label element because Bootstrap reboot makes that one
                    inline-block, and a div in its place is two pixels shorter —
                    which is a jump, since this is what the room is measured by.
                    One icon is enough: the row is a flex of equally tall things,
                    so their number is not its height. -->
                    <label class="form-label small fw-semibold">Password</label>
                    <div class="d-flex align-items-center gap-2">
                      <div class="form-control">&nbsp;</div>
                      <span class="btn position-relative btn-outline-secondary">
                        <span
                          ><i class="bi bi-envelope" aria-hidden="true"></i
                        ></span>
                      </span>
                    </div>
                  </div>
                }
                @if (identifierHint(); as hint) {
                  <div
                    class="form-text"
                    [class.position-absolute]="roomHeld()"
                    [class.top-0]="roomHeld()"
                    [class.start-0]="roomHeld()"
                    [class.w-100]="roomHeld()"
                    data-id="auth-identifier-hint"
                  >
                    {{ hint }}
                  </div>
                }
              }
            </div>
          </div>

          <hilos-form-error [message]="errorMessage()" dataId="auth-error" />

          <!-- The main control is whatever the machine says it is: the submit, a
          passwordless method promoted to the button, or a code channel — for a
          phone the channel choice IS the send, so there is no separate button.
          Both resume controls act on the reply the reveal is drawn from, and that
          reply is HELD while a new lookup runs (HIL-646), so they go out for as
          long as the machine is re-asking about it, exactly as the submit does.
          Their gate is the disabled input and not the loading one: pending is
          set on the keystroke, before the debounce, and the spinner delay equals
          that debounce, so a spinner would blink on every pause in typing. -->
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
              hilosLoadingButton
              type="button"
              class="btn-primary w-100"
              [disabled]="detection().status !== 'resolved'"
              data-id="auth-resume-code"
              (click)="resumeHeldRegistration()"
            >
              {{ submitLabel() }}
            </button>
          } @else if (primaryAction()?.kind === 'resume_password') {
            <button
              hilosLoadingButton
              type="button"
              class="btn-primary w-100"
              [disabled]="detection().status !== 'resolved'"
              data-id="auth-resume-password"
              (click)="resumeProvenRegistration()"
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
                    [title]="'Send the code via ' + other.label"
                    [attr.data-id]="channelDataId(other.key)"
                    (click)="chooseChannel(other.key)"
                  >
                    <i [class]="channelIcon(other.key)" aria-hidden="true"></i>
                  </button>
                }
              </div>
              @if (channelUnavailableLines().length > 0) {
                <div
                  class="small text-body-secondary mt-2"
                  data-id="auth-channel-unavailable"
                >
                  @for (line of channelUnavailableLines(); track line.key) {
                    <div
                      [attr.data-id]="'auth-channel-unavailable-' + line.key"
                    >
                      {{ line.text }}
                    </div>
                  }
                </div>
              }
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

          <hilos-form-error [message]="errorMessage()" dataId="auth-error" />

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
          </div>

          @if (deliveredChannel(); as channel) {
            <p
              class="text-body-secondary small mb-3"
              data-id="auth-delivered-channel"
            >
              Sent via {{ channel }}.
            </p>
          }

          @if (sendProgress(); as progress) {
            <div
              class="d-flex align-items-center gap-2 small mb-3"
              [class]="progress.tone"
              data-id="auth-send-progress"
            >
              <i class="bi" [class]="progress.icon" aria-hidden="true"></i>
              <span>{{ progress.text }}</span>
            </div>
          }

          <!-- The letter went out with two ways back in it, so the screen says
          so before it asks for one: the link is still the shorter road for
          whoever can click it, and the field below is for whoever cannot. -->
          @if (screenKey() === 'check_inbox') {
            <div
              class="alert alert-success small py-2"
              data-id="auth-link-sent"
            >
              <i class="bi bi-envelope-check me-1" aria-hidden="true"></i>
              {{ linkSentLead }}
              <strong>{{ form().identifier }}</strong
              >. {{ linkSentTail }}
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

          <hilos-form-error [message]="errorMessage()" dataId="auth-error" />

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

          <!-- The way out, and the last thing on the card because it is the
          answer to "not this, then": a registration says so out loud and in red,
          and what it cancels is freed - the same address typed again starts
          over. A sign-in or a recovery has nothing to give back, so it says Back
          and wears the word the consent step already uses for the same move
          (HIL-829). -->
          @if (state().intent === 'register') {
            <button
              type="button"
              class="btn btn-link btn-sm w-100 text-danger"
              data-id="auth-cancel-registration"
              (click)="cancelRegistration()"
            >
              Cancel registration
            </button>
          } @else {
            <button
              type="button"
              class="btn btn-link btn-sm w-100"
              data-id="auth-restart"
              (click)="cancelRegistration()"
            >
              Back
            </button>
          }
        </form>
      } @else if (state().step === 'code_expired') {
        <!-- The same screen after its countdown ran out (HIL-828). The heading
        and the address block above do not move - the person is still doing the
        thing they came to do - and what changes is everything under them: no
        field, no Confirm, one line saying the code is dead and one button
        offering a new one. Not a form: there is nothing here to submit. -->
        <div>
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
          </div>

          <div
            class="alert alert-warning small py-2 mb-3"
            data-id="auth-code-expired"
          >
            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
            {{ codeExpiredMessage }}
          </div>

          <!-- The gate outlives the code it was armed for: it belongs to the
          address, so a person cannot spend a code, watch it expire and re-take
          the address inside the cooldown the gate exists to hold. -->
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
              hilosLoadingButton
              type="button"
              class="btn-primary w-100 mb-2"
              [loading]="pending()"
              data-id="auth-code-renew"
              (click)="renewCode()"
            >
              <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>
              Send a new code
            </button>
          }

          <!-- The way out, and the last thing on the card because it is the
          answer to "not this, then": a registration says so out loud and in red,
          and what it cancels is freed - the same address typed again starts
          over. A sign-in or a recovery has nothing to give back, so it says Back
          and wears the word the consent step already uses for the same move
          (HIL-829). -->
          @if (state().intent === 'register') {
            <button
              type="button"
              class="btn btn-link btn-sm w-100 text-danger"
              data-id="auth-cancel-registration"
              (click)="cancelRegistration()"
            >
              Cancel registration
            </button>
          } @else {
            <button
              type="button"
              class="btn btn-link btn-sm w-100"
              data-id="auth-restart"
              (click)="cancelRegistration()"
            >
              Back
            </button>
          }
        </div>
      } @else if (state().step === 'set_password') {
        <!-- One screen for two endings (HIL-825): a recovery writes the new
        password of an account that exists, a registration CREATES the account on
        the address it just proved. The address is not asked for again either
        way — what the accepted code left on this session names it. -->
        <form novalidate (submit)="submit($event)">
          <p class="text-body-secondary small mb-3">{{ setPasswordLead() }}</p>

          <!-- The address, for the password manager and for nobody else: a saved
          entry with no login against it is an entry its owner cannot use. Hidden
          rather than absent, because what the manager files the password under is
          the field beside it. -->
          <input
            type="text"
            hidden
            autocomplete="username"
            data-id="auth-username"
            [value]="form().identifier"
          />

          <div class="mb-3">
            <label class="form-label small fw-semibold" for="auth-new-password">
              {{ newPasswordLabel() }}
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

          <hilos-form-error [message]="errorMessage()" dataId="auth-error" />

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

          <!-- Two ways to FINISH the registration, then the way to drop it, and
          the order carries that meaning (HIL-1008). Unemphasized rather than a
          second primary: choosing a password is still the road this screen is
          named after. -->
          @if (showFinishWithoutPassword()) {
            <button
              type="button"
              class="btn btn-link btn-sm w-100"
              [disabled]="pending()"
              data-id="auth-complete-passwordless"
              (click)="completeWithoutPassword()"
            >
              Create it without a password
            </button>
          }

          <!-- The same way out the code screen carries, for the same reason:
          this screen has no address field and no step behind it, so whoever
          changed their mind here would otherwise be shut in (HIL-825). The hold
          is alive and proved at this point, so on a registration the cancel is
          the one that really gives the address back. -->
          @if (state().intent === 'register') {
            <button
              type="button"
              class="btn btn-link btn-sm w-100 text-danger"
              data-id="auth-cancel-registration"
              (click)="cancelRegistration()"
            >
              Cancel registration
            </button>
          } @else {
            <button
              type="button"
              class="btn btn-link btn-sm w-100"
              data-id="auth-restart"
              (click)="cancelRegistration()"
            >
              Back
            </button>
          }
        </form>
      } @else if (state().step === 'external') {
        <!-- Parked on a ceremony. A link waits on the inbox, everything else
        waits on the device; both are the same step and both can be taken back —
        cancelling ends the ceremony itself rather than merely forgetting its
        outcome. -->
        @if (screenKey() === 'check_inbox') {
          <div class="alert alert-success small py-2">
            <i class="bi bi-envelope-check me-1" aria-hidden="true"></i>
            {{ linkSentLead }}
            <strong>{{ form().identifier }}</strong
            >. {{ linkSentTail }}
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

        <hilos-form-error [message]="errorMessage()" dataId="auth-error" />

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
  // A module constant is invisible to an Angular template, so the id the frame
  // names this surface by reaches the markup through a field.
  protected readonly headingId = AUTH_SURFACE_HEADING_ID

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
  // What this installation can send a one-time code to, derived the same way and
  // for the same reason (HIL-830): the browser half cannot know the backend's
  // mail and channel configuration, so it is told rather than asked.
  private readonly codeDeliverySignal = computed(() =>
    sessionCodeDelivery(this.context().scopes),
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
  protected readonly canFinishWithoutPassword = signal(false)
  protected readonly icons = signal<readonly AuthFlowMethodDescriptor[]>([])
  protected readonly channels = signal<readonly CodeChannelDescriptor[]>([])
  protected readonly primaryAction = signal<AuthFlowPrimaryAction>(null)
  protected readonly screenKey = signal<AuthFlowScreen>('sign_in')
  protected readonly resendAvailableAt = signal<number | null>(null)
  protected readonly expiresAt = signal<number | null>(null)
  protected readonly ack = signal<string | null>(null)
  // Everything deliverable until a handshake says otherwise, which is what an
  // unanswered installation has always behaved like.
  protected readonly codeDelivery = signal<CodeDelivery>({
    email: true,
    phone: true,
  })

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

  // The line is bound at boot (HIL-826), so what a mounting surface reads is the
  // value already held rather than the next frame to arrive - which is the whole
  // of the reload case, where the handshake was answered before this component
  // existed. The machine is told at once and on every change, and it owns the
  // lifetime: what the line stops being about is a decision about the code, not
  // about a tab.
  private readonly reportedProgress = hilosSignal(hilosCodeSendProgress)

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

  // The ack the mark effect last saw, for the same reason: it is the TRANSITION
  // to an empty mark that closes a finished panel, and the first binding has no
  // transition behind it.
  private previousAck: string | null = null
  // Whether THIS panel was raised by the mark. A handshake that says the session
  // owes nothing lowers only that panel, never the initiator's: they stand on
  // done from the action reply before the frame carrying the mark arrives
  // (HIL-955). A plain field, not a signal — the screen does not draw it.
  private panelRaisedByAck = false

  protected readonly heading = computed(() =>
    this.screenKey() === 'confirm_identifier' &&
    this.state().identifierKind === 'phone'
      ? 'Confirm your number'
      : HEADINGS[this.screenKey()],
  )

  protected readonly submitLabel = computed(
    () => SUBMIT_LABELS[this.screenKey()],
  )

  // The way past the password, gated by both halves of the same question: whether
  // the project mounted a passwordless way in that serves an address (the machine
  // knows the registry) and whether this installation can mail at all (the
  // handshake answers that, the same key the recovery key reads). Either half
  // missing and the exit would create an account nobody could get back into.
  protected readonly showFinishWithoutPassword = computed(
    () => this.canFinishWithoutPassword() && this.codeDelivery().email,
  )

  // The password screen says which of its two endings this is. A recovery is
  // replacing a password that exists; a registration is about to create the
  // account, and the sentence has to say so before the button does (HIL-825).
  // A registration that is ALSO offered the way past the password says so here
  // too (HIL-1008): the sentence is the only place the second road is explained,
  // and promising it where the exit is not offered would be a lie.
  protected readonly setPasswordLead = computed<string>(() => {
    if (this.state().intent !== 'register') {
      return 'The code was accepted. Choose a new password.'
    }

    return this.showFinishWithoutPassword()
      ? 'Your address is confirmed. Choose a password, or create the account without one and sign in by a mailed link instead.'
      : 'Your address is confirmed. Choose a password — your account is created when you save it.'
  })

  protected readonly newPasswordLabel = computed(() =>
    this.state().intent === 'register' ? 'Password' : 'New password',
  )

  // An account this installation offers no way into, said as the refusal it is
  // (HIL-973): the person cannot get into their OWN account, so the sentence
  // goes to the refusal row and not to the calm hint under the field. Why it is
  // empty was resolved on the backend, never by comparing flags here.
  private readonly signInRefusal = computed<string | null>(() => {
    const result = this.detection().result
    if (
      this.state().step !== 'identifier' ||
      result === null ||
      result.status !== 'active' ||
      result.signInBlock !== 'no_channel'
    ) {
      return null
    }

    return result.kind === 'phone'
      ? 'This account signs in by a code, and this installation has nothing to send it with. Whoever runs it can set that up.'
      : 'This account signs in by a mailed link, and this installation has nothing to send it with. Whoever runs it can set that up.'
  })

  // The inline refusal: the backend's own sentence when it sent one, its
  // semantic code turned into ours when it did not, and an account left with no
  // way in when no action was refused.
  protected readonly errorMessage = computed<string | null>(() => {
    const shown = this.error()
    if (shown === null) {
      return this.signInRefusal()
    }

    return (
      shown.message ??
      (shown.code === null ? null : CODE_MESSAGES[shown.code]) ??
      GENERIC_ERROR
    )
  })

  // A module constant is invisible to the template, so the two sentences the
  // visible blocks print reach it as fields — the way the rest of this file
  // already hands its tables over.
  protected readonly linkPromptMessage = LINK_PROMPT_MESSAGE
  protected readonly linkSentLead = LINK_SENT_LEAD
  protected readonly linkSentTail = LINK_SENT_TAIL
  protected readonly codeExpiredMessage = CODE_EXPIRED_MESSAGE

  // What the calm region says: the screen's news, in the order they stand on it
  // from the top down. More than one can be true at once, so it is a list and
  // not a sentence — each line keyed by the source it came from, the way the
  // toast stack keys its cards.
  protected readonly announcedNews = computed<
    readonly { key: string; text: string }[]
  >(() => {
    const news: { key: string; text: string }[] = []

    if (this.linkPrompt() && this.state().step === 'identifier') {
      news.push({ key: 'link_prompt', text: LINK_PROMPT_MESSAGE })
    }
    const moved = this.notice()
    if (moved !== null) {
      news.push({ key: 'notice', text: moved })
    }
    if (this.screenKey() === 'check_inbox') {
      news.push({
        key: 'link_sent',
        text: `${LINK_SENT_LEAD} ${this.form().identifier}. ${LINK_SENT_TAIL}`,
      })
    }
    // The send line is news by nature - it changes under a person who is not
    // touching anything - and it is the one thing on this screen that says why
    // nothing has arrived yet.
    const progress = this.sendProgress()
    if (progress !== null && this.state().step === 'code') {
      news.push({ key: 'send_progress', text: progress.text })
    }
    if (this.state().step === 'code_expired') {
      news.push({ key: 'code_expired', text: CODE_EXPIRED_MESSAGE })
    }
    // The line under the channel row only shows; this region is what says it aloud,
    // and a block carrying aria-live of its own would be read twice
    // (accessibility.md, "Live regions").
    if (this.state().step === 'identifier') {
      for (const line of this.channelUnavailableLines()) {
        news.push({ key: `channel_unavailable_${line.key}`, text: line.text })
      }
    }

    return news
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

  // The password reveals INSIDE the identifier step for one reply only: an
  // account that signs in with one. A free address does not offer it any more
  // (HIL-825) — a registration asks for a password after the code, on the screen
  // that creates the account, so nobody invents a credential for an inbox they
  // have not proved.
  protected readonly showPassword = computed(() => {
    const result = this.detection().result
    if (
      this.state().step !== 'identifier' ||
      result === null ||
      result.kind !== 'email'
    ) {
      return false
    }

    return (
      result.status === 'active' && result.methods.includes(PASSWORD_METHOD_KEY)
    )
  })

  // The room under the identifier field is taken from the first character typed
  // and never given back while something is in the field: the reveal and the hint
  // are mutually exclusive, so without it the step would change height on every
  // reply the lookup brings (styling-rules.md, "The room a live message takes").
  // An empty field is deliberately outside it - the icon row above leaves with the
  // first character, there is nothing to answer yet, and the longest sentence of
  // all lives there (an installation with no channel at all).
  protected readonly roomHeld = computed(
    () => this.form().identifier.trim() !== '',
  )

  // The recovery key sits beside the password and only for an account that HAS
  // one: there is nothing to reset for an address that signs in by link, and
  // nothing at all for one that has no account yet. Its whole road is mail, so
  // an installation that cannot mail does not offer it (HIL-973) — and says
  // nothing about it, since the password beside it still works.
  protected readonly showRecovery = computed(() => {
    const result = this.detection().result

    return (
      this.showPassword() &&
      result !== null &&
      result.status === 'active' &&
      result.methods.includes(PASSWORD_METHOD_KEY) &&
      this.codeDelivery().email
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
      // Silence unless NEITHER kind can be reached: a deployment with mail and
      // no phone channel would otherwise lie to whoever was about to type the
      // kind that works, and the partial case is named after the kind is known.
      const delivery = this.codeDelivery()

      return delivery.email || delivery.phone
        ? 'Your email address or phone number.'
        : 'Your email address or phone number. New accounts cannot be created here — there is nothing to send a code with.'
    }
    if (this.state().identifierKind === 'unknown') {
      return 'That does not look like an email address or a phone number yet.'
    }
    const result = this.detection().result
    if (result === null) {
      return null
    }
    if (result.status === 'none') {
      if (result.registerable.length > 0) {
        return 'No account yet — this creates one.'
      }

      // Two reasons, two sentences: a locked door somebody locked, and a
      // deployment that simply has nothing to send with. Which one it is was
      // resolved on the backend, never by comparing flags here (HIL-830).
      return result.registrationBlock === 'no_channel'
        ? 'No account for this, and there is nothing to send a code with.'
        : 'No account for this, and registration is closed.'
    }
    // A held address has no account to describe — it has a code in flight, and
    // saying so is the whole point of the screen the return lands on (HIL-651).
    if (result.status === 'pending') {
      return result.kind === 'phone'
        ? 'A code is already on its way to this number.'
        : 'A code is already on its way to this address.'
    }
    // A proved one has no code left either: what is owed is the password that
    // creates the account (HIL-825).
    if (result.status === 'proven') {
      return 'You confirmed this address. Choose a password to finish.'
    }

    // An account refused a way in is told so in the refusal row; the same fact
    // in grey here would read as a second problem (HIL-973).
    if (this.signInRefusal() !== null) {
      return null
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

  /**
   * Why each dark channel icon is dark, one line per channel. The icon's title cannot
   * carry it — a disabled button gets no mouse events, so the title never shows — and
   * the line stands visible under the row instead (accessibility.md).
   */
  protected readonly channelUnavailableLines = computed<
    readonly { key: string; text: string }[]
  >(() =>
    this.otherChannels()
      .filter((channel) => this.unavailableChannels().has(channel.key))
      .map((channel) => ({
        key: channel.key,
        text: `${channel.label} cannot reach this number`,
      })),
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

  /**
   * The line under the identifier row: where the code has got to (HIL-826).
   */
  protected readonly sendProgress = computed<{
    icon: string
    tone: string
    text: string
  } | null>(() =>
    sendProgressLine(this.state().sendProgress, this.form().identifier),
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
    // The line the boot binding holds, handed to the machine at once and on every
    // change (HIL-826). Its own effect rather than a line in the mirroring one
    // below, because it runs the other way: that one copies the machine OUT, and
    // this one tells it what the server said.
    effect(() => {
      this.auth().reportSendProgress(this.reportedProgress())
    })
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
        bind(auth.canFinishWithoutPassword, this.canFinishWithoutPassword),
        bind(auth.icons, this.icons),
        bind(auth.channels, this.channels),
        bind(auth.primaryAction, this.primaryAction),
        bind(auth.screenKey, this.screenKey),
        bind(auth.resendAvailableAt, this.resendAvailableAt),
        bind(auth.expiresAt, this.expiresAt),
        bind(this.pendingAck(), this.ack),
        bind(this.codeDeliverySignal(), this.codeDelivery),
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
      // Except for what the server is still saying (HIL-826): the reset empties
      // the flow the line lives on, and the line is not this surface's to forget
      // - it belongs to the session, and the frame that carried it may be
      // minutes old.
      auth.reportSendProgress(hilosCodeSendProgress.get())
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

      const stopWatchingHandshake = context.connection.on(
        'projectSignal',
        (signal: ProjectSignal) => {
          if (signal.type !== SIGNAL_HANDSHAKE_RESPONSE) {
            return
          }
          if (
            !shouldLowerAckPanel({
              ackOnHandshake: handshakeResponseAck(signal.data),
              panelRaisedByAck: this.panelRaisedByAck,
              step: auth.flow.get().step,
            })
          ) {
            return
          }
          this.panelRaisedByAck = false
          auth.reset()
          this.gate?.dismiss()
        },
      )

      const clock = setInterval(() => {
        this.now.set(Date.now())
      }, COUNTDOWN_TICK_MS)

      // Answer an OAuth trip that ended while this screen was parked on it
      // (HIL-633). Only a park is answered: a trip can also be a profile link
      // running in another page of the same tab, and that one is somebody
      // else's wait. A trip on the park answers the person's own click on an
      // icon of THIS screen, so a trip that failed is a refusal of this form and
      // lands on its refusal line (`mockups/components/form-error`, the sign-in
      // card) — the same place a refusal of that click before the trip started
      // lands (HIL-926). The notice region stays for news nobody on the screen
      // asked for (a converge).
      const stopWatchingTrip = this.oauth().subscribeOAuthOutcome((outcome) => {
        if (auth.flow.get().step !== 'external') {
          return
        }
        if (outcome.kind === 'signed_in') {
          // The gate closes this surface on the upgrade; saying anything here
          // would be saying it to a screen already on its way out (HIL-422).
          return
        }
        if (outcome.kind === 'error') {
          auth.failMethod(outcome.message)

          return
        }
        auth.cancelMethod()
        if (outcome.kind === 'reauth_pending') {
          this.promptToFinishLink(auth)
        }
      })

      this.promptToFinishLink(auth)
      // The unfinished registration comes back from the SESSION, not from
      // anything this tab remembers, so a reload, a second tab and another
      // device all resume the same screen. Both pending facts are read from
      // their signals rather than from the mirrors, which the effect must not
      // depend on: a mirror changing would re-run the whole mount.
      auth.resume(this.pendingAuthStep().get())
      // resume() moves the screen but says nothing about WHY it moved, and the
      // why is what the notice draws: without this a tab coming back by reload
      // lands on the identifier field, address filled in, with no word about
      // what happened.
      this.applyReportedStep(auth, this.pendingAuthStep().get())
      const patch = authAckToFlowPatch(this.pendingAck().get())
      if (patch !== null) {
        this.panelRaisedByAck = true
        auth.applyExternal(patch)
      }

      onCleanup(() => {
        stopWatchingChannels()
        stopWatchingConverge()
        stopWatchingHandshake()
        stopWatchingTrip()
        clearInterval(clock)
      })
    })

    // News of a lost race reaches a tab whose socket merely BLINKED, not only
    // one that reloaded: resume() runs in the mount effect and nowhere else
    // (deliberately), so a step arriving on a reconnect would otherwise sit in
    // the session unread. Its own effect, written AFTER the mount one on
    // purpose - that one clears the notice, and effects run in creation order.
    effect((onCleanup) => {
      const auth = this.auth()
      const reported = this.pendingAuthStep()
      onCleanup(
        subscribeSignal(reported, (step) => this.applyReportedStep(auth, step)),
      )
    })

    // A step CHANGE moves focus; a reveal inside the identifier step
    // deliberately does not. The password appears 300ms after a keystroke, and
    // taking the cursor out of the field somebody is still typing in would be
    // the surface fighting them. The field is read alongside the step so the
    // effect re-runs once it is actually in the DOM — the shape HilosModal's
    // focus trap already relies on.
    effect(() => {
      const step = this.state().step
      if (step !== 'done') {
        this.panelRaisedByAck = false
      }
      const field = {
        identifier: this.identifierInput(),
        consent: this.consentInput(),
        code: this.codeInput(),
        // The expired screen has no field left to focus (HIL-828); its one
        // control is the button that orders a new code.
        code_expired: null,
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
    //
    // The mark going empty is answered here too, and on the TRANSITION rather
    // than on the state: the initiator's machine stands on `done` from the
    // server's reply before the frame carrying the mark arrives, and this effect
    // also runs on its first binding — either way a tab reacting to "the mark is
    // empty" would take its own panel away. The step is read off the machine and
    // not off `state()` so that a step change does not re-run the effect
    // (HIL-865). And only from `done`: the tab may have LEFT the panel and be
    // typing an address again, and somebody else's dismissal must not pull that
    // screen out from under them.
    effect(() => {
      const ack = this.ack()
      const previous = this.previousAck
      this.previousAck = ack
      const patch = authAckToFlowPatch(ack)
      if (patch !== null) {
        this.panelRaisedByAck = true
        this.auth().applyExternal(patch)

        return
      }
      if (previous === null || this.auth().flow.get().step !== 'done') {
        return
      }
      // reset() orphans the answers of requests already sent, so the reply to
      // the dispatch that cleared the mark cannot land on the emptied machine.
      // It comes first: dismiss() usually unmounts the surface, and a reset
      // after it would run for nothing wherever the surface does stay (the
      // modal an anonymous session keeps).
      this.panelRaisedByAck = false
      this.auth().reset()
      this.gate?.dismiss()
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

  // The one control of the expired screen (HIL-828). Not the re-send above: the
  // hold on the address died with the code, so this takes the address again.
  protected renewCode(): void {
    void this.auth().renewCode()
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

  // The way out of a code screen, whichever intent opened it: every tab of this
  // session goes back to the field and the server frees what this browser was
  // holding. One call on every path — the surface names no address, because
  // which holds exist is something only the server knows. What was typed
  // survives.
  protected cancelRegistration(): void {
    void this.authActions().cancelRegistration()
    this.auth().backToIdentifier()
  }

  // The way past the password on the screen that asks for one: the account is
  // created here and now, with the mailed link as its way in. The machine
  // dispatches it, so a refusal lands in the error row and rolls the surface back
  // exactly as the password save's does.
  protected completeWithoutPassword(): void {
    void this.auth().finishWithoutPassword()
  }

  // The way back into a code this browser is already holding, offered by the
  // screen a return to a held address draws. Purely local: the code is already
  // in flight, so nothing is sent and no second letter is ordered.
  protected resumeHeldRegistration(): void {
    this.auth().resumeHeldRegistration()
  }

  // And the way on from a return to an address this browser already PROVED.
  // Local for the same reason: the proof is on the hold, so nothing is sent and
  // no code is spent (HIL-825).
  protected resumeProvenRegistration(): void {
    this.auth().resumeProvenRegistration()
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

  // Continue on a finished flow: clear the announcement on the server, then close
  // the surface — dismiss reads the session at that moment, so the panel goes and
  // a 401'd page is let through in one move. The OTHER tabs of the session learn
  // of it the only way they can, by the cleared mark arriving through the
  // projection.
  //
  // Only once the server has said it heard. The ack is a ROW (HIL-422), so a
  // surface closed over a refused dispatch would leave the mark standing and meet
  // the person again with the same panel on the next handshake — while the error
  // explaining why is gone with the screen that held it. Closing on the click
  // instead saves a round trip and costs the ordering the seam leans on: the
  // clearing is no longer provably behind whatever the person does next, and a
  // logout that overtakes it hands the mark to a socket of a session that is
  // already gone (HIL-865, and the seam itself is HIL-875).
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

  /**
   * Apply a step the session was MOVED to rather than one it left off on
   * (HIL-833): the address this browser was registering went to somebody else
   * while it was away, and the handshake says so by naming a REASON on the step.
   *
   * Narrow on purpose. A step with no reason is an ordinary resume — the code
   * screen a session is still standing on — and applying one anywhere but on
   * mount would rebuild the screen under the hands of somebody typing on it.
   *
   * @param auth The machine the step is applied to.
   * @param step The step the session stands on, or `null` when it stands on
   *   none.
   */
  private applyReportedStep(
    auth: AuthFlow,
    step: PendingAuthStep | null,
  ): void {
    if (step === null || step.code === null) {
      return
    }
    this.applyFromServer(auth, step.step, step.intent, step.code)
  }
}
