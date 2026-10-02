// HilosHideable — a value a viewer of the admin view mode may be sent hidden
// (Hideable<T>, @hilos/core): the hidden one is drawn as HilosHiddenMark, any
// other goes to the children render function narrowed to T, so it draws the
// value the way the screen always has and never meets the mark. With no
// children the value is printed as text. The page reads the field as hideable
// and hands it here as it is; nothing on the screen tests for the mark itself.
import type { ReactNode } from 'react'
import { type Hideable, isHiddenValue } from '@hilos/core'

import { HilosHiddenMark } from './HilosHiddenMark.js'

/** Props for {@link HilosHideable}. */
export interface HilosHideableProps<T> {
  /** The value, or the hidden mark the server sent in its place. */
  value: Hideable<T>
  /** The value when it is not hidden, narrowed to its own type. */
  children?: (value: T) => ReactNode
}

/**
 * Draw a value a viewer of the admin view mode may be sent hidden: the mark in
 * its place when it is hidden, otherwise the children render function (or the
 * value as text, with no children).
 *
 * @param props The hideable value and its optional render function.
 */
export function HilosHideable<T>({ value, children }: HilosHideableProps<T>) {
  if (isHiddenValue(value)) {
    return <HilosHiddenMark />
  }

  if (children) {
    return <>{children(value)}</>
  }

  // Printed as text the way Vue's interpolation prints it: nothing for an absent value.
  return <>{value === null || value === undefined ? '' : String(value)}</>
}
