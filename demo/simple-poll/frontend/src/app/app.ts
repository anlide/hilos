// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand slot and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import type { Type } from '@angular/core'
import { HilosDashboardPage, HilosLayout, HilosView } from '@hilos/angular'
import { HilosPages } from '@hilos/core'

import { connection } from './bootstrap/connection'
import { PAGE_MAIN } from './pages/keys'
import { About } from './views/about/about'
import { License } from './views/license/license'
import { Main } from './views/main/main'
import { Privacy } from './views/privacy/privacy'
import { Terms } from './views/terms/terms'

@Component({
  selector: 'app-root',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosLayout, HilosView],
  template: `<hilos-layout [connection]="connection">
    <span brand>Hilos Poll</span>
    <hilos-view [pages]="pages" />
  </hilos-layout>`,
})
export class App {
  protected readonly connection = connection

  // The page-key → view map HilosView renders from. Pages without a mapped view
  // (other routes land later) render nothing.
  protected readonly pages: Record<string, Type<unknown>> = {
    [PAGE_MAIN]: Main,
    // The framework dashboard is rendered straight from the SDK — this demo has
    // no admin areas of its own, so it gets the framework sections as-is (a
    // project that does would wrap HilosDashboardPage and project its cards).
    [HilosPages.DASHBOARD]: HilosDashboardPage,
    [HilosPages.ABOUT]: About,
    [HilosPages.TERMS]: Terms,
    [HilosPages.PRIVACY]: Privacy,
    [HilosPages.LICENSE]: License,
  }
}
