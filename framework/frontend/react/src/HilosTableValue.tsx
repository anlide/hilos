/// <reference types="vite/client" />
import { useLayoutEffect, useRef, useState, type ReactNode } from 'react'
import {
  HilosTableTextContext,
  TABLE_TEXT_COPY,
  useTableTextContext,
  useTableTextReading,
  type HilosTableValueProps,
} from './hilosTableText.js'

// Inspect actual descendants, including the output of consumer components.
const INLINE_TEXT = new Set(
  'SPAN B STRONG I EM U S SMALL MARK SUB SUP BR WBR CODE KBD SAMP VAR ABBR BDI BDO DEL INS Q CITE TIME RUBY RP RT'.split(
    ' ',
  ),
)

function ordinaryText(node: HTMLElement): boolean {
  return [...node.querySelectorAll('*')].every((element) => {
    if (
      !INLINE_TEXT.has(element.tagName) ||
      element.hasAttribute('tabindex') ||
      element.hasAttribute('contenteditable') ||
      element.hasAttribute('role') ||
      element.hasAttribute('onclick') ||
      element.matches('.badge, [data-id="hilos-hidden"]')
    )
      return false
    return !/^(block|flow-root|flex|grid|table|list-item|inline-flex|inline-grid|inline-block)/.test(
      getComputedStyle(element).display,
    )
  })
}

/** Queued observer deliveries retain only a ticket emptied at cleanup. */
function inspectionCallback(ticket: { inspect: (() => void) | null }) {
  return () => ticket.inspect?.()
}

/** Internal renderer wrapper; the controller owns the text expansion intent. */
export function HilosTableValue({
  children,
  ...value
}: HilosTableValueProps & { readonly children?: ReactNode }) {
  const context = useTableTextContext(value)
  const target = useRef<HTMLDivElement>(null)
  const content = useRef<HTMLDivElement>(null)
  const text = value.heightContent === 'text'
  const [verdict, setVerdict] = useState<{
    children: ReactNode
    safe: boolean
  } | null>(null)
  const diagnosedUnsafe = useRef(false)
  // A new renderer result wraps immediately, but only its DOM verdict permits a cap.
  const safe =
    text && verdict && verdict.children === children ? verdict.safe : null
  const reading = useTableTextReading(
    context,
    target,
    content,
    // Rechecking a new renderer result must not destroy the focused control of
    // a previously safe value. Only the visual cap waits for its new verdict.
    text && (safe === true || (safe === null && verdict?.safe === true)),
  )

  useLayoutEffect(() => {
    if (!text) {
      diagnosedUnsafe.current = false
      setVerdict(null)
      return
    }
    const node = content.current
    if (!node) return
    const ticket = {
      inspect: (() => {
        if (content.current !== node) return
        const safe = ordinaryText(node)
        setVerdict((previous) =>
          previous && previous.children === children && previous.safe === safe
            ? previous
            : { children, safe },
        )
        if (!safe && !diagnosedUnsafe.current && import.meta.env.DEV)
          console.warn(
            `HilosTableValue: unsafe text slot for ${value.columnKey}; use heightContent: 'parts' and HilosTableText.`,
          )
        diagnosedUnsafe.current = !safe
      }) as (() => void) | null,
    }
    const inspect = inspectionCallback(ticket)
    const mutations =
      typeof MutationObserver === 'undefined'
        ? null
        : new MutationObserver(inspect)
    const resize =
      typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(inspect)
    mutations?.observe(node, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
    })
    // An ancestor's classes can change a descendant's computed display.
    for (let parent = node.parentElement; parent; parent = parent.parentElement)
      mutations?.observe(parent, {
        attributes: true,
        attributeFilter: ['class', 'style', 'hidden'],
      })
    resize?.observe(node)
    window.addEventListener('resize', inspect)
    inspect()
    return () => {
      ticket.inspect = null
      mutations?.disconnect()
      resize?.disconnect()
      window.removeEventListener('resize', inspect)
    }
  }, [text, children, value.columnKey])

  useLayoutEffect(() => {
    // Child layout effects have registered the committed tree. The regions state
    // snapshot can still be zero here, including during StrictMode replay.
    if (
      value.heightContent === 'parts' &&
      context.policy.mode !== 'content' &&
      context.regionCount() !== 1
    )
      throw new TypeError(
        'HilosTableValue: limited parts requires exactly one HilosTableText.',
      )
  })

  return (
    <HilosTableTextContext.Provider value={context}>
      {text ? (
        <div>
          <div
            id={reading.contentId}
            ref={target}
            className={
              safe === false
                ? undefined
                : safe === null
                  ? 'hilos-table-text'
                  : `${reading.textClass}${reading.expanded ? ' is-open' : ''}`
            }
          >
            <div ref={content}>{children}</div>
          </div>
          <HilosTableTextControl reading={reading} />
        </div>
      ) : (
        children
      )}
    </HilosTableTextContext.Provider>
  )
}

/** Shared native control and its data-free room holder; internal to the SDK. */
export function HilosTableTextControl({
  reading,
}: {
  readonly reading: ReturnType<typeof useTableTextReading>
}) {
  if (!reading.limited) return null
  return (
    <div className="hilos-stack">
      <span className="invisible" aria-hidden="true" inert>
        <span className="btn btn-link btn-sm p-0">
          {TABLE_TEXT_COPY.expand}
        </span>
      </span>
      <div>
        {reading.showControl ? (
          <button
            type="button"
            className="btn btn-link btn-sm p-0"
            data-id="hilos-table-text-toggle"
            aria-label={reading.accessibleName}
            aria-expanded={reading.expanded}
            aria-controls={reading.contentId}
            onClick={(event) => {
              event.stopPropagation()
              reading.toggle()
            }}
            onKeyDown={(event) => {
              if (event.key === 'Enter' || event.key === ' ')
                event.stopPropagation()
            }}
            onFocus={() => reading.setFocused(true)}
            onBlur={() => reading.setFocused(false)}
          >
            {reading.caption}
          </button>
        ) : null}
      </div>
    </div>
  )
}
