// Deliberately broken Date prints in JSX: a child, an attribute, and two on
// one line.

/** A moment printed as a child. */
export function Stamp({ at }: { at: Date }) {
  return <time>{at.toLocaleString()}</time>
}

/** An attribute and a child on one line. */
export function Day({ at }: { at: Date }) {
  return <time title={at.toUTCString()}>{at.toLocaleDateString()}</time>
}
