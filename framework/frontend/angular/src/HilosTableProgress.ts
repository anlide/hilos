// HilosTableProgress — the track a running job is drawn as, the one piece of
// markup shared by the bar above a table and the bar under a row. It computes
// NOTHING about the work: the core hands over the fraction (tableProgress.ts) for
// the reason it sums the announced rows — three views dividing the same two numbers
// each in its own way are three different bars on one product. Work that named no
// total gets a striped track running end to end and no number at all, exactly as
// the backup screen already shows a run without an estimate. Internal to the
// Angular view layer on purpose: it is not exported from index.ts, for the reason
// the bar and the footer are not — outside a table it means nothing. The Angular
// port of the Vue reference (vue/src/HilosTableProgress.vue), under the same names
// and words.
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  input,
} from '@angular/core'
import type { HilosTableProgress as HilosTableProgressState } from '@hilos/core'

/** The track of one running job. */
@Component({
  selector: 'hilos-table-progress',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="progress hilos-progress-track"
      role="progressbar"
      [attr.aria-label]="label()"
      aria-valuemin="0"
      aria-valuemax="100"
      [attr.aria-valuenow]="percent()"
    >
      <div
        [class]="
          percent() === null
            ? 'progress-bar progress-bar-striped progress-bar-animated hilos-progress'
            : 'progress-bar hilos-progress'
        "
        [style.--hilos-progress]="percent() ?? 100"
      ></div>
    </div>
  `,
})
export class HilosTableProgress {
  /** The bar to draw, with the fraction already worked out by the core. */
  readonly progress = input.required<HilosTableProgressState>()
  /** Accessible name of the track; the wording belongs to the place that draws it. */
  readonly label = input.required<string>()

  // The percentage the track is filled to, or null when the work named no total —
  // which is the difference between a bar and a striped track, and the difference
  // between reporting a number to assistive tech and reporting none.
  protected readonly percent = computed(() => {
    const fraction = this.progress().fraction

    return fraction === null ? null : Math.round(fraction * 100)
  })
}
