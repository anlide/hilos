import { computed, shallowRef, watch, type InjectionKey, type Ref } from 'vue'
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

/** Internal mount inputs. The table reads its existing geometry registration. */
export interface HilosTableValueProps {
  readonly controller: Pick<
    TableViewportController<unknown>,
    'frame' | 'expandedText' | 'expandText'
  >
  /** Read the existing registration, including at asynchronous boundaries.
   * Return null as soon as the occurrence is outgoing, departed or unavailable.
   */
  readonly readRecord: () => TableGeometryRecord | null
  readonly columnKey: string
  readonly label: string
  readonly heightContent?: HilosTableColumn['heightContent']
  readonly projection: TableProjection
}

/** One wrapper in one projection; registrations never cross projections. */
export interface HilosTableTextContext {
  readonly value: Readonly<HilosTableValueProps>
  readonly policy: () => HilosTableRowHeight
  readonly scope: () => TableTextOverflowScope | null
  readonly regions: Readonly<Ref<number>>
  readonly register: () => () => void
}

export const hilosTableTextKey: InjectionKey<HilosTableTextContext> =
  Symbol('hilos-table-text')

// TODO(i18n): reading labels follow the shared SDK copy catalog when it lands.
export const TABLE_TEXT_COPY = {
  expand: 'Show in full',
  collapse: 'Collapse',
} as const

/** DOM/focus state is private to this mount; logical intent stays in core. */
export function useTableTextReading(
  context: HilosTableTextContext,
  target: Readonly<Ref<HTMLElement | null>>,
  content: Readonly<Ref<HTMLElement | null>>,
  eligible: () => boolean,
) {
  const focused = shallowRef(false)
  const limited = computed(
    () => eligible() && context.policy().mode !== 'content',
  )
  const reading = useTableTextOverflow(
    () => (eligible() ? context.scope() : null),
    target,
    content,
    () => limited.value,
  )
  const { expanded, overflow, contentId } = reading
  const textClass = computed(() =>
    eligible() ? hilosTableTextClass(context.policy()) : 'hilos-table-text',
  )
  const caption = computed(() =>
    expanded.value ? TABLE_TEXT_COPY.collapse : TABLE_TEXT_COPY.expand,
  )
  const accessibleName = computed(
    () => `${caption.value}: ${context.value.label}`,
  )
  const showControl = computed(
    () =>
      limited.value &&
      context.scope() !== null &&
      (overflow.value === true || expanded.value || focused.value),
  )
  watch(
    () => {
      const scope = context.scope()
      return [
        scope?.controller,
        scope?.record,
        scope?.columnKey,
        scope?.projection,
        target.value,
        content.value,
        eligible(),
      ]
    },
    (next, previous) => {
      if (next.some((value, index) => value !== previous[index]))
        focused.value = false
    },
    { flush: 'sync' },
  )
  return {
    expanded,
    contentId,
    limited,
    textClass,
    caption,
    accessibleName,
    showControl,
    focused,
    toggle: () => reading.setExpanded(!expanded.value),
  }
}
