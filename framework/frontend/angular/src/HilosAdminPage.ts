// HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
// lead common to every Hilos admin page, plus a default body. A page passes only
// its key ([page]); the shell reads the live route params from the navigator to
// keep the breadcrumb and child links in context, and takes the heading, the lead,
// the chain and the subsection cards from the navigator's pageIdentity — what the
// page's own subscription answered with, not a frontend constant. It is
// page-agnostic — it renders whichever key it is given, never choosing the page
// itself (that is the app shell's page->view map). The default body is the
// section's sub-navigation cards, or a stub empty-state for a leaf; a real page
// projects its own content to replace the default while keeping the shell.
//
// While the identity is still on the wire the heading is a neutral placeholder and
// nothing else of the shell is drawn: the raw page key is never printed, and an
// empty h1 under the same data-id would make "the name did not arrive" look
// exactly like "the name arrived empty".
//
// A section ROOT that has content of its own projects it with the `body`
// attribute instead, which is drawn after the default content: it needs both, the
// cards to its children and its own figures beneath them, and replacing the
// default content would cost it the cards. A leaf page goes on replacing the
// default content as before. Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
} from '@angular/core'
import { hilosChildLinks, hilosCrumbLinks } from '@hilos/core'

import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HilosLink } from './HilosLink.js'
import { HILOS_ROUTER } from './hilosRouterToken.js'
import { hilosSignal } from './hilosSignal.js'

/** The admin section shell: breadcrumb, heading, lead, and a default body. */
@Component({
  selector: 'hilos-admin-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosBreadcrumb, HilosLink],
  template: `
    <section data-id="hilos-admin-page" [attr.data-page]="page()">
      @if (identity(); as pageIdentity) {
        <hilos-breadcrumb [crumbs]="crumbs()" />
        <h1 class="h4 mb-1" data-id="hilos-admin-title">
          {{ pageIdentity.label }}
        </h1>
        @if (pageIdentity.lead) {
          <p class="text-body-secondary">{{ pageIdentity.lead }}</p>
        }
      } @else {
        <div class="placeholder-glow mb-3" data-id="hilos-admin-title-skeleton">
          <span class="placeholder col-3 d-block mb-2 rounded"></span>
          <span class="placeholder col-6 d-block rounded"></span>
        </div>
      }

      <ng-content>
        @if (children().length) {
          <div
            class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3"
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
        } @else if (identity()) {
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
      <ng-content select="[body]" />
    </section>
  `,
})
export class HilosAdminPage {
  /** The admin page key whose shell is rendered. */
  readonly page = input.required<string>()

  private readonly router = inject(HILOS_ROUTER)
  private readonly route = hilosSignal(this.router.currentRoute)

  protected readonly identity = hilosSignal(this.router.pageIdentity)
  protected readonly crumbs = computed(() =>
    hilosCrumbLinks(
      this.identity()?.breadcrumb ?? [],
      this.route().params,
      this.router.resolvePath,
    ),
  )
  protected readonly children = computed(() =>
    hilosChildLinks(
      this.identity()?.children ?? [],
      this.route().params,
      this.router.resolvePath,
    ),
  )
}
