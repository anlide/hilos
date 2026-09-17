// HilosTableProgress — the track a running job is drawn as, the one piece of
// markup shared by the bar above a table and the bar under a row. It computes
// NOTHING about the work: the core hands over the fraction (tableProgress.ts) for
// the reason it sums the announced rows — three views dividing the same two numbers
// each in its own way are three different bars on one product. Work that named no
// total gets a striped track running end to end and no number at all, exactly as
// the backup screen already shows a run without an estimate. Internal to the React
// view layer on purpose: it is not exported from index.ts, for the reason the bar
// and the footer are not — outside a table it means nothing. The React port of the
// Vue reference (vue/src/HilosTableProgress.vue), under the same names and words.
import type { CSSProperties } from 'react'
import type { HilosTableProgress as HilosTableProgressState } from '@hilos/core'

/** Props for {@link HilosTableProgress}. */
export interface HilosTableProgressProps {
  /** The bar to draw, with the fraction already worked out by the core. */
  progress: HilosTableProgressState
  /** Accessible name of the track; the wording belongs to the place that draws it. */
  label: string
  /**
   * Classes the place drawing the track adds to it — the room of live messages lays
   * it along the bottom edge of its line.
   */
  className?: string
}

/**
 * The track of one running job.
 *
 * @param props The bar to draw, the accessible name of its track, and the classes its place adds.
 */
export function HilosTableProgress({
  progress,
  label,
  className,
}: HilosTableProgressProps) {
  // The percentage the track is filled to, or null when the work named no total —
  // which is the difference between a bar and a striped track, and the difference
  // between reporting a number to assistive tech and reporting none.
  const percent =
    progress.fraction === null ? null : Math.round(progress.fraction * 100)

  return (
    <div
      className={
        className === undefined
          ? 'progress hilos-progress-track'
          : `progress hilos-progress-track ${className}`
      }
      role="progressbar"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={percent ?? undefined}
    >
      <div
        className={
          percent === null
            ? 'progress-bar progress-bar-striped progress-bar-animated hilos-progress'
            : 'progress-bar hilos-progress'
        }
        style={{ '--hilos-progress': percent ?? 100 } as CSSProperties}
      />
    </div>
  )
}
