import { useContext, useRef } from 'react'
import {
  hilosTableTextClass,
  isHiddenValue,
  type Hideable,
} from '@hilos/core/base'
import { HilosHideable } from './HilosHideable.js'
import { HilosTableTextControl } from './HilosTableValue.js'
import {
  HilosTableTextContext,
  useTableTextReading,
  useTableTextRegion,
} from './hilosTableText.js'

/** Props for the single main text region of a parts cell renderer. */
export interface HilosTableTextProps {
  readonly value: Hideable<string>
}

/** Render the main text inside an existing heightContent: parts renderer. */
export function HilosTableText({ value }: HilosTableTextProps) {
  const context = useContext(HilosTableTextContext)
  if (!context || context.value.heightContent !== 'parts')
    throw new TypeError(
      'HilosTableText requires a heightContent: parts context.',
    )
  useTableTextRegion(context)
  const target = useRef<HTMLDivElement>(null)
  const content = useRef<HTMLDivElement>(null)
  const hidden = isHiddenValue(value)
  const reading = useTableTextReading(
    context,
    target,
    content,
    !hidden && (context.policy.mode === 'content' || context.regions === 1),
  )

  if (hidden)
    return (
      <div className="hilos-stack">
        <div
          className={`${hilosTableTextClass(context.policy)} is-open`}
          aria-hidden="true"
          inert
        />
        <div>
          <HilosHideable value={value} />
        </div>
      </div>
    )
  return (
    <div>
      <div
        id={reading.contentId}
        ref={target}
        className={`${reading.textClass}${reading.expanded ? ' is-open' : ''}`}
      >
        <div ref={content}>
          <HilosHideable value={value} />
        </div>
      </div>
      <HilosTableTextControl reading={reading} />
    </div>
  )
}
