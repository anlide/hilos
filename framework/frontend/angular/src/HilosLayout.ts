// HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
// app frame a project fills rather than re-implements. The brand and nav regions
// are projected content and the routed page content is the default slot. It
// renders the top navigation bar carrying the brand and nav, the framework admin
// entry (the gear linking to the Hilos dashboard), the live connection indicator
// the SDK owns (core-and-connection.md), and the content. The brand and the gear
// are HilosLinks — no-refresh navigation that leaves the socket alive — so the
// shell alone can move between the project home and the admin section. Styling is
// Bootstrap classes only and the shell carries no CSS of its own
// (styling-rules.md); the status and admin icons are Bootstrap Icons (`bi-*`).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  input,
  signal,
} from '@angular/core'
import type { ConnectionState, HilosConnection } from '@hilos/core'
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'

import { HilosLink } from './HilosLink.js'

// Each transport state maps to a Bootstrap Icon and a Bootstrap text color:
// green while the socket is live, amber while it is (re)connecting, red when it
// is down. `connecting` and `reconnecting` share the in-progress icon — the only
// thing that distinguishes them is the visually-hidden label.
type ConnVisual = { icon: string; color: string }
const CONN_VISUAL: Record<ConnectionState, ConnVisual> = {
  connected: { icon: 'bi-check-circle-fill', color: 'text-success' },
  connecting: { icon: 'bi-arrow-repeat', color: 'text-warning' },
  reconnecting: { icon: 'bi-arrow-repeat', color: 'text-warning' },
  disconnected: { icon: 'bi-exclamation-triangle-fill', color: 'text-danger' },
}

/**
 * The application shell: top navigation with the brand, the admin gear, the
 * live connection indicator, and the routed page content.
 */
@Component({
  selector: 'hilos-layout',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    <div class="d-flex flex-column min-vh-100" data-id="app-root">
      <nav
        class="navbar navbar-expand bg-body-tertiary border-bottom"
        aria-label="Main"
      >
        <div class="container">
          <a hilosLink="/" class="navbar-brand mb-0 h1" data-id="nav-brand">
            <ng-content select="[brand]">Hilos</ng-content>
          </a>
          <div class="navbar-nav me-auto">
            <ng-content select="[nav]" />
          </div>
          <div class="d-flex align-items-center gap-3">
            <a
              [hilosLink]="adminHref"
              class="nav-link d-inline-flex align-items-center p-0 fs-5"
              data-id="nav-admin"
              aria-label="Hilos dashboard"
            >
              <i class="bi bi-gear-fill" aria-hidden="true"></i>
              <span class="visually-hidden">Hilos dashboard</span>
            </a>
            <span
              [class]="connSpanClass()"
              data-id="conn-state"
              role="status"
              aria-live="polite"
              [title]="connState()"
            >
              <i [class]="connIconClass()" aria-hidden="true"></i>
              <span class="visually-hidden">{{ connState() }}</span>
            </span>
          </div>
        </div>
      </nav>
      <main class="container flex-grow-1 py-4">
        <ng-content />
      </main>
    </div>
  `,
})
export class HilosLayout {
  /** The connection whose live state the shell indicator mirrors. */
  readonly connection = input.required<HilosConnection>()

  // The gear targets the framework's own dashboard page; its URL is owned by
  // the framework page catalog, not restated here as a literal.
  protected readonly adminHref = HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]
  protected readonly connState = signal<ConnectionState>('connecting')
  protected readonly connSpanClass = computed(
    () =>
      'navbar-text d-inline-flex align-items-center fs-5 ' +
      CONN_VISUAL[this.connState()].color,
  )
  protected readonly connIconClass = computed(
    () => 'bi ' + CONN_VISUAL[this.connState()].icon,
  )

  constructor() {
    // Read the connection input once it is bound and mirror its machine state;
    // the effect's cleanup releases the listener when the shell is destroyed.
    effect((onCleanup) => {
      const connection = this.connection()
      this.connState.set(connection.state)
      onCleanup(
        connection.on('state', (next) => {
          this.connState.set(next)
        }),
      )
    })
  }
}
