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
import { HILOS_SKELETON_LINES } from '@hilos/core'

/** Props for {@link HilosSkeleton}. */
export interface HilosSkeletonProps {
  /** Bar widths in Bootstrap columns (1..12); the core default when omitted. */
  lines?: number[]
  /** What a screen reader announces for the block; silent when omitted. */
  label?: string
}

/**
 * Render the placeholder bars of a data block that has not arrived.
 *
 * @param props The bar widths and the optional announcement.
 */
export function HilosSkeleton({ lines, label }: HilosSkeletonProps) {
  const bars = lines ?? HILOS_SKELETON_LINES

  return (
    <div className="placeholder-glow" data-id="hilos-skeleton">
      {label ? (
        <span className="visually-hidden" role="status">
          {label}
        </span>
      ) : null}
      {bars.map((width, index) => (
        <span
          key={index}
          className={`placeholder col-${width} d-block rounded${index < bars.length - 1 ? ' mb-2' : ''}`}
          aria-hidden="true"
        />
      ))}
    </div>
  )
}
