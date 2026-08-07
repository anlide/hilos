// HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
// app frame a project fills rather than re-implements. The brand and nav regions
// are projected content and the routed page content is the default slot. It
// renders the top navigation bar carrying the brand and nav, the framework admin
// entry (the gear linking to the Hilos dashboard), the live connection indicator
// the SDK owns (core-and-connection.md), the content, and a footer of the public
// framework pages (HILOS_FOOTER_LINKS). The shell is a fixed-height viewport
// column (vh-100): the nav and footer never scroll (flex-shrink-0) and the main
// region grows and scrolls its own overflow (min-h-0 + overflow-auto), so a page
// either scrolls inside main or — like the chat page — fills it and scrolls an
// inner region rather than the whole document. The brand, the gear, and the
// footer links are HilosLinks — no-refresh navigation that leaves the socket
// alive — so the shell alone moves between the project home, the admin section,
// and the public pages. While the connection reports protected mode the shell
// becomes the maintenance surface (HilosMaintenance) and keeps only the
// connection indicator — every other region of the shell links to a page the
// freeze has shut. Styling is Bootstrap classes only and the shell carries
// no CSS of its own (styling-rules.md); the status and admin icons are Bootstrap
// Icons (`bi-*`).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
} from '@angular/core'
import type {
  ConnectionState,
  HilosConnection,
  ProtectedModeStatus,
} from '@hilos/core'
import {
  HILOS_FOOTER_LINKS,
  HILOS_PAGE_ROUTES,
  PROTECTED_MODE_INACTIVE,
  HilosPages,
} from '@hilos/core'

import { HilosLink } from './HilosLink.js'
import { HilosMaintenance } from './HilosMaintenance.js'
import { HilosToastHost } from './HilosToastHost.js'
import { HILOS_ROUTER } from './hilosRouterToken.js'
import { hilosSignal } from './hilosSignal.js'

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
  imports: [HilosLink, HilosMaintenance, HilosToastHost],
  template: `
    <div class="d-flex flex-column vh-100 overflow-hidden" data-id="app-root">
      <a
        href="#hilos-main-content"
        class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary btn-sm z-3"
        data-id="skip-to-content"
        >Skip to main content</a
      >
      <div
        class="visually-hidden"
        role="status"
        aria-live="polite"
        data-id="page-title"
      >
        {{ pageTitle() }}
      </div>
      <nav
        class="navbar navbar-expand bg-body-tertiary border-bottom flex-shrink-0"
        aria-label="Main"
      >
        <div class="container">
          @if (!underMaintenance()) {
            <a hilosLink="/" class="navbar-brand mb-0 h1" data-id="nav-brand">
              <ng-content select="[brand]">Hilos</ng-content>
            </a>
          }
          <!-- The auto margin lives on this region whether or not it holds
          links, so the connection indicator keeps its place on the right while
          the maintenance surface is up. -->
          <div class="navbar-nav me-auto">
            @if (!underMaintenance()) {
              <ng-content select="[nav]" />
            }
          </div>
          <div class="d-flex align-items-center gap-3">
            @if (!underMaintenance()) {
              <ng-content select="[user]" />
              <a
                [hilosLink]="adminHref"
                class="nav-link d-inline-flex align-items-center p-0 fs-5"
                data-id="nav-admin"
                aria-label="Hilos dashboard"
              >
                <i class="bi bi-gear-fill" aria-hidden="true"></i>
                <span class="visually-hidden">Hilos dashboard</span>
              </a>
            }
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
      <main
        id="hilos-main-content"
        tabindex="-1"
        class="container flex-grow-1 min-h-0 overflow-auto py-4"
        [class.d-flex]="underMaintenance()"
        [class.flex-column]="underMaintenance()"
      >
        @if (underMaintenance()) {
          <hilos-maintenance [status]="protectedMode()" />
        } @else {
          <ng-content />
        }
      </main>
      @if (!underMaintenance()) {
        <footer
          class="footer flex-shrink-0 border-top bg-body-tertiary py-2"
          data-id="app-footer"
        >
          <div
            class="container d-flex flex-wrap justify-content-center gap-3 small"
          >
            @for (link of footerLinks; track link.page) {
              <a
                [hilosLink]="link.href"
                class="link-secondary text-decoration-none"
                [attr.data-id]="'footer-link-' + link.page"
                >{{ link.label }}</a
              >
            }
          </div>
        </footer>
      }
      <!-- Transient notices float over the shell, so every page inside it can
      report an outcome without owning a notification surface of its own. -->
      <hilos-toast-host />
    </div>
  `,
})
export class HilosLayout {
  /** The connection whose live state the shell indicator mirrors. */
  readonly connection = input.required<HilosConnection>()

  // The gear targets the framework's own dashboard page; its URL is owned by
  // the framework page catalog, not restated here as a literal.
  protected readonly adminHref = HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]
  // The footer's public framework pages, their labels, and their hrefs are owned
  // by the framework (routing/hilosPages), so every project's footer offers the
  // same links and a project supplies only each page's content component.
  protected readonly footerLinks = HILOS_FOOTER_LINKS.map((link) => ({
    page: link.page,
    label: link.label,
    href: HILOS_PAGE_ROUTES[link.page] ?? '/',
  }))
  protected readonly connState = signal<ConnectionState>('connecting')
  // While the backend holds the node in protected mode the shell shows the
  // maintenance surface instead of the routed content, and drops everything
  // that leads anywhere: the brand, the nav, the user region, the admin gear,
  // and the footer all point at pages the freeze has shut. The connection
  // indicator is the one thing that stays — during planned work it is the only
  // status worth telling the visitor. The state is read from the connection,
  // not from a page store, so it outlives routing and subscription lifecycles.
  protected readonly protectedMode = signal<ProtectedModeStatus>(
    PROTECTED_MODE_INACTIVE,
  )
  protected readonly underMaintenance = computed(
    () => this.protectedMode().active,
  )
  protected readonly connSpanClass = computed(
    () =>
      'navbar-text d-inline-flex align-items-center fs-5 ' +
      CONN_VISUAL[this.connState()].color,
  )
  protected readonly connIconClass = computed(
    () => 'bi ' + CONN_VISUAL[this.connState()].icon,
  )

  // Mirror the navigator's current page title: set it as the document title so
  // the browser tab tracks the no-refresh navigation, and render it in the live
  // region below so a screen reader announces the page change (WCAG 2.4.2).
  // Without a router (tests, the hard-link fallback) the title stays empty.
  private readonly router = inject(HILOS_ROUTER, { optional: true })
  protected readonly pageTitle = this.router
    ? hilosSignal(this.router.currentTitle)
    : signal('')

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

    // The same mirroring for the freeze: seeded from the connection (a shell
    // mounted mid-maintenance starts on the surface) and kept live by the
    // pushed frame.
    effect((onCleanup) => {
      const connection = this.connection()
      this.protectedMode.set(connection.protectedMode)
      onCleanup(
        connection.on('protectedMode', (next) => {
          this.protectedMode.set(next)
        }),
      )
    })

    // Track the page title onto the document title across no-refresh navigation.
    effect(() => {
      const title = this.pageTitle()
      if (title) {
        document.title = title
      }
    })
  }
}
