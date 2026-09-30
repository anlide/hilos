// The public Terms page (HilosPages.TERMS). A framework-declared static page;
// this project supplies the content. See views/about/about.ts.
import { ChangeDetectionStrategy, Component } from '@angular/core'
import { HilosStaticPage } from '@hilos/angular'

@Component({
  selector: 'app-terms',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [HilosStaticPage],
  template: `<hilos-static-page title="Terms of Service">
    <p>
      This is a demonstration application provided for evaluation purposes only,
      without warranty of any kind.
    </p>
    <p>
      Nothing it shows is an exam: the tests, the scores and the verdicts it
      will show are made up to show the framework's real-time features, and none
      of them certifies anything.
    </p>
    <p class="mb-0">Using this demo implies acceptance of these terms.</p>
  </hilos-static-page>`,
})
export class Terms {}
