// The public Privacy page (HilosPages.PRIVACY). A framework-declared static
// page; this project supplies the content and nothing else. The frame, the
// heading and the erase block under the prose are the framework's
// (HilosPrivacyPage), and this demo declares no browser value of its own, so it
// hands in no `values`. See views/about/about.ts.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosPrivacyPage } from '@hilos/angular'

import { actions, connection } from '../../bootstrap/connection.js'

@Component({
  selector: 'app-privacy',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosPrivacyPage],
  template: `<hilos-privacy-page [connection]="connection" [actions]="actions">
    <p>
      This demo stores what an account and its sign-in need: your email address,
      your password in hashed form, the display name taken from the address, the
      terms you accepted, your browser sessions, and — for a while — the network
      address of repeated failed attempts.
    </p>
    <p>
      This demo records analytics: times, network addresses, browser and
      language details, visited pages and route parameters, and names of actions
      and internal signals. It does not store action contents or arbitrary API
      request bodies in analytics.
    </p>
    <p class="mb-0">
      After account deletion, your numeric account number, analytics events, and
      network addresses remain. There is currently no automatic deletion period
      for raw analytics. This demo uses no third-party trackers, and demo data
      may be reset at any time.
    </p>
  </hilos-privacy-page>`,
})
export class Privacy {
  protected readonly connection = connection
  protected readonly actions = actions
}
