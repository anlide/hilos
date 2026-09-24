// The "it was not me" relay of a second-factor removal (HIL-494). Every notice
// about a removal carries a link to this static SPA route
// (/auth/second-factor/cancel?token=…); the view relays the token as the cancel
// action and says the outcome in place. No sign-in is involved and none results:
// the token alone names the removal, so the page works in a browser that never
// signed in — the person reading the notice may be anywhere.
//
// The route loads cold from a click in a mail client, so the cancel waits for a
// connection that can carry it and gives the wait up on the connection's own
// verdict that the server cannot be reached (HIL-1044), exactly as the
// magic-link relay does; Try again repeats the step whole. A token is spent only
// by a cancel that worked, so a retry after a failed wait is safe.
// Bootstrap classes only, no CSS of its own (styling-rules.md): the column is the
// grid's, not a width of this page's.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import { createAuthActions, whenPageReadyOrUnreachable } from '@hilos/core'
import type { HilosAuthActions, HilosAuthContext } from '@hilos/core'

import { HILOS_ROUTER } from '../hilosRouterToken.js'

/** Where Continue goes: the home page, which signs the person in if they want to. */
const HOME_PATH = '/'

/**
 * A link the server does not recognize and a link with nothing in it read the
 * same to the person: the removal it named is not there to cancel.
 */
const LINK_DEAD_MESSAGE = 'This link no longer works.'

/** The sentence for a cancel that never reached the server. */
const UNREACHABLE_MESSAGE = 'Could not reach the server. Please try again.'

/**
 * The route a removal notice's "it was not me" link opens: relay the token, and
 * say what became of the removal.
 */
@Component({
  selector: 'hilos-second-factor-cancel-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section
      data-id="auth-second-factor-cancel"
      class="row justify-content-center text-center"
    >
      <div class="col-sm-8 col-md-6 col-lg-4">
        @if (status() === 'verifying') {
          <div role="status" data-id="auth-second-factor-cancel-verifying">
            <span
              class="spinner-border"
              role="status"
              aria-hidden="true"
            ></span>
            <p class="mt-3">Canceling the request…</p>
          </div>
        } @else if (status() === 'done') {
          <div
            class="alert alert-success"
            role="status"
            data-id="auth-second-factor-cancel-done"
          >
            The request to remove two-step verification was canceled.
          </div>
          <button
            type="button"
            class="btn btn-primary"
            data-id="auth-second-factor-cancel-continue"
            (click)="goHome()"
          >
            Continue
          </button>
        } @else {
          <div
            class="alert alert-danger"
            role="alert"
            data-id="auth-second-factor-cancel-error"
          >
            {{ message() }}
          </div>
          <div class="d-flex justify-content-center gap-3">
            @if (retryable()) {
              <button
                type="button"
                class="btn btn-link p-0"
                data-id="auth-second-factor-cancel-retry"
                (click)="retry()"
              >
                Try again
              </button>
            }
            <button
              type="button"
              class="btn btn-link p-0"
              data-id="auth-second-factor-cancel-continue"
              (click)="goHome()"
            >
              Continue
            </button>
          </div>
        }
      </div>
    </section>
  `,
})
export class HilosSecondFactorCancelPage {
  /** The project context the relay dispatches the cancel over. */
  readonly context = input.required<HilosAuthContext>()

  private readonly authActions = computed(() =>
    createAuthActions(this.context()),
  )

  private readonly router = inject(HILOS_ROUTER)

  // `verifying` while the token is in flight, `done` once the removal is
  // canceled, `error` once the link names no removal or the server is out of reach.
  protected readonly status = signal<'verifying' | 'done' | 'error'>(
    'verifying',
  )
  protected readonly message = signal('')
  // Whether a retry can do anything: a link with no token will not grow one.
  protected readonly retryable = signal(false)

  // The token as it arrived, read once at mount and kept for Try again.
  private token = ''

  // Which attempt is the one on screen; a newer one supersedes what is in flight.
  private attempt = 0

  constructor() {
    effect((onCleanup) => {
      this.token =
        new URLSearchParams(window.location.search).get('token') ?? ''
      if (this.token === '') {
        this.showError(LINK_DEAD_MESSAGE, false)

        return
      }

      void this.runCancel(this.authActions())

      onCleanup(() => {
        this.attempt += 1
      })
    })
  }

  protected retry(): void {
    void this.runCancel(this.authActions())
  }

  protected goHome(): void {
    this.router.navigate(HOME_PATH)
  }

  /**
   * Run the step whole: wait for a connection that can carry the cancel,
   * dispatch it, and show the outcome. Mount runs it, and so does Try again.
   *
   * @param actions The wire of the project context.
   */
  private async runCancel(actions: HilosAuthActions): Promise<void> {
    this.attempt += 1
    const mine = this.attempt
    this.status.set('verifying')
    this.message.set('')
    if (!(await whenPageReadyOrUnreachable(this.context().connection))) {
      if (this.attempt === mine) {
        this.showError(UNREACHABLE_MESSAGE, true)
      }

      return
    }
    if (this.attempt !== mine) {
      return
    }

    const outcome = await actions.cancelSecondFactorReset(this.token)
    if (this.attempt !== mine) {
      return
    }
    if (outcome.ok) {
      this.status.set('done')

      return
    }
    this.showError(outcome.message ?? LINK_DEAD_MESSAGE, true)
  }

  /**
   * Show a failure, and whether Try again is offered for it.
   *
   * @param reason The sentence to show.
   * @param canRetry Whether a retry can do anything about it.
   */
  private showError(reason: string, canRetry: boolean): void {
    this.status.set('error')
    this.message.set(reason)
    this.retryable.set(canRetry)
  }
}
