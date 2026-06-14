// HilosStaticPage — the tier-1 wrapper for a static, content-only framework page
// (About / Terms / Privacy / License and the like). The framework owns the page
// frame — a centered single reading column with a heading — and a project
// supplies the content as projected content, so the look stays uniform while the
// content stays a project concern. Long content scrolls within the shell's main
// region (HilosLayout). Styling is Bootstrap classes only, no CSS of its own
// (styling-rules.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'

/**
 * The frame for a static content page: a centered reading column with a heading
 * above the project-projected content.
 */
@Component({
  selector: 'hilos-static-page',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <article class="row justify-content-center" data-id="static-page">
      <div class="col-12 col-lg-8">
        <h1 class="h3 mb-4" data-id="static-page-title">{{ title() }}</h1>
        <ng-content />
      </div>
    </article>
  `,
})
export class HilosStaticPage {
  /** The page heading. */
  readonly title = input.required<string>()
}
