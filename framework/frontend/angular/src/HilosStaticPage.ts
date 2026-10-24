// HilosStaticPage — the frame for a static page: a centered reading column
// with a heading, the project filling the body. It frames a project's own static
// pages, and it is what the four public framework pages render inside themselves;
// it is neither their component nor widened for their behavior. A project
// supplies the content as projected content, so the look stays uniform while the
// content stays a project concern. Long content scrolls within the shell's main
// region (HilosLayout). Styling is Bootstrap classes only, no CSS of its own
// (styling-rules.md). No demo carries a static page of its own, because all four
// static pages a demo shows belong to the framework; the frame stays exported for
// a project that has one.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
} from '@angular/core'
import type {
  HilosPageIdentity,
  HilosStaticPageNavigation,
} from '@hilos/core/base'

import { HilosPageHead } from './HilosPageHead.js'
import { HILOS_PAGE_HEADING_ID } from './hilosPageHeadingToken.js'

// Distinct ids for static frames mounted beside one another.
let staticPageHeadingSeq = 0

/**
 * The frame for a static content page: a centered reading column with a heading
 * above the project-projected content.
 */
@Component({
  selector: 'hilos-static-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosPageHead],
  // Projected content reads the same id as the head through this provider.
  providers: [
    {
      provide: HILOS_PAGE_HEADING_ID,
      useFactory: () => `hilos-static-page-title-${staticPageHeadingSeq++}`,
    },
  ],
  template: `
    <article class="row justify-content-center" data-id="static-page">
      <div class="col-12 col-lg-8">
        <hilos-page-head
          [identity]="identity()"
          [page]="page()"
          [params]="navigation()?.params ?? {}"
          [resolvePath]="resolvePath()"
          [headingId]="headingId"
          kind="section"
          [tabsLabel]="navigation()?.label ?? 'Page sections'"
        />
        <ng-content />
      </div>
    </article>
  `,
})
export class HilosStaticPage {
  /** The page heading. */
  readonly title = input.required<string>()
  /** A decorative Bootstrap icon beside the heading. */
  readonly icon = input<string>()
  /** The line under the heading. */
  readonly subtitle = input<string>()
  /** The page's optional section navigation. */
  readonly navigation = input<HilosStaticPageNavigation>()

  protected readonly headingId = inject(HILOS_PAGE_HEADING_ID)
  protected readonly page = computed(() => this.navigation()?.page ?? '')
  protected readonly resolvePath = computed(
    () => this.navigation()?.resolvePath ?? (() => undefined),
  )
  protected readonly identity = computed<HilosPageIdentity>(() => ({
    label: this.title(),
    lead: this.subtitle() ?? '',
    breadcrumb: [],
    children: [],
    pageHeadOwner: {
      page: this.navigation()?.tabs[0]?.page ?? this.page(),
      label: this.title(),
      lead: this.subtitle() ?? '',
      icon: this.icon() ?? null,
    },
    pageTabs: [...(this.navigation()?.tabs ?? [])],
  }))
}
