// The main page's skeleton (HIL-983): what the routed outlet draws while the
// page waits for its first answer. The page is one line today — who is browsing
// — so its skeleton is one bar standing in the same paragraph, at the height of
// that line, and nothing moves when the page lands. Decorative: the outlet
// announces the wait once.
import { ChangeDetectionStrategy, Component } from '@angular/core'

@Component({
  selector: 'app-main-skeleton',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<p class="placeholder-glow" aria-hidden="true">
    <span class="placeholder col-4 rounded"></span>
  </p>`,
})
export class MainSkeleton {}
