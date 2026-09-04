// The public Privacy page (HilosPages.PRIVACY). A framework-declared static
// page; this project supplies the content. See views/about/about.ts.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStaticPage } from '@hilos/angular'

@Component({
  selector: 'app-privacy',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStaticPage],
  template: `<hilos-static-page title="Privacy">
    <p>
      This demo stores only the data needed to show its real-time features: your
      chosen display name and the votes you cast.
    </p>
    <p class="mb-0">
      No analytics or third-party trackers are used, and demo data may be reset
      at any time.
    </p>
  </hilos-static-page>`,
})
export class Privacy {}
