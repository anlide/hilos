// HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
// lead common to every Hilos admin page, plus the cards to the page's children. A
// page passes only its key ([page]); the shell reads the live route params from
// the navigator to keep the breadcrumb and child links in context, and takes the
// heading, the lead, the chain and the subsection cards from the navigator's
// pageIdentity — what the page's own subscription answered with, not a frontend
// constant. It is page-agnostic — it renders whichever key it is given, never
// choosing the page itself (that is the app shell's page->view map).
//
// A page whose answer names a head owner (pageHeadOwner: its owner opted in to
// the shared head) gets HilosPageHead in place of all that — the breadcrumb, the
// owner's block and its one row of tabs, and no heading, lead or cards of the
// shell's own (docs/agents/architecture/page-head.md). The answer alone decides
// it: the head inputs and the head templates the page projects (#headVisual,
// #headMeta, #headBadges, #headDescription) only dress the owner's block, and on
// a page without an owner they are ignored. #headSkeletonVisual may supply the
// decorative picture in that head's aria-hidden skeleton while the answer is
// pending; it does not expose the owner's real content. Until an answer arrives,
// a page that names its head kind already stands on that head's skeleton rather
// than on the legacy one.
//
// Under the legacy heading the shell always draws the section's sub-navigation
// cards — one for every child the live params can resolve — and a page's own
// projected content is drawn beneath them: the way on stands above what the
// admin came for, and no page's content can cost its children their cards. A
// leaf that projects no content gets a stub empty-state instead.
//
// While the identity is still on the wire the heading is a neutral placeholder and
// neither the cards nor the stub is drawn; a page's own content already is, and
// the cards rise above it once the identity lands. The raw page key is never
// printed, and an empty h1 under the same data-id would make "the name did not
// arrive" look exactly like "the name arrived empty".
//
// The heading — the legacy one or the shared head's — carries an id the shell
// provides to what it holds: a table that declares no title of its own takes its
// accessible name from this heading, which already names it.
//
// The shell also tells what it holds whether a viewer of the admin view mode
// stands here (hilosAdminAccess is 'view', HIL-1253): the controls of the mode
// read that themselves and stand plainly disabled (HIL-1261).
//
// A page whose resource can be deleted under it opts in with
// preserveModalDraftsOnDelete (HIL-1603), and hands the shell its content as an
// <ng-template #pageBody> and its dialogs as an <ng-template #pageModals>. Then
// the current page error replaces the head, the cards and #pageBody with one
// ErrorPage, while #pageModals stays on the same mount, under the same heading
// and view-mode providers, so an open dialog keeps its draft above the 404.
// #pageBody is a template, not projected content, because Angular creates
// projected content once in the page's own view, and an @if around it would
// only hide what stays alive. Inside the routed outlet the shell registers, once
// rendered, to hold its page through a live deletion
// (HILOS_PAGE_ERROR_BOUNDARY); outside one it simply draws the error. Its root
// offers the dialogs' focus return a fallback, the Back to home link of the
// error drawn here, for an opener that went away with the content. Without the
// opt-in nothing changes: the shell reads no page error, and the outlet
// replaces the whole page on any refusal. Bootstrap classes only
// (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  contentChild,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  input,
  signal,
  viewChild,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import {
  hilosAdminAccess,
  hilosChildLinks,
  hilosCrumbLinks,
  registerFocusReturnFallback,
} from '@hilos/core/base'
import type { HilosPageHeadAction, PageError } from '@hilos/core/base'

import { ErrorPage } from './ErrorPage.js'
import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HilosLink } from './HilosLink.js'
import { HilosPageHead } from './HilosPageHead.js'
import { HILOS_ADMIN_VIEW_MODE } from './hilosLookOnly.js'
import { HILOS_PAGE_ERROR_BOUNDARY } from './hilosPageErrorBoundaryToken.js'
import { HILOS_PAGE_HEADING_ID } from './hilosPageHeadingToken.js'
import { HILOS_ROUTER } from './hilosRouterToken.js'
import { hilosSignal, mirrorHilosSignal } from './hilosSignal.js'

// Distinct ids so two shells alive at once never give their headings the same one.
let adminPageHeadingSeq = 0

