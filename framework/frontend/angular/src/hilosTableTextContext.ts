import {
  DestroyRef,
  InjectionToken,
  afterRenderEffect,
  computed,
  inject,
  signal,
  type Signal,
} from '@angular/core'
import {
  hilosTableTextClass,
  type HilosTableColumn,
  type HilosTableRowHeight,
  type TableGeometryRecord,
  type TableProjection,
  type TableViewportController,
} from '@hilos/core/base'
import {
  createTableTextOverflow,
  type TableTextOverflowScope,
} from './tableTextOverflow.js'

/** Internal mount inputs, never part of the project's cell-template context. */
export interface HilosTableValueProps {
  readonly controller: Pick<
    TableViewportController<unknown>,
    'frame' | 'expandedText' | 'expandText'
  >
  readonly readRecord: () => TableGeometryRecord | null
  readonly columnKey: string
  readonly label: string
  readonly heightContent?: HilosTableColumn['heightContent']
  readonly projection: TableProjection
}

/** One value in one projection; the template outlet explicitly provides it. */
export interface HilosTableTextContext {
  readonly value: Signal<HilosTableValueProps>
  readonly policy: () => HilosTableRowHeight
  readonly scope: () => TableTextOverflowScope | null
  readonly regions: Signal<number>
  readonly register: (region: object) => () => void
}

export const HILOS_TABLE_TEXT = new InjectionToken<HilosTableTextContext>(
  'hilos-table-text',
)

// TODO(i18n): reading labels follow the shared SDK copy catalog when it lands.
export const TABLE_TEXT_COPY = {
  expand: 'Show in full',
  collapse: 'Collapse',
} as const

let textSequence = 0

/** Shared presentation for ordinary text and the explicit parts text region. */
export function createTableTextReading(
  context: HilosTableTextContext,
  target: () => HTMLElement | null,
  content: () => HTMLElement | null,
  eligible: () => boolean,
) {
  const id = `hilos-table-text-${++textSequence}`
  const limited = computed(
    () => eligible() && context.policy().mode !== 'content',
  )
  const reading = createTableTextOverflow(
    () => (eligible() ? context.scope() : null),
    target,
    content,
    limited,
  )
  const focusedBinding = signal<object | null>(null)
  const focused = computed(
    () => focusedBinding() !== null && focusedBinding() === reading.binding(),
  )
  const caption = computed(() =>
    reading.expanded() ? TABLE_TEXT_COPY.collapse : TABLE_TEXT_COPY.expand,
  )
  const contentId = computed(() => `${id}-${context.value().projection}`)
  const accessibleName = computed(
    () => `${caption()}: ${context.value().label}`,
  )
  const showControl = computed(
    () =>
      limited() &&
      context.scope() !== null &&
      (reading.overflow() === true || reading.expanded() || focused()),
  )
  const textClass = computed(() =>
    eligible() ? hilosTableTextClass(context.policy()) : 'hilos-table-text',
  )
  afterRenderEffect(() => reading.commit())
  inject(DestroyRef).onDestroy(() => {
    focusedBinding.set(null)
    reading.dispose()
  })
  return {
    ...reading,
    limited,
    textClass,
    caption,
    contentId,
    accessibleName,
    showControl,
    setFocused(value: boolean) {
      focusedBinding.set(
        value && reading.isCurrent() ? reading.binding() : null,
      )
    },
    toggle() {
      reading.setExpanded(!reading.expanded())
    },
  }
}
