// HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
// app frame a project fills rather than re-implements. The brand and nav
// regions are projected content and the routed page content is the default
// slot. It renders the top navigation bar carrying the brand and nav, the
// framework admin entry (the gear linking to the Hilos dashboard), the live
// connection indicator the SDK owns (core-and-connection.md), a full-width
// banner region below the nav carrying, in this order, the framework's own
// protected-mode strip, its impersonation strip (drawn from the session, with a
// Stop that waits for the server's answer), and the app-wide status strip a
// project fills (e.g. a trial notice) through a projected [banner] node — one
// live region for all, empty and zero-height while none is up — the content,
// and a footer of the public framework pages (HILOS_FOOTER_LINKS). The shell is
// a fixed-height viewport column (vh-100): the nav, banner, and footer never
// scroll (flex-shrink-0) and the main region grows and scrolls its own overflow
// (min-h-0 + overflow-auto), so a page either scrolls inside main or — like the
// chat page — fills it and scrolls an inner region rather than the whole
// document. The brand, the gear, and the footer links are HilosLinks —
// no-refresh navigation that leaves the socket alive — so the shell alone moves
// between the project home, the admin section, and the public pages. While the
// connection reports protected mode the shell becomes the maintenance surface
// (HilosMaintenance) and keeps only the connection indicator — every other
// region of the shell links to a page the freeze has shut. On the very first
// frame there is nothing to report yet, and on a browser that has met
// maintenance here the core holds that frame back (HIL-613): the shell then
// renders only the hidden hilos-boot-state marker, so a reload into a frozen
// node never flashes the ordinary layout. Styling is Bootstrap classes only and
// the shell carries no CSS of its own (styling-rules.md); the status and admin
// icons are Bootstrap Icons (`bi-*`).
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
  PageRouteMatch,
  ProtectedModeStatus,
  RtStalenessStatus,
} from '@hilos/core'
import {
  HILOS_FOOTER_LINKS,
  HILOS_PAGE_ROUTES,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  HilosPages,
  hilosImpersonation,
  IMPERSONATION_STRIP_COPY,
  protectedModeBannerCopy,
  RECONNECT_DRAGGING_COPY,
  rtStalenessLabel,
  stopImpersonation,
} from '@hilos/core'

import { HilosLink } from './HilosLink.js'
import { HilosMaintenance } from './HilosMaintenance.js'
import { LoadingButton } from './LoadingButton.js'
import { HilosToastHost } from './HilosToastHost.js'
import type { HilosToastCorner } from './hilosToastCorner.js'
import { HilosOAuthWaitModal } from './auth/HilosOAuthWaitModal.js'
import { HILOS_ROUTER } from './hilosRouterToken.js'
import { hilosSignal } from './hilosSignal.js'
import { createHilosTrackedAction } from './hilosTrackedAction.js'