/**
 * The admin section shell: the breadcrumb, heading and lead with the cards to the
 * page's children — or the shared head when the answer names an owner — and the
 * page's own content beneath them.
 */
@Component({
  selector: 'hilos-admin-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ErrorPage,
    HilosBreadcrumb,
    HilosLink,
    HilosPageHead,
    NgTemplateOutlet,
  ],
  // Providers rather than view providers: the table that reads the id, and the
  // controls that read whether a viewer stands here, are content projected into
  // the shell by the page, which a view provider does not reach. The factories
  // run once per shell, so every shell names its own heading.
  providers: [
    {
      provide: HILOS_PAGE_HEADING_ID,
      useFactory: () => `hilos-admin-title-${adminPageHeadingSeq++}`,
    },
    {
      provide: HILOS_ADMIN_VIEW_MODE,
      useFactory: () => {
        const adminAccess = hilosSignal(hilosAdminAccess)

        return computed(() => adminAccess() === 'view')
      },
    },
  ],
  template: `
    <section #root data-id="hilos-admin-page" [attr.data-page]="page()">
      @if (refusal(); as error) {
        <hilos-error-page [error]="error" />
      } @else {
        @if (sharedHead()) {
          <hilos-page-head
            [identity]="identity()"
            [page]="page()"
            [params]="params()"
            [resolvePath]="resolvePath"
            [headingId]="headingId"
            [kind]="headKind() ?? 'section'"
            [action]="headAction()"
            [skeletonVisual]="headSkeletonVisual()"
            [breadcrumbLabel]="breadcrumbLabel()"
            [tabsLabel]="tabsLabel()"
            [visual]="headVisual()"
            [meta]="headMeta()"
            [badges]="headBadges()"
            [description]="headDescription()"
          />
        } @else if (identity(); as pageIdentity) {
          <hilos-breadcrumb [crumbs]="crumbs()" />
          <h1 [id]="headingId" class="h4 mb-1" data-id="hilos-admin-title">
            {{ pageIdentity.label }}
          </h1>
          @if (pageIdentity.lead) {
            <p class="text-body-secondary">{{ pageIdentity.lead }}</p>
          }
        } @else {
          <div
            class="placeholder-glow mb-3"
            data-id="hilos-admin-title-skeleton"
          >
            <span class="placeholder col-3 d-block mb-2 rounded"></span>
            <span class="placeholder col-6 d-block rounded"></span>
          </div>
        }

        @if (children().length) {
          <div
            class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4"
            data-id="hilos-admin-children"
          >
            @for (child of children(); track child.page) {
              <div class="col">
                <a
                  [hilosLink]="child.to"
                  class="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
                  [attr.data-id]="'hilos-admin-child-' + child.page"
                >
                  <div class="card-body d-flex flex-column gap-1">
                    <span
                      class="h6 mb-0 d-flex align-items-center justify-content-between gap-2"
                    >
                      <span>{{ child.label }}</span>
                      <i
                        class="bi bi-chevron-right text-body-secondary"
                        aria-hidden="true"
                      ></i>
                    </span>
                    <span class="small text-body-secondary">{{
                      child.lead
                    }}</span>
                  </div>
                </a>
              </div>
            }
          </div>
        }

        @if (pageBody(); as body) {
          <ng-container [ngTemplateOutlet]="body" />
        } @else {
          <ng-content>
            @if (!children().length && identity()) {
              <div
                class="border rounded p-4 text-center text-body-secondary"
                data-id="hilos-admin-empty"
              >
                <i
                  class="bi bi-cone-striped fs-2 d-block mb-2"
                  aria-hidden="true"
                ></i>
                <p class="mb-0">
                  Stub page — real content arrives with this section's
                  implementation.
                </p>
              </div>
            }
          </ng-content>
        }
      }
      <!-- After the branch, never inside it: switching to the error keeps the
      dialogs mounted, and they stand over the page wherever they are drawn. -->
      <ng-container [ngTemplateOutlet]="pageModals() ?? null" />
    </section>
  `,
})
export class HilosAdminPage {
  /** The admin page key whose shell is rendered. */
  readonly page = input.required<string>()

  /** Local selection used only to resolve links to this page's children. */
  readonly childParams = input<Record<string, string>>({})

