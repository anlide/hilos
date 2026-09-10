// The public Privacy page (HilosPages.PRIVACY). A framework-declared static
// page; this project supplies the content and nothing else. The frame, the
// heading and the erase block under the prose are the framework's
// (HilosPrivacyPage), and this demo declares no browser value of its own, so it
// hands in no `values`. See views/about/about.ts.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosPrivacyPage } from '@hilos/angular'

import { actions, connection } from '../../bootstrap/connection'

@Component({
  selector: 'app-privacy',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosPrivacyPage],
  template: `<hilos-privacy-page
    [connection]="connection"
    [actions]="actions"
  >
    <p>
      This demo stores only the data needed to show its real-time features: your
      chosen display name and the votes you cast.
    </p>
    <p class="mb-0">
      No analytics or third-party trackers are used, and demo data may be reset
      at any time.
    </p>
  </hilos-privacy-page>`,
})
export class Privacy {
  protected readonly connection = connection
  protected readonly actions = actions
}