// Each transport state maps to a Bootstrap Icon and a Bootstrap text color:
// green while the socket is live and green on a first connect too — the person
// has only just opened the page and nothing has broken yet, so a warning color
// there would invent a problem (HIL-831) — amber once a live link has dropped
// and is being repaired, red when it is down. `connecting` and `reconnecting`
// share the in-progress icon, and what distinguishes them is the color and the
// visually-hidden label.
type ConnVisual = { icon: string; color: string }
const CONN_VISUAL: Record<ConnectionState, ConnVisual> = {
  connected: { icon: 'bi-check-circle-fill', color: 'text-success' },
  connecting: { icon: 'bi-arrow-repeat', color: 'text-success' },
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
  imports: [
    HilosLink,
    HilosMaintenance,
    HilosOAuthWaitModal,
    HilosToastHost,
    LoadingButton,
  ],
  template: `
    <!-- The one place the two boot outcomes are named, on the marker a test
    waits for rather than polling for chrome that is absent by design while
    held. -->
    <div
      data-id="hilos-boot-state"
      [attr.data-state]="bootState()"
      hidden
    ></div>
    @if (!firstFrameHeld()) {
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
                @if (isAdmin()) {
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
              }
              <span
                [class]="connSpanClass()"
                data-id="conn-state"
                role="status"
                aria-live="polite"
                [title]="connLabel()"
              >
                <span class="position-relative d-inline-flex">
                  <i [class]="connIconClass()" aria-hidden="true"></i>
                  @if (showsDraggingRepair()) {
                    <i
                      class="bi bi-exclamation-triangle-fill text-danger position-absolute hilos-conn-dragging-mark"
                      aria-hidden="true"
                    ></i>
                  }
                </span>
                <span class="visually-hidden">{{ connLabel() }}</span>
              </span>
            </div>
          </div>
        </nav>
        <div
          class="flex-shrink-0"
          role="status"
          aria-live="polite"
          data-id="app-banner"
        >
          @if (verificationBanner(); as bannerMessage) {
            <div
              class="alert alert-warning border-0 rounded-0 mb-0 py-2"
              data-id="protected-mode-banner"
            >
              <div
                class="container d-flex flex-wrap align-items-center justify-content-center gap-3"
              >
                <span>
                  <i
                    class="bi bi-shield-exclamation me-1"
                    aria-hidden="true"
                  ></i>
                  {{ bannerMessage }}
                </span>
              </div>
            </div>
          }
          @if (impersonation(); as strip) {
            @if (!underMaintenance()) {
              <div
                class="alert alert-warning border-0 rounded-0 mb-0 py-2"
                data-id="impersonation-banner"
              >
                <div
                  class="container d-flex flex-wrap align-items-center justify-content-center gap-3"
                >
                  <span>
                    <i class="bi bi-people-fill me-1" aria-hidden="true"></i>
                    {{ stripCopy.lead }} <strong>{{ strip.userName }}</strong>
                  </span>
                  <button
                    hilosLoadingButton
                    class="btn-sm btn-outline-dark"
                    data-id="impersonation-stop"
                    [loading]="impersonationStop.busy()"
                    (click)="onImpersonationStop()"
                  >
                    {{ stripCopy.stop }}
                  </button>
                </div>
              </div>
            }
          }
          <ng-content select="[banner]" />
        </div>
        <main
          id="hilos-main-content"
          tabindex="-1"
          class="container flex-grow-1 min-h-0 overflow-auto py-4"
          [class.d-flex]="underMaintenance()"
          [class.flex-column]="underMaintenance()"
        >
          @if (underMaintenance()) {
            <hilos-maintenance
              [status]="protectedMode()"
              [connection]="connection()"
              [adminSurface]="adminSurface()"
            />
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
        <hilos-toast-host [corner]="toastCorner()" />
        <!-- An OAuth trip runs in another window over whatever page started it, so
        the wait belongs to the shell too: the page underneath stays subscribed and
        alive, and no project mounts anything (HIL-633). -->
        <hilos-oauth-wait-modal />
      </div>
    }
  `,
})
export class HilosLayout {
  /** The connection whose live state the shell indicator mirrors. */
  readonly connection = input.required<HilosConnection>()
  /**
   * Whether the signed-in user holds the admin privilege. The admin entry is
   * drawn for an admin and for nobody else, so a project that answers no admin
   * identity (the default) shows no way into a surface the gate would refuse.
   */
  readonly isAdmin = input(false)
  /**
   * Which corner the toast stack sits in; the bottom end by default. A project
   * chooses it once here and never per notice: different corners in different
   * sections of one product is a reliable way to make the notices stop being
   * noticed (toasts.md).
   */
  readonly toastCorner = input<HilosToastCorner>('bottom-end')

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
  // The opposite side of the same state: whoever the mode does NOT hold, while
  // it still holds the node, is inside a system that is closed to everybody else
  // and looks exactly like an open one. The banner is what says so, and it comes
  // from the connection for the same reason the surface does - navigation, a
  // reconnect, an F5 and a second tab all learn it from the frame rather than
  // from a store that would have to be rebuilt on each of them.
  protected readonly verificationBanner = computed(() =>
    protectedModeBannerCopy(this.protectedMode()),
  )
  // The second framework strip (HIL-1064): the session says an administrator
  // stands behind it, so the person is told whom they are acting as and given
  // the way back. It is the shell's and reads the core store bootHilos binds -
  // no project input. Stop is a tracked action with the driver's defaults: busy
  // disables it at once, a refusal is the error toast and leaves the strip
  // standing, and success needs no toast - the strip leaves by itself with the
  // identity the answer rides behind. Below the protected-mode strip because
  // what is about the node comes before what is about the session.
  protected readonly impersonation = hilosSignal(hilosImpersonation)
  protected readonly impersonationStop = createHilosTrackedAction()
  protected readonly stripCopy = IMPERSONATION_STRIP_COPY
  // Before any of that can be read there is a frame where nothing has been
  // announced yet, and drawing the ordinary shell in it is what makes a reload
  // into a frozen node flash (HIL-613). On a browser that has met maintenance
  // here the core holds that frame back until the welcome lands, and the shell
  // draws nothing at all in the meantime — not a spinner, not a placeholder: the
  // wait is measured in the time one frame takes, and anything drawn in it is a
  // second flash replacing the first.
  protected readonly firstFrameHeld = signal(false)
  protected readonly bootState = computed(() =>
    this.firstFrameHeld() ? 'held' : 'ready',
  )
  protected readonly connSpanClass = computed(
    () =>
      'navbar-text d-inline-flex align-items-center fs-5 ' +
      CONN_VISUAL[this.connState()].color,
  )
  // A live socket that is nonetheless showing part of a frozen replica
  // (HIL-711): the same green, with a snowflake instead of the tick. It replaces
  // the icon rather than standing beside it because the question is one — how
  // much of what you see can be trusted — and two marks would read as two
  // problems. Only while the socket is up: while it is down the transport itself
  // is the news, and a stale copy is the least of what is out of date.
  protected readonly rtStaleness = signal<RtStalenessStatus>(RT_STALENESS_FRESH)
  private readonly showsFrozenData = computed(
    () => this.connState() === 'connected' && this.rtStaleness().stale,
  )
  protected readonly connIconClass = computed(() =>
    this.showsFrozenData()
      ? 'bi bi-snow'
      : 'bi ' + CONN_VISUAL[this.connState()].icon,
  )
  // A repair that has been running long enough for the backoff pauses to have
  // reached their ceiling (HIL-831): the same amber arrows, with a small red
  // triangle over their corner. An overlay rather than a replacement, because it
  // is the same process — merely longer than expected. Only while reconnecting:
  // on a first connect nothing has been repairing.
  protected readonly reconnectDragging = signal(false)
  protected readonly showsDraggingRepair = computed(
    () => this.connState() === 'reconnecting' && this.reconnectDragging(),
  )
  protected readonly connLabel = computed(() => {
    const label = rtStalenessLabel(this.rtStaleness())

    if (this.showsFrozenData() && label !== undefined) {
      return `${this.connState()} - ${label}`
    }
    if (this.showsDraggingRepair()) {
      return `${this.connState()} - ${RECONNECT_DRAGGING_COPY.dragging}`
    }

    return this.connState()
  })

