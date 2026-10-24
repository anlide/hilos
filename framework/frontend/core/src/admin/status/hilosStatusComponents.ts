import { z } from 'zod'
import { HilosPages } from '../../routing/hilosPages.js'
import { subscribeSignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import {
  type HilosStatusContext,
  statusComponentStateSchema,
  HILOS_STATUS_COPY,
} from './hilosStatus.js'

export const HILOS_STATUS_COMPONENTS_TABLE = 'hilosStatusComponents'
export const HILOS_STATUS_COMPONENT_SLOT = 'component'
/** Viewport filter key; independent of the row's state field. */
export const STATUS_COMPONENT_FILTER_STATE = 'state'
/** Row payload key of the component's stable identity. */
const STATUS_COMPONENT_KEY_FIELD = 'componentKey'
/** Row payload key of the component's display name. */
export const STATUS_COMPONENT_NAME_FIELD = 'name'
/** Row payload key of the optional component description. */
export const STATUS_COMPONENT_DESCRIPTION_FIELD = 'description'
/** Row payload key of the declared agent type. */
export const STATUS_COMPONENT_AGENT_TYPE_FIELD = 'agentType'
/** Row payload key of the current authoritative state. */
export const STATUS_COMPONENT_STATE_FIELD = 'state'

export const statusComponentSchema = z
  .strictObject({
    [STATUS_COMPONENT_KEY_FIELD]: z.string(),
    [STATUS_COMPONENT_NAME_FIELD]: z.string(),
    [STATUS_COMPONENT_DESCRIPTION_FIELD]: z.string().nullable(),
    [STATUS_COMPONENT_AGENT_TYPE_FIELD]: z.string(),
    [STATUS_COMPONENT_STATE_FIELD]: statusComponentStateSchema,
  })
  .readonly()
export type HilosStatusComponentRow = z.infer<typeof statusComponentSchema>

/** Components needs the same project binding as Overview. */
export type HilosStatusComponentsContext = HilosStatusContext

export const HILOS_STATUS_COMPONENTS_COPY = {
  component: 'Component',
  agent: 'Agent',
  now: 'Now',
  search: 'Search by name, description or agent…',
  empty: 'No components declared',
  states: { ...HILOS_STATUS_COPY, unknown: 'Unknown' },
} as const

/** Project source freshness without changing the authoritative inline slot. */
export function resolveHilosStatusComponentRow(
  row: TableRow,
): HilosStatusComponentRow {
  const component = statusComponentSchema.parse(
    row.slots[HILOS_STATUS_COMPONENT_SLOT],
  )
  if (String(row.rowKey) !== component.componentKey)
    throw new Error('Status component row key does not match its slot.')
  if (row.staleSources?.includes(HILOS_STATUS_COMPONENT_SLOT))
    return Object.freeze({ ...component, state: 'unknown' })
  return component
}

export interface HilosStatusComponentsTable {
  readonly controller: TableViewportController<HilosStatusComponentRow>
  start(): void
  dispose(): void
}

/** Standard server viewport: no sort capability, CRUD or client-side filtering. */
export function createHilosStatusComponentsTable(
  context: HilosStatusComponentsContext,
): HilosStatusComponentsTable {
  const columns: readonly HilosTableColumnOf<HilosStatusComponentRow>[] = [
    {
      key: STATUS_COMPONENT_NAME_FIELD,
      label: HILOS_STATUS_COMPONENTS_COPY.component,
      card: 'title',
      reads: [STATUS_COMPONENT_DESCRIPTION_FIELD],
    },
    {
      key: STATUS_COMPONENT_AGENT_TYPE_FIELD,
      label: HILOS_STATUS_COMPONENTS_COPY.agent,
    },
    {
      key: STATUS_COMPONENT_STATE_FIELD,
      label: HILOS_STATUS_COMPONENTS_COPY.now,
      card: 'badge',
    },
  ]
  const page = HilosPages.STATUS_COMPONENTS
  const controller = new TableViewportController<HilosStatusComponentRow>({
    resolve: resolveHilosStatusComponentRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        page,
        HILOS_STATUS_COMPONENTS_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        page,
        HILOS_STATUS_COMPONENTS_TABLE,
        rendered,
      ),
    frame: {
      columns,
      search: { placeholder: HILOS_STATUS_COMPONENTS_COPY.search },
      filters: [
        {
          kind: 'select',
          key: STATUS_COMPONENT_FILTER_STATE,
          label: HILOS_STATUS_COMPONENTS_COPY.now,
          options: () =>
            statusComponentStateSchema.options.map((value) => ({
              value,
              label: HILOS_STATUS_COMPONENTS_COPY.states[value],
            })),
        },
      ],
      empty: { title: HILOS_STATUS_COMPONENTS_COPY.empty },
    },
  })
  let off: (() => void) | undefined
  let offScope: (() => void) | undefined
  let disposed = false
  const dispose = (): void => {
    if (disposed) return
    disposed = true
    off?.()
    off = undefined
    offScope?.()
    offScope = undefined
    controller.dispose()
  }
  return {
    controller,
    start() {
      if (disposed || off !== undefined) return
      const scope = context.scopes.page()
      off = bindTableViewport(
        context.connection,
        context.scopes,
        { page, tableKey: HILOS_STATUS_COMPONENTS_TABLE },
        controller,
      )
      offScope = subscribeSignal(
        { get: () => context.scopes.page() },
        (current) => {
          if (current !== scope) dispose()
        },
      )
    },
    dispose,
  }
}
