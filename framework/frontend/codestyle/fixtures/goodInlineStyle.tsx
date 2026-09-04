// The look-alikes: the one legal channel in JSX and in the imperative form, a
// read of a declaration, and an attribute that only reads like a style.
// STYLE-INLINE must stay silent on every one of them.

/** The JSX form of the legal channel: every name it sets is a custom property. */
export function Bar({ percent }: { percent: number }): JSX.Element {
  return (
    <div className="progress" style={{ '--hilos-progress': percent }}>
      <span data-style="compact" />
    </div>
  )
}

/** The imperative form of the same channel, and a read, which is not a write. */
export function widthOf(element: HTMLElement, percent: number): string {
  element.style.setProperty('--hilos-progress', String(percent))

  return element.style.width
}
