// Deliberately broken text built from Date getters in JSX. The children of one
// element are one text: a bare getter, a component under an alias, and text
// already built inside a child all report once, at the element. An attribute
// value is not a child.

/** A bare getter printed as a child. */
export function Year({ at }: { at: Date }) {
  return <span>{at.getFullYear()}</span>
}

/** A component under an alias, shifted, printed as a child. */
export function Month({ at }: { at: Date }) {
  const month = at.getMonth() + 1
  return <span>{month}</span>
}

/** Text built inside a child belongs to the element. */
export function Day({ at }: { at: Date }) {
  return <time>{`${at.getDate()}.${at.getMonth() + 1}`}</time>
}

/** Two children of one element over two lines: one site, at the element. */
export function Clock({ at }: { at: Date }) {
  return (
    <time data-hour={at.getHours()}>
      {at.getHours()}:{at.getMinutes()}
    </time>
  )
}

/** Two elements, each its own text, on one line inside a fragment. */
export function Stamp({ at }: { at: Date }) {
  return (
    <>
      <b>{at.getHours()}</b> <i>{at.getMinutes()}</i>
    </>
  )
}
