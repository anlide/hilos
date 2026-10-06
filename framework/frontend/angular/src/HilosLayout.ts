// HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
// app frame a project fills rather than re-implements. The brand and nav
// regions are projected content and the routed page content is the default
// slot. It renders the top navigation bar carrying the brand and nav, the
// framework admin entry (the gear linking to the Hilos dashboard, drawn for an
// admin and, in the admin view mode, for a viewer), the live connection
// indicator the SDK owns (core-and-connection.md), and, last,
// its tracked sign-out control while a person stands behind the session;
// a full-width banner region below the nav carrying, in this order, the
// framework's own protected-mode strip, its impersonation strip (drawn from the
// session, with a Stop that waits for the server's answer, colored by the
// standing of the person taken over, and marked "view only" when the
// administrator may only look, HIL-1170), its account deletion strip (the
// session's own scheduled deletion, with a "Keep my account" that waits the
// same way, HIL-945), its view-mode strip (a viewer of the admin view mode on an
// admin route, HIL-1260), and the app-wide status strip a project fills (e.g. a
// trial notice) through a projected [banner] node — one live region for all,
// empty and zero-height while none is up — the content, and a footer of the
// public framework pages
// (HILOS_FOOTER_LINKS). The shell is a fixed-height viewport column (vh-100):
// the nav, banner, and footer never scroll (flex-shrink-0) and the main region
// grows and scrolls its own overflow (min-h-0 + overflow-auto), so a page
// either scrolls inside main or — like the chat page — fills it and scrolls an
// inner region rather than the whole document. The brand, the gear, and the
// footer links are HilosLinks — no-refresh navigation that leaves the socket
// alive — so the shell alone moves between the project home, the admin section,
// and the public pages. While the connection reports protected mode the shell
// becomes the maintenance surface (HilosMaintenance) and keeps only the
// connection indicator — every other region of the shell links to a page the
// freeze has shut. On the very first frame there is nothing to report yet, and
// on a browser that has met maintenance here the core holds that frame back
// (HIL-613): the shell then renders only the hidden hilos-boot-state marker, so
// a reload into a frozen node never flashes the ordinary layout. While the
// session holds a blocked account the routed content gives way to the "Access
// closed" card (HilosAccountBlocked, HIL-289) — the header and footer stay,
// maintenance still comes first. The "the terms have changed" screen is the
// shell's too (HIL-500): a yellow document icon right after the user region
// while a document waits for a decision, the window it opens (raised by itself
// on a sign-in in this tab), and, once the deadline has passed and the account
// is frozen, the same screen in the content's place on every page the freeze
// does not leave open — after the "Access closed" card, before the content.
// Styling is Bootstrap classes only and the
// shell carries no CSS of its own (styling-rules.md); the status and admin
// icons are Bootstrap Icons (`bi-*`). In a takeover that only looks the
// controls of what is projected into the shell stand disabled and the shell's
// own do not (HILOS_TAKEOVER_VIEW_ONLY): the token reaches projected content
// through `providers`, and the shell's view meets `false` first through
// `viewProviders`. Angular cannot tell the default slot from the named ones by
// injector, so a control a project projects into [brand], [nav], [user] or
// [banner] reads the takeover too — in Vue and React only the page's area does.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core'
import type {
  ConnectionState,
  HilosConnection,
  PageRouteMatch,
  ProtectedModeStatus,
  RtStalenessStatus,
} from '@hilos/core'
import {
  ACCOUNT_DELETION_TICK_MS,
  ACCOUNT_STANDING_STRIP_COPY,
  closeLegalReconsent,
  formatHilosDeletionStrip,
  formatHilosLegalReconsentBadge,
  HILOS_FOOTER_LINKS,
  HILOS_FROZEN_OPEN_PAGES,
  HILOS_IMPERSONATION_STRIP_TEXT_ID,
  HILOS_PAGE_ROUTES,
  HILOS_VIEW_MODE_COPY,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  PROTECTED_MODE_INACTIVE,
  RT_STALENESS_FRESH,
  HilosPages,
  hilosAccountBlocked,
  hilosAdminAccess,
  hilosAccountStanding,
  hilosDeletionStrip,
  hilosFrozenScreen,
  hilosLegalReconsent,
  hilosLegalReconsentDue,
  hilosLegalReconsentOpen,
  hilosLegalReconsentPerson,
  LEGAL_RECONSENT_COPY,
  openLegalReconsent,
  hilosImpersonation,
  hilosSignedIn,
  hilosTakeoverViewOnly,
  IMPERSONATION_STRIP_COPY,
  keepMyAccount,
  protectedModeBannerCopy,
  RECONNECT_DRAGGING_COPY,
  rtStalenessLabel,
  signOut,
  SIGN_OUT_COPY,
  stopImpersonation,
} from '@hilos/core'

