// The framework admin dashboard (HilosPages.DASHBOARD). A placeholder for now:
// the point it proves is the no-refresh transition that reaches it (the gear in
// HilosLayout) over the live socket. The real dashboard content lands with the
// admin section.
import { ChangeDetectionStrategy, Component } from '@angular/core'

@Component({
  selector: 'app-dashboard',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<section data-id="dashboard-view">
    <h1 class="h4">Hilos dashboard</h1>
    <p class="text-body-secondary">Framework admin section.</p>
  </section>`,
})
export class Dashboard {}
