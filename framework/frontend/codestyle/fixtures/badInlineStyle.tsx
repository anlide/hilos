// Deliberately broken sample: the React spelling of an inline style, the
// identifier that hides the names it sets, and all three imperative forms.
// STYLE-INLINE must report one line per site.

/** A style hoisted into a constant, so the JSX attribute names nothing. */
const MAX_WIDTH = { maxWidth: '24rem' }

/** The JSX forms: names written out, and names one indirection away. */
export function Card(): JSX.Element {
  return (
    <section className="card">
      <div style={{ maxWidth: '18rem', overflowY: 'auto' }} />
      <div style={MAX_WIDTH} />
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