  // Mirror the navigator's current page title: set it as the document title so
  // the browser tab tracks the no-refresh navigation, and render it in the live
  // region below so a screen reader announces the page change (WCAG 2.4.2).
  // Without a router (tests, the hard-link fallback) the title stays empty.
  private readonly router = inject(HILOS_ROUTER, { optional: true })
  protected readonly pageTitle = this.router
    ? hilosSignal(this.router.currentTitle)
    : signal('')

  // The maintenance surface shows the verifier's code field only on an
  // administrative url, so the shell hands the route's surface type down to it.
  // Without a router there is no route and therefore no administrative surface:
  // the field then hides, which is the safe way round — a missing field is fixed
  // by typing the admin url, a field shown where it should not be is the defect
  // this closes.
  private readonly currentRoute = this.router
    ? hilosSignal(this.router.currentRoute)
    : signal<PageRouteMatch>({ page: '', params: {}, admin: false })
  protected readonly adminSurface = computed(() => this.currentRoute().admin)

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

    // And the same for the frozen replicas this page reads: seeded from the
    // connection (a shell mounted during a break starts marked) and kept live by
    // the pushed frame, which also arrives on every page subscription.
    effect((onCleanup) => {
      const connection = this.connection()
      this.rtStaleness.set(connection.rtStaleness)
      onCleanup(
        connection.on('rtStaleness', (next) => {
          this.rtStaleness.set(next)
        }),
      )
    })

    // And the same for a repair that has dragged: seeded from the connection so
    // a shell mounted mid-outage starts marked, then kept live by the event the
    // core emits on the change alone.
    effect((onCleanup) => {
      const connection = this.connection()
      this.reconnectDragging.set(connection.reconnectDragging)
      onCleanup(
        connection.on('reconnectDragging', (next) => {
          this.reconnectDragging.set(next)
        }),
      )
    })

    // And the same for the boot hold, which the core only ever reports going
    // down: the value it starts on is read off the connection here, before this
    // component's template is first checked.
    effect((onCleanup) => {
      const connection = this.connection()
      this.firstFrameHeld.set(connection.firstFrameHeld)
      onCleanup(
        connection.on('firstFrameHold', (next) => {
          this.firstFrameHeld.set(next)
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

  /** Leave the takeover through the tracked driver; a second press while busy is dropped. */
  protected onImpersonationStop(): void {
    if (this.impersonationStop.busy()) {
      return
    }
    void this.impersonationStop.run(stopImpersonation())
  }
}
