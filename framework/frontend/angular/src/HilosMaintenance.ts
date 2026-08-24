// HilosMaintenance — the maintenance surface of the app shell. HilosLayout
// renders it over the routed content for as long as the connection reports
// protected mode, on every url, so a visitor who arrives mid-maintenance sees
// planned work rather than a generic outage. The words come from the backend
// registry and travel the wire (the Hilos i18n model); this component holds
// layout only and falls back to PROTECTED_MODE_FALLBACK_COPY when the state is
// known but no sentence arrived with it. It is a state, not a page: no links,
// no retry button — the mode lifts on its own and the core reloads the document.
// The one exception is the code field, shown only while the freeze says it
// accepts a pass AND the shell hands over an administrative surface AND at least
// one code has been minted: that phase is the verification window, and a verifier
// admitted by the code sees the whole product rather than this screen, while a
// visitor on a public url is not invited to fill in a key he was never given. The
// window opens before any code exists, and until one does the same spot carries a
// sentence saying so — the field would otherwise be a box that can take nothing.
// The rule lives here, in the component that owns the field, rather than in the
// shell. Submitting reconnects with the key on the socket url (the core does
// that), because a client refused every outbound frame can only ask to be let in
// on the 101.
//
// The second exception is the restore panel, and it appears for one visitor only:
// the admin whose own restore is what shuttered the node. Its frames are addressed
// to that browser's session (HIL-655), so every other tab receives none and keeps
// this screen exactly as it was. What it says is the phase and the outcome, not the
// backup list — under the freeze there is nobody left to serve a list.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import {
  createHilosRestoreProgress,
  formatRestoreOutcomeLine,
  formatRestorePhaseLine,
  subscribeSignal,
  PROTECTED_MODE_FALLBACK_COPY,
  PROTECTED_MODE_PASS_COPY,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRestoreStatus,
  ProtectedModeStatus,
} from '@hilos/core'

