// The look-alikes: the one legal channel in JSX and in the imperative form, the
// same channel under the cast React's types ask for, a read of a declaration,
// and an attribute that only reads like a style.
// STYLE-INLINE must stay silent on every one of them.
import type { CSSProperties } from 'react'

/** The JSX form of the legal channel: every name it sets is a custom property. */
export function Bar({ percent }: { percent: number }): JSX.Element {
  return (
    <div className="progress" style={{ '--hilos-progress': percent }}>
      <span data-style="compact" />
    </div>
  )
}

/**
 * The same channel under a cast: React's CSSProperties types no custom
 * property, and the literal under `as` or `satisfies` is still read where it
 * is written.
 */
export function Layers({ depth }: { depth: number }): JSX.Element {
  return (
    <div style={{ '--hilos-modal-depth': depth } as CSSProperties}>
      <span style={{ '--hilos-gap': depth } satisfies CSSProperties} />
    </div>
  )
}

/** The imperative form of the same channel, and a read, which is not a write. */
export function widthOf(element: HTMLElement, percent: number): string {
  element.style.setProperty('--hilos-progress', String(percent))

  return element.style.width
}
