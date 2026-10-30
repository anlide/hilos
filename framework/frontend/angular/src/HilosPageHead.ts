// HilosPageHead — the shared head of a page whose owner opted in: the
// breadcrumb, the owner's block (a section head or an entity block) with an
// optional transition button on its right, and the owner's one row of page tabs
// (docs/agents/architecture/page-head.md). It is page-agnostic: it reads the
// identity the page answered with and nothing else, so it knows no concrete
// page, and the page module hands it the block's content as templates.
//
// Only the block's content is the caller's. The title is not: the one h1 shows
// the owner's label, the live instance name already laid into it by the core,
// and it carries the id the caller hands in (a table without a title of its own
// takes its accessible name from it). A section draws its catalog icon in a
// circle and its lead by default; an entity draws what its page supplies. A
// template given in place of a default replaces it. The only button is the one
// transition `action` names — no free buttons, no edit or delete here.
//
// The breadcrumb is drawn only from two links up and the row only from two tabs
// up, counted after the route map has dropped the tabs this application has no
// address for. The current tab is the one whose page key is the rendered page:
// not a label, a path prefix or a position, so a child of a tab marks itself and
// not its parent. Every tab hands HilosLink its current mark through
// hilosLinkCurrent, so the link's own path match cannot mark another tab while
// the router is already elsewhere.
//
// Until the answer arrives — or an identity without an owner, which is not a
// head this component draws — only a skeleton stands: no crumbs, no row, no
// name, no block content, no button. Its bars are decorative; the routed outlet
// announces the wait once for the whole page.
//
// On a narrow screen the row scrolls sideways and the current tab is centered in
// the row's scroll limits: after the render that mounts or reveals the row, after
// the one that changes the current key or the row's composition, and when the
// row's box is resized — never on another render, so a manual scroll or a rename
// does not snap it back. Bootstrap classes and the SDK's own utilities only
// (styling-rules.md).
import { NgTemplateOutlet } from '@angular/common'
import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  afterNextRender,
  computed,
  effect,
  inject,
  input,
  viewChild,
} from '@angular/core'
import type { TemplateRef } from '@angular/core'
import { hilosCrumbLinks, hilosTabLinks } from '@hilos/core/base'
import type {
  HilosCrumb,
  HilosPageHeadAction,
  HilosPageIdentity,
  HilosPageTabLink,
  HilosPathResolver,
} from '@hilos/core/base'

import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HilosLink } from './HilosLink.js'

// Distinct ids so two heads alive at once never give their headings the same one.
let pageHeadHeadingSeq = 0

/**
 * The shared page head: breadcrumb, the owner's block and its tab row, or a
 * silent skeleton until the page has answered with an owner.
 */
@Component({
  selector: 'hilos-page-head',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosBreadcrumb, HilosLink, NgTemplateOutlet],
  template: `
    <div class="min-w-0" data-id="hilos-page-head">
      @if (owner(); as headOwner) {
        @if (crumbs().length > 1) {
          <hilos-breadcrumb [crumbs]="crumbs()" [label]="breadcrumbLabel()" />
        }
        <div
          class="d-flex align-items-start gap-3"
          [class.mb-3]="rowShown()"
          [class.mb-4]="!rowShown()"
          data-id="hilos-page-head-block"
        >
          @if (visual(); as visualTemplate) {
            <ng-container [ngTemplateOutlet]="visualTemplate" />
          } @else if (kind() === 'section' && headOwner.icon) {
            <span
              class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
              aria-hidden="true"
            >
              <i class="bi" [class]="headOwner.icon"></i>
            </span>
          }
          <div
            class="d-flex flex-wrap align-items-start column-gap-3 row-gap-2 flex-grow-1 min-w-0"
          >
            <div class="flex-grow-1 min-w-0">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h1
                  [id]="titleId()"
                  class="h4 mb-0 text-break"
                  [attr.data-id]="titleDataId() ?? 'hilos-page-head-title'"
                >
                  {{ headOwner.label }}
                </h1>
                @if (meta(); as metaTemplate) {
                  <ng-container [ngTemplateOutlet]="metaTemplate" />
                }
                @if (badges(); as badgesTemplate) {
                  <ng-container [ngTemplateOutlet]="badgesTemplate" />
                }
              </div>
              @if (description(); as descriptionTemplate) {
                <ng-container [ngTemplateOutlet]="descriptionTemplate" />
              } @else if (kind() === 'section' && headOwner.lead) {
                <p class="text-body-secondary mb-0">{{ headOwner.lead }}</p>
              }
            </div>
            @if (action(); as headAction) {
              @if (actionTo(); as to) {
                <a
                  [hilosLink]="to"
                  class="btn btn-sm btn-outline-secondary text-nowrap"
                  data-id="hilos-page-head-action"
                  >{{ headAction.label }}</a
                >
              }
            }
          </div>
        </div>
        @if (rowShown()) {
          <nav
            [attr.aria-label]="tabsLabel()"
            class="mb-4 min-w-0"
            data-id="hilos-page-tabs"
          >
            <ul
              #row
              class="nav nav-underline flex-nowrap overflow-x-auto border-bottom"
            >
              @for (tab of tabs(); track tab.page) {
                <li class="nav-item flex-shrink-0">
                  <a
                    [hilosLink]="tab.to"
                    [hilosLinkCurrent]="tab.page === page() ? 'page' : null"
                    class="nav-link text-nowrap"
                    [class.active]="tab.page === page()"
                    [attr.data-id]="'hilos-page-tab-' + tab.page"
                    >{{ tab.label }}</a
                  >
                </li>
              }
            </ul>
          </nav>
        }
      } @else {
        <div
          class="d-flex align-items-start gap-3 mb-4 placeholder-glow"
          aria-hidden="true"
          data-id="hilos-page-head-skeleton"
        >
          <span
            class="hilos-avatar-md bg-secondary-subtle flex-shrink-0"
            [class.rounded-3]="kind() === 'entity'"
            [class.rounded-circle]="kind() !== 'entity'"
          ></span>
          <div class="flex-grow-1 min-w-0">
            <span
              class="placeholder placeholder-lg col-4 d-block mb-2 rounded"
            ></span>
            <span class="placeholder col-9 d-block rounded"></span>
          </div>
        </div>
      }
    </div>
  `,
})
export class HilosPageHead {
  /** What the page answered with; absent while the answer is on the wire. */
  readonly identity = input<HilosPageIdentity>()
  /** The key of the rendered page; the tab carrying it is current. */
  readonly page = input.required<string>()
  /** The current route params every link is resolved in. */
  readonly params = input.required<Record<string, string>>()
  /** Resolves a page key to its path (`HilosRouter.resolvePath`). */
  readonly resolvePath = input.required<HilosPathResolver>()
  /** The id the h1 carries, from the caller; a local one when omitted. */
  readonly headingId = input<string>()
  /** Replaces the h1's data-id; omitted or undefined keeps hilos-page-head-title. */
  readonly titleDataId = input<string>()
  /** A section head or an entity block: the defaults and the skeleton's tile. */
  readonly kind = input<'section' | 'entity'>('section')
  /** The breadcrumb navigation's accessible name. */
  readonly breadcrumbLabel = input('Breadcrumb')
  /** The tab row navigation's accessible name, named after the owner. */
  readonly tabsLabel = input('Page sections')
  /** The one transition the head offers as a button. */
  readonly action = input<HilosPageHeadAction>()
  /** The block's picture: an entity's tile or avatar; a section's icon by default. */
  readonly visual = input<TemplateRef<unknown>>()
  /** A short mark beside the title, such as the entity's code. */
  readonly meta = input<TemplateRef<unknown>>()
  /** Status badges beside the title. */
  readonly badges = input<TemplateRef<unknown>>()
  /** The line under the title; a section's lead by default. */
  readonly description = input<TemplateRef<unknown>>()

