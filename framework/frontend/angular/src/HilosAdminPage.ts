// HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
// lead common to every Hilos admin page, plus a default body. A page passes only
// its key ([page]); the shell reads the live route params from the navigator to
// keep the breadcrumb and child links in context, and resolves label / lead /
// parent from the framework admin tree (HILOS_ADMIN_PAGES). It is page-agnostic —
// it renders whichever key it is given, never choosing the page itself (that is
// the app shell's page->view map). The default body is the section's
// sub-navigation cards, or a stub empty-state for a leaf; a real page projects
// its own content to replace the default while keeping the shell.
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
import {
  HILOS_ADMIN_PAGES,
  hilosAdminBreadcrumb,
  hilosAdminChildren,
} from '@hilos/core'

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
      <hilos-breadcrumb [crumbs]="crumbs()" />
      <h1 class="h4 mb-1" data-id="hilos-admin-title">
        {{ meta()?.label ?? page() }}
      </h1>
      @if (meta()?.lead; as lead) {
        <p class="text-body-secondary">{{ lead }}</p>
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
        } @else {
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

  protected readonly meta = computed(() => HILOS_ADMIN_PAGES[this.page()])
  protected readonly crumbs = computed(() =>
    hilosAdminBreadcrumb(this.page(), this.route().params),
  )
  protected readonly children = computed(() =>
    hilosAdminChildren(this.page(), this.route().params),
  )
}
