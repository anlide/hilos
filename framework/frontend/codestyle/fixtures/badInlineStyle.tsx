// Deliberately broken sample: the React spelling of an inline style, the
// identifier that hides the names it sets, a cast that must not launder the
// names under it, and all three imperative forms.
// STYLE-INLINE must report one line per site.
import type { CSSProperties } from 'react'

/** A style hoisted into a constant, so the JSX attribute names nothing. */
const MAX_WIDTH = { maxWidth: '24rem' }

/**
 * The JSX forms: names written out, names one indirection away, and names
 * written out under a cast, which judges them as if it were not there.
 */
export function Card(): JSX.Element {
  return (
    <section className="card">
      <div style={{ maxWidth: '18rem', overflowY: 'auto' }} />
      <div style={MAX_WIDTH} />
      <div style={{ maxWidth: '18rem' } as CSSProperties} />
      <div style={MAX_WIDTH as CSSProperties} />
    </section>
  )
}

/** The imperative forms, each of them a write onto an element's own style. */
export function paint(element: HTMLElement, name: string): void {
  element.style.color = 'red'
  element.style.setProperty('width', '10px')
  element.style.setProperty(name, '10px')
  element.style.cssText = 'color: red'
}