  private readonly injector = inject(Injector)
  private readonly row = viewChild<ElementRef<HTMLUListElement>>('row')
  private readonly localHeadingId = `hilos-page-head-title-${pageHeadHeadingSeq++}`

  protected readonly titleId = computed(
    () => this.headingId() ?? this.localHeadingId,
  )
  protected readonly owner = computed(() => this.identity()?.pageHeadOwner)
  protected readonly crumbs = computed<HilosCrumb[]>(() =>
    this.owner() === undefined
      ? []
      : hilosCrumbLinks(
          this.identity()?.breadcrumb ?? [],
          this.params(),
          this.resolvePath(),
        ),
  )
  protected readonly tabs = computed<HilosPageTabLink[]>(() =>
    this.owner() === undefined
      ? []
      : hilosTabLinks(
          this.identity()?.pageTabs ?? [],
          this.params(),
          this.resolvePath(),
        ),
  )
  protected readonly rowShown = computed(() => this.tabs().length > 1)
  protected readonly actionTo = computed(() => {
    const action = this.action()

    return action === undefined
      ? undefined
      : this.resolvePath()(action.page, action.params ?? this.params())
  })

  // The keys alone, so a new caption or a rename that leaves the row as it was
  // does not re-center it over a scroll the reader made by hand.
  private readonly rowKeys = computed(() =>
    this.tabs()
      .map((tab) => tab.page)
      .join('\n'),
  )

  constructor() {
    // Center once the render that brought the change is on screen. A newer
    // change, or the head going away, drops a render callback still waiting.
    effect((onCleanup) => {
      this.page()
      this.rowKeys()
      const pending = afterNextRender(
        () => centerCurrentTab(this.row()?.nativeElement),
        { injector: this.injector },
      )
      onCleanup(() => pending.destroy())
    })
    // The observer follows the row node itself: it is attached when the row is
    // drawn and let go when the row disappears or the head is destroyed.
    effect((onCleanup) => {
      const container = this.row()?.nativeElement
      if (container === undefined || typeof ResizeObserver === 'undefined') {
        return
      }
      const observer = new ResizeObserver(() => centerCurrentTab(container))
      observer.observe(container)
      onCleanup(() => observer.disconnect())
    })
  }
}

/**
 * Scroll the row so the current tab stands in its middle, within the row's
 * scroll limits; a row that is not drawn, detached, or without a current tab is
 * left alone. The jump is instant: nothing animates and nothing takes focus.
 *
 * @param container The row's list, or undefined when the row is not drawn.
 */
function centerCurrentTab(container: HTMLElement | undefined): void {
  if (container === undefined || !container.isConnected) {
    return
  }
  const current = container.querySelector<HTMLElement>('[aria-current="page"]')
  if (current === null) {
    return
  }
  const box = container.getBoundingClientRect()
  const tab = current.getBoundingClientRect()
  const target =
    container.scrollLeft +
    (tab.left - box.left) -
    (container.clientWidth - tab.width) / 2
  const limit = Math.max(0, container.scrollWidth - container.clientWidth)
  container.scrollLeft = Math.min(Math.max(target, 0), limit)
}
