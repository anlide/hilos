import { type HilosPageCrumb } from '../admin/identity/hilosPageIdentity.js'
import { copyToClipboard } from '../dom/clipboard.js'
import { downloadTextFile } from '../dom/downloadTextFile.js'
import { HilosPages } from '../routing/hilosPages.js'
import { createSignal, type ReadonlySignal } from '../state/signal.js'
import { TableViewportController } from '../table/TableViewportController.js'
import {
  filterLicenseEntries,
  type HilosLicenseEntry,
  type HilosLicenseInventory,
  licenseCsvFileName,
  licenseFilterOptions,
  licenseLanguageLabel,
  renderLicenseCsv,
} from './licenseInventory.js'

/** The public License owner's pages, resolved by each application's router. */
export const HILOS_LICENSE_TABS: readonly HilosPageCrumb[] = [
  { page: HilosPages.LICENSE, label: 'License' },
  { page: HilosPages.LICENSE_DEPENDENCIES, label: 'Dependencies' },
]

/** One mount's local window over the immutable build inventory. */
export interface HilosLicenseDependenciesController {
  readonly table: TableViewportController<HilosLicenseEntry>
  readonly visible: ReadonlySignal<readonly HilosLicenseEntry[]>
  readonly entry: ReadonlySignal<HilosLicenseEntry | null>
  readonly copyStatus: ReadonlySignal<string>
  /** Open the current snapshot's entry with this package identity. */
  open(entry: HilosLicenseEntry): void
  close(): void
  /** Replace the snapshot, keeping the current filter conditions. */
  setInventory(inventory: HilosLicenseInventory): void
  copy(): Promise<void>
  download(): void
  /** Release the window and prevent late clipboard results from publishing. */
  dispose(): void
}

/** The generator's package identity, shared by the window and the open dialog. */
function licenseEntryKey(entry: HilosLicenseEntry): string {
  return JSON.stringify([entry.language, entry.name, entry.version])
}

/**
 * Adapt a complete build snapshot to the ordinary table frame and controller.
 * This mount owns all UI state; no connection or server subscription is needed.
 *
 * @param inventory The complete immutable inventory produced by the build.
 */
export function createHilosLicenseDependencies(
  inventory: HilosLicenseInventory,
): HilosLicenseDependenciesController {
  let snapshot = inventory
  let entries = new Map(
    snapshot.entries.map((entry) => [licenseEntryKey(entry), entry]),
  )
  let windowEntries = entries
  const options = createSignal(licenseFilterOptions(snapshot.entries))
  const visible = createSignal<readonly HilosLicenseEntry[]>([])
  const entry = createSignal<HilosLicenseEntry | null>(null)
  const copyStatus = createSignal('')
  let generation = 0
  let disposed = false

  async function copy(): Promise<void> {
    if (disposed) return
    const attempt = ++generation
    copyStatus.set('')
    const copied = await copyToClipboard(renderLicenseCsv(visible.get()))
    if (disposed || attempt !== generation) return
    copyStatus.set(
      copied ? 'Copied' : 'The browser did not allow copying — use Download',
    )
  }

  function download(): void {
    if (disposed) return
    downloadTextFile(
      licenseCsvFileName(snapshot.project),
      renderLicenseCsv(visible.get()),
      'text/csv;charset=utf-8',
    )
  }

  const table = new TableViewportController<HilosLicenseEntry>({
    resolve: (row) => entries.get(row.rowKey) ?? windowEntries.get(row.rowKey)!,
    sendViewport: (descriptor) =>
      refresh(descriptor.filter, descriptor.requestId),
    frame: {
      search: { placeholder: 'Search a package or a license' },
      filters: [
        {
          kind: 'select',
          key: 'license',
          label: 'License',
          anyLabel: 'All licenses',
          options: () =>
            options.get().licenses.map((license) => ({
              value: license,
              label: license,
            })),
        },
        {
          kind: 'select',
          key: 'language',
          label: 'Language',
          anyLabel: 'All languages',
          options: () =>
            options.get().languages.map((language) => ({
              value: language,
              label: licenseLanguageLabel(language),
            })),
        },
      ],
      columns: [
        { key: 'name', label: 'Package', card: 'title' },
        { key: 'language', label: 'Language' },
        { key: 'license', label: 'License' },
      ],
      auxiliaryActions: [
        {
          key: 'copy',
          label: 'Copy the list',
          icon: 'bi-clipboard',
          press: () => void copy(),
        },
        {
          key: 'download',
          label: 'Download as a file',
          icon: 'bi-download',
          press: download,
        },
      ],
      empty: { title: 'No dependencies in this build' },
    },
  })

  function refresh(
    filter: Readonly<Record<string, unknown>>,
    requestId?: string,
  ): void {
    if (disposed) return
    generation++
    copyStatus.set('')
    const admitted = filterLicenseEntries(snapshot.entries, {
      search: typeof filter['search'] === 'string' ? filter['search'] : '',
      license: typeof filter['license'] === 'string' ? filter['license'] : '',
      language:
        typeof filter['language'] === 'string' ? filter['language'] : '',
    })
    visible.set(admitted)
    const rows = admitted.map((entry) => ({
      rowKey: licenseEntryKey(entry),
      slots: {},
    }))
    const limit = Math.max(1, snapshot.entries.length)
    if (!table.loaded.get()) {
      // This local owner knows its opening order: inventory order, with no sorting.
      table.ingestSubscriptionWindow(
        rows,
        admitted.length,
        true,
        null,
        null,
        limit,
        undefined,
        [],
        0,
      )
      return
    }
    table.ingestWindow(
      rows,
      admitted.length,
      true,
      null,
      null,
      limit,
      0,
      requestId,
    )
  }

  refresh(table.filter.get())

  return {
    table,
    visible,
    entry,
    copyStatus,
    open: (value) => {
      if (!disposed) entry.set(entries.get(licenseEntryKey(value)) ?? null)
    },
    close: () => {
      if (!disposed) entry.set(null)
    },
    setInventory: (inventory) => {
      if (disposed) return
      snapshot = inventory
      entries = new Map(
        snapshot.entries.map((entry) => [licenseEntryKey(entry), entry]),
      )
      const held = entry.get()
      entry.set(
        held === null ? null : (entries.get(licenseEntryKey(held)) ?? null),
      )
      options.set(licenseFilterOptions(snapshot.entries))
      // Republish filter views too: each kit must reread the new options through
      // its own signal adapter, while every current condition stays in place.
      // Reset publications still resolve the old window until ingestWindow
      // replaces it synchronously; only then may its entries be released.
      table.setFilters({ ...table.filter.get() })
      windowEntries = entries
    },
    copy,
    download,
    dispose: () => {
      if (disposed) return
      disposed = true
      generation++
      table.dispose()
      entries.clear()
      windowEntries.clear()
      visible.set([])
      entry.set(null)
      copyStatus.set('')
    },
  }
}
