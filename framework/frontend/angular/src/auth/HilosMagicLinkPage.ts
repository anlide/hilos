// The magic-link confirm relay (HIL-283), the Angular peer of
// framework/frontend/react/src/auth/HilosMagicLinkPage.tsx and
// framework/frontend/vue/src/auth/HilosMagicLinkPage.vue. The emailed sign-in
// link opens this static SPA route (/auth/magic?email=…&token=…); the app has
// already opened its single live connection (subscribed to the main page through
// the router fallback), so this view relays the token as the CONFIRM_MAGIC_LINK
// action the request-only auth surface never sends. A valid token upgrades the
// session on the backend; this then navigates home, where the now-authenticated
// main page renders. A missing or rejected token shows a generic error with a way
// back to sign-in — no token detail is disclosed.
//
// The route is entered by a click in a mail client, so it loads cold: the confirm
// is held behind the framework's page-ready gate until the connection can carry it
// (HIL-607, in `authActions`). Waiting is the one thing that can go on forever, so
// this screen carries the backstop the gate deliberately does not — the same 20s
// the OAuth callback relay uses — and offers Try again, which repeats the step
// whole. The link is not spent by a wait that failed: a token is consumed only by
// a successful check, so a retry is the one action that actually helps here.
// Bootstrap classes only, no CSS of its own (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import { createAuthActions, whenPageReady } from '@hilos/core'
import type { HilosAuthActions, HilosAuthContext } from '@hilos/core'

import { HILOS_ROUTER } from '../hilosRouterToken.js'

/**
 * The home path the successful sign-in lands on; also the target of the "back to
 * sign-in" link, where the auth surface shows for the still-anonymous session.
 */
const HOME_PATH = '/'

// Client-side backstop on the wait for a connection that can carry the confirm:
// the page-ready gate parks forever by design, so if no page is ever answered,
// give the wait up rather than let the spinner wedge. The same value as
// HilosOAuthCallbackPage's CALLBACK_TIMEOUT_MS — the two relays wait on the same
// thing and there is no reason for one to be more patient than the other.
const MAGIC_LINK_TIMEOUT_MS = 20000

// The generic sentence for a confirm that never reached the server, matching what
// `describeAuthError` shows for a dropped connection: here it is the honest
// report it was not, since the frame really did fail to leave.
const UNREACHABLE_MESSAGE = 'Could not reach the server. Please try again.'

/** What a rejected, malformed or given-up link says. */
const INVALID_MESSAGE = 'This sign-in link is invalid or expired.'

/** What a link missing half of itself says — a retry cannot help that. */
const INCOMPLETE_MESSAGE = 'This sign-in link is invalid or incomplete.'

/**
 * Wait for a connection that can carry the confirm, and give the wait up after
 * the backstop. Giving up has to end the ATTEMPT, not merely the spinner: a wait
 * abandoned on screen but still pending underneath would dispatch whenever the
 * connection eventually settled, spend the one-time token on a screen already
 * showing an error, and leave Try again nothing left to succeed with.
 *
 * @returns True when a page answered in time, false when the wait was given up on.
 */
function waitForConnection(): Promise<boolean> {
  return new Promise<boolean>((resolve) => {
    const timer = setTimeout(() => resolve(false), MAGIC_LINK_TIMEOUT_MS)
    void whenPageReady().then(() => {
      clearTimeout(timer)
      resolve(true)
    })
  })
}