/** The full-screen maintenance state of the shell. */
@Component({
  selector: 'hilos-maintenance',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="d-flex flex-column justify-content-center align-items-center flex-grow-1 text-center"
      data-id="maintenance"
      [attr.data-operation]="status().operation ?? null"
      role="status"
      aria-live="polite"
    >
      <i
        class="bi bi-tools display-4 text-body-secondary mb-3"
        aria-hidden="true"
      ></i>
      <h1 class="h3 mb-2" data-id="maintenance-title">{{ title() }}</h1>
      <p class="text-body-secondary mb-0" data-id="maintenance-message">
        {{ message() }}
      </p>
      @if (restoreStatus()) {
        <div
          class="alert mt-4 mb-0"
          [class.alert-danger]="restoreVariant() === 'alert-danger'"
          [class.alert-success]="restoreVariant() === 'alert-success'"
          [class.alert-info]="restoreVariant() === 'alert-info'"
          data-id="maintenance-restore"
        >
          <div class="fw-semibold" data-id="maintenance-restore-phase">
            {{ restorePhaseLine() }}
          </div>
          @if (restoreOutcomeLine()) {
            <div class="small" data-id="maintenance-restore-outcome">
              {{ restoreOutcomeLine() }}
            </div>
          }
        </div>
      }
      @if (status().acceptsPass && adminSurface() && status().passIssued) {
        <form
          class="row justify-content-center w-100 mt-4 px-3"
          data-id="maintenance-pass-form"
          (submit)="present($event)"
        >
          <div class="col-12 col-sm-8 col-md-5">
            <label
              class="form-label small text-body-secondary"
              for="maintenance-pass"
            >
              {{ passCopy.prompt }}
            </label>
            <div class="input-group">
              <input
                id="maintenance-pass"
                class="form-control"
                [class.is-invalid]="status().passRejected"
                data-id="maintenance-pass"
                type="text"
                autocomplete="off"
                [value]="code()"
                [attr.aria-invalid]="status().passRejected"
                [attr.aria-describedby]="
                  status().passRejected ? 'maintenance-pass-error' : null
                "
                (input)="onCodeInput($event)"
              />
              <button
                class="btn btn-primary"
                data-id="maintenance-pass-submit"
                type="submit"
                [disabled]="!submittable()"
              >
                {{ passCopy.submit }}
              </button>
            </div>
            @if (status().passRejected) {
              <p
                id="maintenance-pass-error"
                class="text-danger small mt-2 mb-0"
                data-id="maintenance-pass-error"
              >
                {{ passCopy.rejected }}
              </p>
            }
          </div>
        </form>
      } @else if (status().acceptsPass && adminSurface()) {
        <p
          class="text-body-secondary small mt-4 mb-0"
          data-id="maintenance-pass-pending"
        >
          {{ passCopy.pending }}
        </p>
      }
    </div>
  `,
})
export class HilosMaintenance {
  /** The freeze to render, as the connection reports it. */
  readonly status = input.required<ProtectedModeStatus>()

  /** The connection a presented code is carried back in on. */
  readonly connection = input.required<HilosConnection>()

  /**
   * Whether the url under the freeze names an administrative surface, as the
   * shell reads it off the current route. Required, so a shell cannot forget to
   * answer and silently hide the field from the verifier who needs it.
   */
  readonly adminSurface = input.required<boolean>()

  protected readonly passCopy = PROTECTED_MODE_PASS_COPY

  protected readonly title = computed(
    () => this.status().title ?? PROTECTED_MODE_FALLBACK_COPY.title,
  )
  protected readonly message = computed(
    () => this.status().message ?? PROTECTED_MODE_FALLBACK_COPY.message,
  )

  protected readonly code = signal('')
  protected readonly submittable = computed(() => this.code().trim() !== '')

  /**
   * The latest restore frame this connection was sent, mirrored from the core
   * selector; null for every visitor whose session did not ask for the run.
   */
  protected readonly restoreStatus = signal<HilosRestoreStatus | null>(null)

  protected readonly restorePhaseLine = computed(() => {
    const status = this.restoreStatus()

    return status === null ? '' : formatRestorePhaseLine(status)
  })

  protected readonly restoreOutcomeLine = computed(() => {
    const status = this.restoreStatus()

    return status === null ? '' : formatRestoreOutcomeLine(status)
  })

  /**
   * The alert variant the restore panel wears: the colour follows the outcome, and
   * the sentence inside says the same thing in words — the colour is never the only
   * carrier (WCAG 1.4.1).
   */
  protected readonly restoreVariant = computed(() => {
    const outcome = this.restoreStatus()?.outcome ?? null
    if (outcome === 'error') {
      return 'alert-danger'
    }

    return outcome === 'success' ? 'alert-success' : 'alert-info'
  })

  constructor() {
    // The connection arrives via input, so the selector is built once it binds and
    // dropped if it is replaced — the same shape the backup page uses for the same
    // frames.
    effect((onCleanup) => {
      const progress = createHilosRestoreProgress(this.connection())
      progress.start()
      const unsubscribe = subscribeSignal(progress.status, (value) =>
        this.restoreStatus.set(value),
      )
      onCleanup(() => {
        unsubscribe()
        progress.dispose()
      })
    })
  }

  /**
   * Mirrors the typed code into the signal the submit button reads.
   *
   * @param event The input event of the code field.
   */
  protected onCodeInput(event: Event): void {
    this.code.set((event.target as HTMLInputElement).value)
  }

  /**
   * Presents the typed code, keeping it in the field.
   *
   * A rejection is most often a typo, and clearing the field would make the
   * visitor retype the whole key.
   *
   * @param event The form submit event, whose page load is not wanted.
   */
  protected present(event: Event): void {
    event.preventDefault()
    this.connection().presentProtectedModePass(this.code())
  }
}
