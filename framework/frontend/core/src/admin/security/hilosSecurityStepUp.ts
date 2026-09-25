import { type ActionHandle } from '../../connection/actionLifecycle.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readBoolean, readString } from '../../state/fieldReaders.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'
import { type HilosTwoFactorContext } from './hilosSecurityTwoFactor.js'

/** One declared operation on the step-up administration table. */
export interface HilosStepUpOperationRow {
  readonly operationKey: string
  readonly label: string
  readonly owner: string
  readonly enabled: boolean
}

const STEP_UP_TABLE = 'hilosSecurityStepUp'
const STEP_UP_SLOT = 'operation'
const STEP_UP_SET_ACTION = 'security_step_up_operation_set'

/** Wire keys of the operation row slot. */
export const HilosStepUpOperationRowKey = {
  operationKey: 'operationKey',
  label: 'label',
  owner: 'owner',
  enabled: 'enabled',
} as const

/** Shared copy for the protected-operation section. */
export const HILOS_STEP_UP_ADMIN_COPY = {
  heading: 'Operations that ask for confirmation',
  lead: 'Asked right before the operation, even on a trusted device. The project declares the list; the framework gives the mechanism and three operations of its own.',
  framework: 'Framework',
  project: 'Project',
} as const

/** Resolve the inline operation slot into its view-model. */
export function resolveHilosStepUpOperationRow(
  row: TableRow,
): HilosStepUpOperationRow {
  const slot = row.slots[STEP_UP_SLOT]
  const record =
    typeof slot === 'object' && slot !== null && !Array.isArray(slot)
      ? (slot as Record<string, unknown>)
      : {}

  return {
    operationKey:
      readString(record, HilosStepUpOperationRowKey.operationKey) ||
      String(row.rowKey),
    label: readString(record, HilosStepUpOperationRowKey.label),
    owner: readString(record, HilosStepUpOperationRowKey.owner),
    enabled: readBoolean(record, HilosStepUpOperationRowKey.enabled),
  }
}

/** The operation table handle used by all three views. */
export interface HilosStepUpTable {
  readonly controller: TableViewportController<HilosStepUpOperationRow>
  start(): void
  dispose(): void
}

const STEP_UP_COLUMNS: HilosTableColumnOf<HilosStepUpOperationRow>[] = [
  {
    key: HilosStepUpOperationRowKey.operationKey,
    label: 'Operation',
    reads: [HilosStepUpOperationRowKey.label],
  },
  { key: HilosStepUpOperationRowKey.owner, label: 'Owner' },
  { key: HilosStepUpOperationRowKey.enabled, label: 'Enabled' },
]

const STEP_UP_FRAME: HilosTableFrame = {
  title: HILOS_STEP_UP_ADMIN_COPY.heading,
  subtitle: HILOS_STEP_UP_ADMIN_COPY.lead,
  columns: STEP_UP_COLUMNS,
  empty: { title: 'No protected operations declared.' },
}

/** Create and bind the server-windowed protected-operation table. */
export function createHilosSecurityStepUpTable(
  context: HilosTwoFactorContext,
): HilosStepUpTable {
  const controller = new TableViewportController<HilosStepUpOperationRow>({
    resolve: resolveHilosStepUpOperationRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.SECURITY_2FA,
        STEP_UP_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.SECURITY_2FA,
        STEP_UP_TABLE,
        rendered,
      ),
    frame: STEP_UP_FRAME,
  })
  let teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          { page: HilosPages.SECURITY_2FA, tableKey: STEP_UP_TABLE },
          controller,
        ),
      ]
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/** Tracked mutation actions for one operation switch. */
export interface HilosSecurityStepUpActions {
  sendOperationSet(operationKey: string, enabled: boolean): ActionHandle
}

/** Create operation-switch actions over the shared lifecycle. */
export function createHilosSecurityStepUpActions(
  context: Pick<HilosTwoFactorContext, 'actions'>,
): HilosSecurityStepUpActions {
  return {
    sendOperationSet(operationKey, enabled) {
      return context.actions.dispatch(STEP_UP_SET_ACTION, {
        operationKey,
        enabled,
      })
    },
  }
}
