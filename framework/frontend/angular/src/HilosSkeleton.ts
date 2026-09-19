// HilosSkeleton — the frame a data block draws while its data has not arrived:
// grey bars at the size and place of the content to come, not a blank and not a
// spinner over everything (the mockup's «The connection went away» screen).
// Stock Bootstrap placeholders, no CSS of its own (styling-rules.md).
//
// The bars are decorative and hidden from a screen reader. The block is silent
// by default, because it usually stands inside a surface that already says it is
// loading — the routed outlet around a page skeleton says it once for the whole
// page. A block that loads on its own on a live page passes `label`, and gets
// exactly one announcement for the whole frame, never one per bar
// (accessibility.md).
import { ChangeDetectionStrategy, Component, input } from '@angular/core'
import { HILOS_SKELETON_LINES } from '@hilos/core'

/** The placeholder bars of a data block that has not arrived. */
@Component({
  selector: 'hilos-skeleton',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="placeholder-glow" data-id="hilos-skeleton">
      @if (label(); as announced) {
        <span class="visually-hidden" role="status">{{ announced }}</span>
      }
      @for (width of lines(); track $index) {
        <span
          class="placeholder d-block rounded"
          [class]="'col-' + width"
          [class.mb-2]="!$last"
          aria-hidden="true"
        ></span>
      }
    </div>
  `,
})
export class HilosSkeleton {
  /** Bar widths in Bootstrap columns (1..12); the core default when omitted. */
  readonly lines = input<number[]>([...HILOS_SKELETON_LINES])
  /** What a screen reader announces for the block; silent when omitted. */
  readonly label = input<string | undefined>(undefined)
}
