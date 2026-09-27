import {
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type RowEditStep,
} from '../../conflict/rowEdit.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
} from '../../state/signal.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import {
  HILOS_LEGAL_VALUE_COPY,
  type HilosLegalSettingRow,
} from './hilosLegal.js'

/** Modal-owned legal setting draft, shared by the three view adapters. */
export function createHilosLegalSettingEdit(
  controller: TableViewportController<HilosLegalSettingRow>,
) {
  const row = createSignal<HilosLegalSettingRow | null>(null)
  const value = createSignal('')
  const baseline = createSignal(openRowEdit({ value: '' }))
  const state = computedSignal(() => {
    const focused = controller.focusedRow.get()
    return resolveRowEdit(
      focused !== undefined && focused.rowKey === row.get()?.rowKey
        ? { value: focused.value }
        : undefined,
      baseline.get(),
      { value: value.get() },
    )
  })
  const noticeText = computedSignal(() => {
    const live = state.get()
    switch (live.notice?.kind) {
      case 'deleted':
        return 'Deleted elsewhere — your choice stays visible.'
      case 'conflict':
        return `Changed elsewhere to "${HILOS_LEGAL_VALUE_COPY[live.fields.value.incoming] ?? live.fields.value.incoming}".`
      case 'updated':
        return 'Updated just now'
      default:
        return ''
    }
  })
  let stop: (() => void) | null = null

  function apply(step: RowEditStep<{ value: string }>): void {
    baseline.set(step.baseline)
    if (step.take.value !== undefined) value.set(step.take.value)
  }
  function close(): void {
    row.set(null)
    controller.releaseFocus()
  }

  return {
    row: computedSignal(() => row.get()),
    value: computedSignal(() => value.get()),
    state,
    noticeText,
    open(rowKey: string): void {
      const fresh = controller.focusRow(rowKey)
      if (fresh === null) return
      baseline.set(openRowEdit({ value: fresh.value }))
      value.set(fresh.value)
      row.set(fresh)
    },
    setValue(next: string): void {
      value.set(next)
    },
    keepMine(): void {
      baseline.set(keepMineRowEdit(state.get(), baseline.get()))
    },
    takeTheirs(): void {
      apply(takeTheirsRowEdit(state.get(), baseline.get()))
    },
    close,
    start(): void {
      stop?.()
      stop = subscribeSignal(state, (next) => {
        if (row.get() !== null && next.settle !== null) apply(next.settle)
      })
    },
    dispose(): void {
      stop?.()
      stop = null
      close()
    },
  }
}
