// HilosDashboardPage — the framework Hilos admin dashboard (HilosPages.DASHBOARD):
// the entry to the admin section reached by the shell's gear over the live socket.
// It renders the admin sections grouped from the framework admin catalog (@hilos/core
// HILOS_ADMIN_DASHBOARD_SECTIONS) as no-refresh HilosLink cards. It is self-contained
// (no project context), so it is the framework default for the dashboard key; a
// project that wants its own admin areas above the framework sections wraps this page
// and projects them through the `<ng-content>` top slot (the chat demo's bots /
// moderation cards). Bootstrap classes only (styling-rules.md).
import { ChangeDetectionStrategy, Component } from '@angular/core'
import {
  HILOS_ADMIN_DASHBOARD_SECTIONS,
  HILOS_ADMIN_PAGES,
  resolveHilosPath,
} from '@hilos/core'

import { HilosLink } from '../../HilosLink.js'

/** One section card resolved from the admin catalog. */
interface DashboardCard {
  page: string
  title: string
  lead: string
  icon: string
  to: string
}

/** A labelled group of section cards on the dashboard. */
interface DashboardSection {
  title: string
  description: string
  items: DashboardCard[]
}

const DASHBOARD_SECTIONS: DashboardSection[] =
  HILOS_ADMIN_DASHBOARD_SECTIONS.map((section) => ({
    title: section.title,
    description: section.description,
    items: section.items.map((page) => ({
      page,
      title: HILOS_ADMIN_PAGES[page]?.label ?? page,
      lead: HILOS_ADMIN_PAGES[page]?.lead ?? '',
      icon: HILOS_ADMIN_PAGES[page]?.icon ?? 'bi-square',
      to: resolveHilosPath(page),
    })),
  }))

/** The framework admin dashboard: grouped section cards over the admin catalog. */
@Component({
  selector: 'hilos-dashboard-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLink],
  template: `
    <section data-id="dashboard-view">
      <div class="d-flex flex-column gap-1 mb-4">
        <h1 class="h4 mb-0">Hilos</h1>
        <p class="mb-0 text-body-secondary">
          Administrative sections with quick access to key project areas.
        </p>
      </div>

      <!-- Project-supplied admin areas above the framework sections; empty by
      default, filled by a project wrapper (the chat demo's bots / moderation). -->
      <ng-content />

      @for (section of sections; track section.title) {
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
                        <i class="bi {{ item.icon }}" aria-hidden="true"></i>
                      </span>
                      <span class="d-flex flex-column gap-1">
                        <span class="h6 mb-0">{{ item.title }}</span>
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
  /** Section cards grouped for display, resolved once from the admin catalog. */
  protected readonly sections = DASHBOARD_SECTIONS
}
