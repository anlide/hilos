// HilosDashboardPage — the framework Hilos admin dashboard (HilosPages.DASHBOARD):
// the entry to the admin section reached by the shell's gear over the live socket.
// It renders the admin sections the dashboard's own subscription answered with,
// framework groups first and a project's own after them, as no-refresh HilosLink
// cards. It is self-contained (no project context), so it is the framework default
// for the dashboard key; a project declares its cards in its page catalog on the
// backend rather than wrapping this page, and the content seam is left for content
// that is not a card at all (the Vue and React ports offer it too). The sections
// are a computed, not a module constant: nothing is known about them at module
// load, they arrive with the page — and so does the heading, read from the page
// catalog over the same answer, which is why no literal is left. Until they do, the
// heading and the cards are placeholders — an empty grid would jump the layout on
// every visit.
// Bootstrap classes only (styling-rules.md).
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
} from '@angular/core'

import { HilosLink } from '../../HilosLink.js'
import { HILOS_ROUTER } from '../../hilosRouterToken.js'
import { hilosSignal } from '../../hilosSignal.js'

/** One section card resolved to an address. */
interface DashboardCard {
  page: string
  label: string
  lead: string
  icon: string | null
  to: string
}

/** A labelled group of section cards on the dashboard. */
interface DashboardSection {
  title: string
  description: string
  items: DashboardCard[]
}

/** Placeholder cards drawn while the sections are still on the wire. */
const SKELETON_CARDS: readonly number[] = [0, 1, 2, 3, 4, 5]

/** The framework admin dashboard: grouped section cards over the admin catalog. */
@Component({
  selector: 'hilos-dashboard-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    <section data-id="dashboard-view">
      <div class="d-flex flex-column gap-1 mb-4">
        @if (identity(); as pageIdentity) {
          <h1 class="h4 mb-0" data-id="dashboard-title">
            {{ pageIdentity.label }}
          </h1>
          @if (pageIdentity.lead) {
            <p class="mb-0 text-body-secondary">{{ pageIdentity.lead }}</p>
          }
        } @else {
          <div class="placeholder-glow" data-id="dashboard-title-skeleton">
            <span class="placeholder col-3 d-block mb-2 rounded"></span>
            <span class="placeholder col-6 d-block rounded"></span>
          </div>
        }
      </div>

      <!-- Project-supplied admin areas above the framework sections; empty by
      default. A project's own cards ride its page catalog on the backend, so this
      seam is for content that is not a card at all. -->
      <ng-content />

      @if (answered() === undefined) {
        <div
          class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 placeholder-glow"
          data-id="dashboard-skeleton"
        >
          @for (slot of skeletonCards; track slot) {
            <div class="col">
              <span class="placeholder col-12 rounded py-5 d-block"></span>
            </div>
          }
        </div>
      }

      @for (section of sections(); track section.title) {
        <div class="mb-4">
          <div class="mb-3">
            <h2 class="h6 text-uppercase text-body-secondary mb-1">
              {{ section.title }}
            </h2>
            <p class="mb-0 text-body-secondary">{{ section.description }}</p>
          </div>

          <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
            @for (item of section.items; track item.page) {
              <div class="col">
                <a
                  [hilosLink]="item.to"
                  class="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
                  [attr.data-id]="'dashboard-card-' + item.page"
                >
                  <div class="card-body">
                    <div class="d-flex align-items-start gap-3">
                      <span
                        class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
                      >
                        <i
                          class="bi {{ item.icon ?? 'bi-square' }}"
                          aria-hidden="true"
                        ></i>
                      </span>
                      <span class="d-flex flex-column gap-1">
                        <span class="h6 mb-0">{{ item.label }}</span>
                        <span class="small text-body-secondary">{{
                          item.lead
                        }}</span>
                      </span>
                    </div>
                  </div>
                </a>
              </div>
            }
          </div>
        </div>
      }
    </section>
  `,
})
export class HilosDashboardPage {
  private readonly router = inject(HILOS_ROUTER)

  protected readonly skeletonCards = SKELETON_CARDS

  protected readonly identity = hilosSignal(this.router.pageIdentity)

  protected readonly answered = hilosSignal(this.router.dashboardSections)

  /** Section cards grouped for display, resolved against the app's route map. */
  protected readonly sections = computed<DashboardSection[]>(() =>
    (this.answered() ?? []).map((section) => ({
      title: section.title,
      description: section.description,
      // A card with no address is left out: a card IS its target, and the shell
      // must not offer one that goes nowhere.
      items: section.items.flatMap((item) => {
        const to = this.router.resolvePath(item.page)

        return to === undefined ? [] : [{ ...item, to }]
      }),
    })),
  )
}