/** The route an emailed sign-in link opens: relay the token, then go home. */
@Component({
  selector: 'hilos-magic-link-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section
      data-id="auth-magic"
      class="mx-auto text-center"
      style="max-width: 24rem"
    >
      @if (status() === 'verifying') {
        <div role="status" data-id="auth-magic-verifying">
          <span class="spinner-border" role="status" aria-hidden="true"></span>
          <p class="mt-3">Signing you in…</p>
        </div>
      } @else {
        <div class="alert alert-danger" role="alert" data-id="auth-magic-error">
          {{ message() }}
        </div>
        <div class="d-flex justify-content-center gap-3">
          @if (retryable()) {
            <button
              type="button"
              class="btn btn-link p-0"
              data-id="auth-magic-retry"
              (click)="retry()"
            >
              Try again
            </button>
          }
          <button
            type="button"
            class="btn btn-link p-0"
            data-id="auth-magic-to-login"
            (click)="backToSignIn()"
          >
            Back to sign in
          </button>
        </div>
      }
    </section>
  `,
})
export class HilosMagicLinkPage {
  /** The project context the relay dispatches the confirm over. */
  readonly context = input.required<HilosAuthContext>()

  // The framework wire, bound to that context: this relay dispatches the confirm
  // the sign-in surface itself never sends (its magic-link entry is
  // request-only).
  private readonly authActions = computed(() =>
    createAuthActions(this.context()),
  )

  private readonly router = inject(HILOS_ROUTER)

  // The relay outcome: `verifying` while the token is in flight, `error` once it
  // is rejected, malformed, or given up on. A success navigates away, so it needs
  // no visible state.
  protected readonly status = signal<'verifying' | 'error'>('verifying')
  protected readonly message = signal('')

  // Whether the failure on screen is one a retry can do anything about. A
  // malformed link is not — nothing in the URL will change by asking again — so
  // it shows the way back to sign-in alone.
  protected readonly retryable = signal(false)

  // The link as it arrived, read once at mount and kept so Try again can repeat
  // the step from the same URL — the query is not re-read, because nothing
  // navigated.
  private link = { email: '', token: '' }

  // Which attempt is the one on screen. Taking it is how a new attempt
  // supersedes whatever is still in flight; see `runConfirm`.
  private attempt = 0

  constructor() {
    effect((onCleanup) => {
      const params = new URLSearchParams(window.location.search)
      this.link = {
        email: params.get('email') ?? '',
        token: params.get('token') ?? '',
      }
      if (this.link.email === '' || this.link.token === '') {
        this.showError(INCOMPLETE_MESSAGE, false)

        return
      }

      void this.runConfirm(this.authActions())

      onCleanup(() => {
        // Whatever is in flight belongs to a view that is going away.
        this.attempt += 1
      })
    })
  }

  protected retry(): void {
    void this.runConfirm(this.authActions())
  }

  protected backToSignIn(): void {
    this.router.navigate(HOME_PATH)
  }

  /**
   * Run the step whole: wait for a connection that can carry the confirm,
   * dispatch it, and show the outcome. This is what mount runs and what Try again
   * runs again — a retry that skipped the wait would be the very bug this screen
   * is here for.
   *
   * Each call takes the attempt token, and every resume point checks it is still
   * the holder. The guard lives HERE rather than in the callers because both of
   * them need it for the same reason: the wait is up to twenty seconds long and
   * the token is one-time, so an attempt that has been superseded — by Try again,
   * or by the view going away — must not spend it and must not navigate out of a
   * page the person has already left.
   *
   * @param authActions The wire the confirm is dispatched over.
   */
  private async runConfirm(authActions: HilosAuthActions): Promise<void> {
    this.attempt += 1
    const mine = this.attempt
    this.status.set('verifying')
    this.message.set('')
    if (!(await waitForConnection())) {
      if (this.attempt === mine) {
        this.showError(UNREACHABLE_MESSAGE, true)
      }

      return
    }
    if (this.attempt !== mine) {
      return
    }

    const outcome = await authActions.confirmMagicLink(
      this.link.email,
      this.link.token,
    )
    if (this.attempt !== mine) {
      return
    }
    if (outcome.ok) {
      this.router.navigate(HOME_PATH)

      return
    }
    this.showError(outcome.message ?? INVALID_MESSAGE, true)
  }

  /**
   * Put the view on the error panel.
   *
   * @param reason The sentence to show.
   * @param canRetry Whether Try again is offered with it.
   */
  private showError(reason: string, canRetry: boolean): void {
    this.status.set('error')
    this.message.set(reason)
    this.retryable.set(canRetry)
  }
}