  /**
   * Draw the page error in place of the content and keep #pageModals mounted
   * under it, so a dialog open when the page's resource is deleted keeps its
   * draft. A page opts in for its whole mount: the shell registers with the
   * outlet once, when it first renders.
   */
  readonly preserveModalDraftsOnDelete = input(false)

  /**
   * The shared head's kind: a section head or an entity block. It does not opt
   * the owner in; it only lets the skeleton stand before the answer arrives.
   */
  readonly headKind = input<'section' | 'entity'>()

  /** The one transition the shared head offers as a button. */
  readonly headAction = input<HilosPageHeadAction>()

  /** The shared head's breadcrumb navigation's accessible name. */
  readonly breadcrumbLabel = input('Breadcrumb')

  /** The shared head's tab row navigation's accessible name. */
  readonly tabsLabel = input('Page sections')

  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)
  private readonly destroyRef = inject(DestroyRef)
  private readonly retainForResourceDeletion = inject(
    HILOS_PAGE_ERROR_BOUNDARY,
    { optional: true },
  )
  private readonly root = viewChild.required<ElementRef<HTMLElement>>('root')
  private readonly errorPage = viewChild(ErrorPage)

  /** The page's content, drawn under the cards while no error replaces it. */
  protected readonly pageBody = contentChild<TemplateRef<unknown>>('pageBody')
  /** The page's dialogs: the one part an opted-in page error leaves mounted. */
  protected readonly pageModals =
    contentChild<TemplateRef<unknown>>('pageModals')
  // Without the opt-in the shell never draws a refusal and reads nothing it did
  // not read before: the outlet replaces the whole page with its own ErrorPage,
  // as it always has.
  protected readonly refusal = signal<PageError | null>(null)

  protected readonly resolvePath = this.router.resolvePath
  protected readonly headingId = inject(HILOS_PAGE_HEADING_ID)
  protected readonly identity = hilosSignal(this.router.pageIdentity)
  protected readonly params = computed(() => this.route().params)
  /** The shared head's decorative loading picture before its owner answers. */
  protected readonly headSkeletonVisual =
    contentChild<TemplateRef<unknown>>('headSkeletonVisual')
  /** The shared head's block picture: an entity's tile or avatar. */
  protected readonly headVisual =
    contentChild<TemplateRef<unknown>>('headVisual')
  /** A short mark beside the shared head's title, such as the entity's code. */
  protected readonly headMeta = contentChild<TemplateRef<unknown>>('headMeta')
  /** Status badges beside the shared head's title. */
  protected readonly headBadges =
    contentChild<TemplateRef<unknown>>('headBadges')
  /** The line under the shared head's title. */
  protected readonly headDescription =
    contentChild<TemplateRef<unknown>>('headDescription')
  // The answer's owner opts the page in; before the answer, the kind the page
  // names picks the shared head's skeleton over the legacy one.
  protected readonly sharedHead = computed(() => {
    const identity = this.identity()

    return identity === undefined
      ? this.headKind() !== undefined
      : identity.pageHeadOwner !== undefined
  })
  protected readonly crumbs = computed(() =>
    hilosCrumbLinks(
      this.identity()?.breadcrumb ?? [],
      this.params(),
      this.resolvePath,
    ),
  )
  protected readonly children = computed(() =>
    this.sharedHead()
      ? []
      : hilosChildLinks(
          this.identity()?.children ?? [],
          { ...this.params(), ...this.childParams() },
          this.resolvePath,
        ),
  )

  constructor() {
    effect((onCleanup) => {
      if (!this.preserveModalDraftsOnDelete()) {
        this.refusal.set(null)

        return
      }
      onCleanup(mirrorHilosSignal(this.router.pageError, this.refusal))
    })
    // Only once rendered: the outlet holds the component it shows at the moment
    // of registration, so the shell registers when its page is on screen. The
    // focus fallback is offered before any dialog of the page can open, and
    // lives and dies with this root.
    afterNextRender(() => {
      if (!this.preserveModalDraftsOnDelete()) {
        return
      }
      const releaseFocus = registerFocusReturnFallback(
        this.root().nativeElement,
        () => this.errorPage()?.homeLink() ?? null,
      )
      const releaseRetention = this.retainForResourceDeletion?.(this.page())
      this.destroyRef.onDestroy(() => {
        releaseRetention?.()
        releaseFocus()
      })
    })
  }
}