import { HilosLink } from './HilosLink.js'
import { HilosAccountBlocked } from './HilosAccountBlocked.js'
import { HilosMaintenance } from './HilosMaintenance.js'
import { LoadingButton } from './LoadingButton.js'
import { HilosToastHost } from './HilosToastHost.js'
import type { HilosToastCorner } from './hilosToastCorner.js'
import { HilosOAuthWaitModal } from './auth/HilosOAuthWaitModal.js'
import { HilosModal } from './HilosModal.js'
import { HilosLegalReconsent } from './legal/HilosLegalReconsent.js'
import { HILOS_ROUTER } from './hilosRouterToken.js'
import { HILOS_TAKEOVER_VIEW_ONLY } from './hilosLookOnly.js'
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
/** What the shell's own controls read: the takeover never locks them. */
const SHELL_NEVER_LOCKED = signal(false).asReadonly()

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
  providers: [
    {
      provide: HILOS_TAKEOVER_VIEW_ONLY,
      useFactory: () => hilosSignal(hilosTakeoverViewOnly),
    },
  ],
  viewProviders: [
    { provide: HILOS_TAKEOVER_VIEW_ONLY, useValue: SHELL_NEVER_LOCKED },
  ],
  imports: [
    HilosAccountBlocked,
    HilosLegalReconsent,
    HilosLink,
    HilosMaintenance,
    HilosModal,
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
                @if (reconsentDue() !== null) {
                  <button
                    type="button"
                    class="btn btn-link nav-link d-inline-flex align-items-center p-0 fs-5 text-warning"
                    data-id="legal-reconsent-icon"
                    [attr.title]="reconsentBadge()"
                    (click)="openReconsent()"
                  >
                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                    <span class="visually-hidden">{{ reconsentBadge() }}</span>
                  </button>
                }
                @if (adminAccess() !== 'none') {
                  <a
                    [hilosLink]="adminHref"
                    class="nav-link d-inline-flex align-items-center p-0 fs-5"
                    [class.position-relative]="adminAccess() === 'view'"
                    data-id="nav-admin"
                    [attr.data-access]="adminAccess()"
                    [attr.aria-label]="adminLabel()"
                    [attr.title]="
                      adminAccess() === 'view' ? adminLabel() : null
                    "
                  >
                    <i
                      class="bi bi-gear-fill"
                      [class.text-info-emphasis]="adminAccess() === 'view'"
                      aria-hidden="true"
                    ></i>
                    @if (adminAccess() === 'view') {
                      <span
                        class="position-absolute top-100 start-100 translate-middle bg-body-tertiary lh-1 rounded-circle"
                        aria-hidden="true"
                      >
                        <i class="bi bi-eye" aria-hidden="true"></i>
                      </span>
                    }
                    <span class="visually-hidden">{{ adminLabel() }}</span>
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
              @if (!underMaintenance() && signedIn()) {
                <button
                  hilosLoadingButton
                  class="btn-link nav-link d-inline-flex align-items-center p-0 fs-5"
                  data-id="nav-logout"
                  [attr.aria-label]="signOutCopy.label"
                  [title]="signOutCopy.label"
                  [loading]="signOutAction.busy()"
                  (click)="onSignOut()"
                >
                  <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                </button>
              }
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
                class="alert border-0 rounded-0 mb-0 py-2"
                [class]="'alert-' + strip.tone"
                data-id="impersonation-banner"
              >
                <div
                  class="container d-flex flex-wrap align-items-center justify-content-center gap-3"
                >
                  <span [id]="stripTextId">
                    <i class="bi bi-people-fill me-1" aria-hidden="true"></i>
                    {{ stripCopy.lead }} <strong>{{ strip.userName }}</strong>
                    @if (strip.viewOnly) {
                      <span class="badge text-bg-dark ms-1">{{
                        stripCopy.viewOnly
                      }}</span>
                    }
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
          @if (deletionStrip() !== null && !underMaintenance()) {
            <div
              class="alert alert-warning border-0 rounded-0 mb-0 py-2"
              data-id="account-deletion-strip"
            >
              <div
                class="container d-flex flex-wrap align-items-center justify-content-center gap-3"
              >
                <span data-id="account-deletion-strip-text">
                  <i class="bi bi-trash me-1" aria-hidden="true"></i>
                  {{ deletionStripText() }}
                </span>
                <button
                  hilosLoadingButton
                  class="btn-sm btn-outline-dark"
                  data-id="account-deletion-strip-keep"
                  [loading]="keepAccount.busy()"
                  (click)="onKeepAccount()"
                >
                  {{ standingCopy.keep }}
                </button>
              </div>
            </div>
          }
          @if (viewModeStrip()) {
            <div
              class="alert alert-secondary border-0 rounded-0 mb-0 py-2"
              data-id="view-mode-banner"
            >
              <div
                class="container d-flex flex-wrap align-items-center justify-content-center gap-3"
              >
                <span [id]="viewModeStripTextId">
                  <i class="bi bi-eye me-1" aria-hidden="true"></i>
                  <strong>{{ viewModeCopy.mark }}</strong> ·
                  {{ viewModeCopy.explanation }}
                </span>
              </div>
            </div>
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
          } @else if (accountBlocked(); as notice) {
            <hilos-account-blocked [notice]="notice" />
          } @else if (frozenShown()) {
            <div
              class="row justify-content-center"
              data-id="legal-frozen-screen"
            >
              <div class="col-12 col-md-10 col-lg-8">
                <div class="text-center mb-3">
                  <span
                    class="bg-info-subtle text-info rounded-circle d-inline-flex align-items-center justify-content-center p-3 fs-4 lh-1"
                  >
                    <i class="bi bi-snow" aria-hidden="true"></i>
                  </span>
                </div>
                <hilos-legal-reconsent
                  variant="frozen"
                  [content]="reconsentContent()"
                  [view]="reconsentView()"
                  [loading]="reconsentLoading()"
                  [error]="reconsentError()"
                  [busy]="reconsentBusy()"
                  [person]="reconsentPerson()"
                  [deletionScheduled]="deletionScheduled()"
                  [now]="reconsentNow()"
                  (accept)="onReconsentAccept()"
                  (retry)="onReconsentRetry()"
                  (show)="reconsent.show($event)"
                />
              </div>
            </div>
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
        <!-- The "the terms have changed" window (HIL-500): over the page, never
        over work in progress — the core raises it on a sign-in in this tab
        only; after that the icon in the header is the way back to it. Focus
        lands on the dialog, not on Accept, so Enter never accepts the terms by
        accident. -->
        <hilos-modal
          [open]="reconsentOpen() && !underMaintenance()"
          [aria-label]="reconsentCopy.heading"
          initialFocus="dialog"
          (openChange)="$event ? undefined : onReconsentLater()"
        >
          <div data-id="legal-reconsent-modal">
            <hilos-legal-reconsent
              variant="window"
              [content]="reconsentContent()"
              [view]="reconsentView()"
              [loading]="reconsentLoading()"
              [error]="reconsentError()"
              [busy]="reconsentBusy()"
              [person]="reconsentPerson()"
              [now]="reconsentNow()"
              (accept)="onReconsentAccept()"
              (later)="onReconsentLater()"
              (retry)="onReconsentRetry()"
              (show)="reconsent.show($event)"
            />
          </div>
        </hilos-modal>
      </div>
    }
  `,
})
export class HilosLayout {
  /** The connection whose live state the shell indicator mirrors. */
  readonly connection = input.required<HilosConnection>()
  /**
   * Which corner the toast stack sits in; the bottom end by default. A project
   * chooses it once here and never per notice: different corners in different
   * sections of one product is a reliable way to make the notices stop being
   * noticed (toasts.md).
   */
  readonly toastCorner = input<HilosToastCorner>('bottom-end')

  // The admin gear (HIL-1253, HIL-1289): drawn for an admin and, on a node in the admin
  // view mode, for a viewer who may look and not act; the core derives which
  // from the session's admin flag and the node's mode, so the project feeds it
  // nothing. It leads to the same dashboard either way - the server decides
  // what each is shown. Its URL is owned by the framework page catalog, not
  // restated here as a literal.
  protected readonly adminAccess = hilosSignal(hilosAdminAccess)
  protected readonly adminHref = HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]
  protected readonly adminLabel = computed(() =>
    this.adminAccess() === 'view'
      ? `Hilos dashboard — ${this.viewModeCopy.mark}`
      : 'Hilos dashboard',
  )
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
  // what is about the node comes before what is about the session. Where the
  // administrator may only look (HIL-1170) the strip says so after the name,
  // and its text carries the id every control of the page then disabled names
  // in aria-describedby — the page alone: Stop stays live.
  protected readonly impersonation = hilosSignal(hilosImpersonation)
  protected readonly impersonationStop = createHilosTrackedAction()
  protected readonly stripCopy = IMPERSONATION_STRIP_COPY
  protected readonly stripTextId = HILOS_IMPERSONATION_STRIP_TEXT_ID
  // The third framework strip (HIL-945): the session's own account is scheduled
  // for deletion, so the person is told when, and how long there is left to
  // think, on every page — the product still works, and the state lives beside
  // the work. Not under a takeover: the shell then speaks about the person taken
  // over, and the core leaves the strip down. "Keep my account" is the
  // impersonation strip's Stop once more — busy at once, a refusal is the error
  // toast, and success needs no toast: the strip leaves with the handshake that
  // no longer carries the deletion. The days left are counted again once a
  // minute while it stands (the constructor's tick).
  protected readonly deletionStrip = hilosSignal(hilosDeletionStrip)
  private readonly deletionStanding = computed(
    () => this.deletionStrip() !== null,
  )
  private readonly deletionNow = signal(Date.now())
  protected readonly deletionStripText = computed(() => {
    const strip = this.deletionStrip()

    return strip === null
      ? ''
      : formatHilosDeletionStrip(strip, this.deletionNow())
  })
  protected readonly keepAccount = createHilosTrackedAction()
  protected readonly standingCopy = ACCOUNT_STANDING_STRIP_COPY
  protected readonly signedIn = hilosSignal(hilosSignedIn)
  protected readonly signOutAction = createHilosTrackedAction()
  protected readonly signOutCopy = SIGN_OUT_COPY
  // The "Access closed" card (HIL-289): the session lost its account to a
  // block, so the content gives way to the card on every url - the header and
  // the footer stay, and whatever the content held, modals included, goes with
  // it. Under the maintenance surface rather than over it: what is about the
  // node comes first.
  protected readonly accountBlocked = hilosSignal(hilosAccountBlocked)
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

  // The fourth framework strip (HIL-1260): a viewer of the admin view mode, on
  // an admin route — the framework's and a project's alike, the dashboard
  // included — is told once that the screen may be looked at and not changed.
  // Its text carries the id every control the mode disables names in
  // aria-describedby (HIL-1261), so the reason is said in one place. It is grey
  // because yellow, blue and red already mean "not well", frozen and blocked in
  // this region, and it is last of the framework's strips: it is about the
  // screen one stands on, so it sits nearest to it. A grant takes it down live
  // and a revoke brings it back, both through the access the session derives;
  // under maintenance there is no admin screen to speak of.
  protected readonly viewModeStrip = computed(
    () =>
      this.adminAccess() === 'view' &&
      this.adminSurface() &&
      !this.underMaintenance(),
  )
  protected readonly viewModeCopy = HILOS_VIEW_MODE_COPY
  protected readonly viewModeStripTextId = HILOS_VIEW_MODE_STRIP_TEXT_ID

  // The "the terms have changed" screen (HIL-500). The core decides everything
  // from the session: whether something is due (the icon), whether the window
  // stands (raised by a sign-in in this tab, or by the icon), and whether the
  // account is frozen (the screen in the content's place, on every page the
  // freeze does not leave open). The window and the freeze screen draw one
  // store; the days of the icon are counted again once a minute while it
  // stands, like the deletion strip's.
  protected readonly reconsent = hilosLegalReconsent
  protected readonly reconsentCopy = LEGAL_RECONSENT_COPY
  protected readonly reconsentDue = hilosSignal(hilosLegalReconsentDue)
  protected readonly reconsentOpen = hilosSignal(hilosLegalReconsentOpen)
  protected readonly reconsentPerson = hilosSignal(hilosLegalReconsentPerson)
  protected readonly reconsentContent = hilosSignal(hilosLegalReconsent.content)
  protected readonly reconsentLoading = hilosSignal(hilosLegalReconsent.loading)
  protected readonly reconsentError = hilosSignal(hilosLegalReconsent.error)
  protected readonly reconsentBusy = hilosSignal(hilosLegalReconsent.busy)
  protected readonly reconsentView = hilosSignal(hilosLegalReconsent.view)
  private readonly frozen = hilosSignal(hilosFrozenScreen)
  private readonly standing = hilosSignal(hilosAccountStanding)
  protected readonly deletionScheduled = computed(
    () => this.standing()?.deletionEffectiveAt != null,
  )
  protected readonly reconsentNow = signal(Date.now())
  private readonly reconsentCounting = computed(
    () => this.reconsentDue() !== null || this.frozen(),
  )
  protected readonly reconsentBadge = computed(() => {
    const due = this.reconsentDue()
    return due === null
      ? ''
      : formatHilosLegalReconsentBadge(due, this.reconsentNow())
  })
  protected readonly frozenShown = computed(
    () =>
      this.frozen() &&
      !HILOS_FROZEN_OPEN_PAGES.includes(this.currentRoute().page),
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

    // Count the deletion strip's days left again once a minute while it stands,
    // and afresh whenever it goes up.
    effect((onCleanup) => {
      const standing = this.deletionStanding()
      untracked(() => this.deletionNow.set(Date.now()))
      if (!standing) {
        return
      }
      const tick = setInterval(
        () => this.deletionNow.set(Date.now()),
        ACCOUNT_DELETION_TICK_MS,
      )
      onCleanup(() => clearInterval(tick))
    })

    // Count the re-consent icon's days again once a minute while something is
    // due, and read the freeze screen's content afresh each time it rises.
    effect((onCleanup) => {
      const counting = this.reconsentCounting()
      untracked(() => this.reconsentNow.set(Date.now()))
      if (!counting) {
        return
      }
      const tick = setInterval(
        () => this.reconsentNow.set(Date.now()),
        ACCOUNT_DELETION_TICK_MS,
      )
      onCleanup(() => clearInterval(tick))
    })
    effect(() => {
      if (this.frozenShown()) {
        untracked(() => void hilosLegalReconsent.load())
      }
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

  /** Keep the account through the tracked driver; a second press while busy is dropped. */
  protected onKeepAccount(): void {
    if (this.keepAccount.busy()) {
      return
    }
    void this.keepAccount.run(keepMyAccount())
  }

  /** Open the re-consent window from the header icon. */
  protected openReconsent(): void {
    openLegalReconsent()
  }

  /** Close the re-consent window: Later, Esc, the cross. */
  protected onReconsentLater(): void {
    closeLegalReconsent()
  }

  /** Accept the terms the screen shows; the store keeps its own busy flag. */
  protected onReconsentAccept(): void {
    void hilosLegalReconsent.accept()
  }

  /** Read the screen's content again after a failed read. */
  protected onReconsentRetry(): void {
    void hilosLegalReconsent.load()
  }

  /** Sign out through the tracked driver; a second press while busy is dropped. */
  protected onSignOut(): void {
    if (this.signOutAction.busy()) {
      return
    }
    void this.signOutAction.run(signOut())
  }
}
