import {
  createContext,
  useCallback,
  useLayoutEffect,
  useRef,
  useState,
  type RefObject,
} from 'react'
import {
  hilosTableTextClass,
  type HilosTableColumn,
  type HilosTableRowHeight,
  type TableGeometryRecord,
  type TableProjection,
  type TableViewportController,
} from '@hilos/core/base'
import {
  useTableTextOverflow,
  type TableTextOverflowScope,
} from './useTableTextOverflow.js'

/** Internal wrapper inputs; read the table's existing geometry registration. */
export interface HilosTableValueProps {
  readonly controller: Pick<
    TableViewportController<unknown>,
    'frame' | 'expandedText' | 'expandText'
  >
  /** Null immediately when the captured occurrence becomes unavailable. */
  readonly readRecord: () => TableGeometryRecord | null
  readonly columnKey: string
  readonly label: string
  readonly heightContent?: HilosTableColumn['heightContent']
  readonly projection: TableProjection
}

export interface TableTextContext {
  readonly value: HilosTableValueProps
  readonly policy: HilosTableRowHeight
  readonly scope: () => TableTextOverflowScope | null
  readonly regions: number
  /** Read committed registrations before the state snapshot is published. */
  readonly regionCount: () => number
  readonly register: () => () => void
}

// Internal to the table value and its parts region, absent from public barrels.
export const HilosTableTextContext = createContext<TableTextContext | null>(
  null,
)

// TODO(i18n): reading labels follow the shared SDK copy catalog when it lands.
export const TABLE_TEXT_COPY = {
  expand: 'Show in full',
  collapse: 'Collapse',
} as const

/** Internal provider value for one mounted table value. */
export function useTableTextContext(
  value: HilosTableValueProps,
): TableTextContext {
  const [regions, setRegions] = useState(0)
  const committedRegions = useRef(0)
  const regionCount = useCallback(() => committedRegions.current, [])
  const register = useCallback(() => {
    setRegions(++committedRegions.current)
    let active = true
    return () => {
      if (!active) return
      active = false
      setRegions(--committedRegions.current)
    }
  }, [])
  return {
    value,
    policy: value.controller.frame.rowHeight,
    scope: () => {
      const record = value.readRecord()
      return record
        ? {
            controller: value.controller,
            record,
            columnKey: value.columnKey,
            projection: value.projection,
          }
        : null
    },
    regions,
    regionCount,
    register,
  }
}

/** Parts regions register only after commit, including StrictMode. */
export function useTableTextRegion(context: TableTextContext): void {
  useLayoutEffect(() => context.register(), [context.register])
}

/**
 * Shared reading preparation for the text/parts components.
 * Focus belongs to this DOM binding; occurrence/column intent belongs to core.
 */
export function useTableTextReading(
  context: TableTextContext,
  target: RefObject<HTMLElement | null>,
  content: RefObject<HTMLElement | null>,
  eligible: boolean,
) {
  const limited = eligible && context.policy.mode !== 'content'
  const reading = useTableTextOverflow(
    () => (eligible ? context.scope() : null),
    target,
    content,
    () => limited,
  )
  const [focus, setFocus] = useState<{
    binding: object | null
    value: boolean
  }>({
    binding: null,
    value: false,
  })
  const focused = focus.binding === reading.binding && focus.value
  const caption = reading.expanded
    ? TABLE_TEXT_COPY.collapse
    : TABLE_TEXT_COPY.expand
  return {
    ...reading,
    limited,
    textClass: eligible
      ? hilosTableTextClass(context.policy)
      : 'hilos-table-text',
    caption,
    accessibleName: `${caption}: ${context.value.label}`,
    showControl:
      limited &&
      context.scope() !== null &&
      (reading.overflow === true || reading.expanded || focused),
    focused,
    setFocused(value: boolean) {
      if (reading.isCurrent()) setFocus({ binding: reading.binding, value })
    },
    toggle: () => reading.setExpanded(!reading.expanded),
  }
}
