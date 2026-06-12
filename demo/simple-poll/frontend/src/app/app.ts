// Root view. The application shell is the SDK's HilosLayout; the demo fills its
// brand slot and routes the content through HilosView, which renders the
// component mapped to the navigator's current page. The brand and the shell's
// gear move between the main page and the framework dashboard with no refresh.
// The live connection state is the shell's own indicator.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import type { Type } from '@angular/core'
import { HilosLayout, HilosView } from '@hilos/angular'
import { HilosPages } from '@hilos/core'

import { connection } from './connection'
import { PAGE_MAIN } from './pageKeys'
import { DashboardView } from './views/dashboard-view'
import { MainView } from './views/main-view'

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
    [PAGE_MAIN]: MainView,
    [HilosPages.DASHBOARD]: DashboardView,
  }
}
