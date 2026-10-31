// Legal administration data and table handles (HIL-941).
import {
  LEGAL_ACCEPTANCES_SIGNAL,
  legalAcceptanceFiltersSchema,
  type HilosLegalAcceptanceFilters,
} from '../../protocol/legalAcceptancesSignals.js'
import { z } from 'zod'
import { type ActionHandle } from '../../connection/actionLifecycle.js'
import { formatMoment } from '../../format/moment.js'
import {
  legalChangeSchema,
  legalClauseSchema,
  legalRevisionSchema,
  type HilosLegalContext,
  type HilosLegalRevision,
} from '../../legal/legalAgreements.js'
import { hilosLegalDocumentLabel } from '../../legal/legalDocumentLabel.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { hilosUsersPath } from '../users/hilosUsers.js'
import {
  readBoolean,
  readHideableString,
  readHideableStringOrNull,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { type Hideable, isHiddenValue } from '../../state/hiddenValue.js'
import {
  createSignal,
  computedSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

export {
  LEGAL_ACCEPTANCES_SIGNAL,
  legalAcceptanceFiltersSchema,
  LEGAL_ACCEPTANCES_SIGNAL_SCHEMAS,
  type HilosLegalAcceptanceFilters,
} from '../../protocol/legalAcceptancesSignals.js'

export { hilosLegalDocumentLabel } from '../../legal/legalDocumentLabel.js'

export const LEGAL_CATALOG_REFUSAL_SECTION = 'legalCatalogRefusal'
export const LEGAL_DOCUMENT_SECTION = 'legalDocument'
export const LEGAL_REVISION_SECTION = 'legalRevision'

export const LEGAL_SETTING_SET = 'legal_setting_set'
export const LEGAL_DOCUMENT_FILTER = 'document'
export const LEGAL_REVISION_FILTER = 'revision'

export const HilosLegalTableKey = {
  documents: 'hilosLegalDocuments',
  checks: 'hilosLegalChecks',
  documentSet: 'hilosLegalDocumentSet',
  documentDeviations: 'hilosLegalDocumentDeviations',
  revisions: 'hilosLegalRevisions',
  acceptances: 'hilosLegalAcceptances',
  settings: 'hilosLegalSettings',
} as const

/** Payload keys shared by the legal table row shapes. */
export const HilosLegalRowKey = {
  rowKey: 'rowKey',
  documentLabel: 'documentLabel',
  publishedOn: 'publishedOn',
  effectiveOn: 'effectiveOn',
  significance: 'significance',
  setVersion: 'setVersion',
  clauseKey: 'clauseKey',
  statement: 'statement',
  standardStatement: 'standardStatement',
  text: 'text',
  direction: 'direction',
  position: 'position',
  declared: 'declared',
  revision: 'revision',
  covered: 'covered',
  window: 'window',
  lapsed: 'lapsed',
  ok: 'ok',
  items: 'items',
  current: 'current',
  origin: 'origin',
  heldCount: 'heldCount',
  acceptedCount: 'acceptedCount',
  userId: 'userId',
  name: 'name',
  email: 'email',
  document: 'document',
  revisionId: 'revisionId',
  acceptedAt: 'acceptedAt',
  value: 'value',
  defaultValue: 'defaultValue',
} as const

const LegalCheckItemRowKey = {
  people: 'people',
  setVersion: 'setVersion',
  documentSetVersion: 'documentSetVersion',
  significance: 'significance',
  deviations: 'deviations',
} as const

export const HilosLegalSettingKey = {
  consentForm: 'legal.consent_form',
  refusalAfterDeadline: 'legal.refusal_after_deadline',
} as const
export const HILOS_LEGAL_CONSENT_VALUES = ['checkbox', 'line'] as const
export const HILOS_LEGAL_REFUSAL_VALUES = ['freeze', 'remind'] as const
export const HILOS_LEGAL_SETTING_COPY: Readonly<
  Partial<
    Record<string, { label: string; hint: string; values: readonly string[] }>
  >
> = {
  [HilosLegalSettingKey.consentForm]: {
    label: 'Consent at registration',
    hint: 'How a person agrees when creating an account.',
    values: HILOS_LEGAL_CONSENT_VALUES,
  },
  [HilosLegalSettingKey.refusalAfterDeadline]: {
    label: 'Refusal after the deadline',
    hint: 'What happens when the acceptance window ends.',
    values: HILOS_LEGAL_REFUSAL_VALUES,
  },
}
export const HILOS_LEGAL_VALUE_COPY: Readonly<Partial<Record<string, string>>> =
  {
    checkbox: 'Checkbox',
    line: 'Line below the button',
    freeze: 'Freeze access',
    remind: 'Keep reminding',
  }

/**
 * The words of the count of people past a deadline on the legal root: `remind` —
 * the setting keeps reminding them, `freeze` — it freezes them.
 */
export const HILOS_LEGAL_LAPSED_COPY = {
  remind: 'Past deadline',
  freeze: 'Frozen',
} as const

/**
 * The label of the count of people past a deadline, chosen by the value of
 * `legal.refusal_after_deadline`: "Past deadline" under `remind` and while the
 * value is hidden from a viewer of the admin view mode — the one wording true
 * whatever the value is — and "Frozen" otherwise, an unread value included. One
 * label for the three frontends.
 *
 * @param refusal The setting's value, hidden, or undefined before its row arrives.
 */
export function hilosLegalLapsedLabel(
  refusal: Hideable<string> | undefined,
): string {
  return refusal === 'remind' || isHiddenValue(refusal)
    ? HILOS_LEGAL_LAPSED_COPY.remind
    : HILOS_LEGAL_LAPSED_COPY.freeze
}

/** Static illustrations of the two choices; they perform no account action. */
export const HILOS_LEGAL_SETTING_PREVIEWS = [
  {
    key: 'checkbox',
    title: 'Checkbox',
    text: 'I have read the differences and accept the terms.',
    hint: 'A separate acknowledgement before creating the account.',
  },
  {
    key: 'line',
    title: 'Line below the button',
    text: 'By creating an account, you accept the terms and privacy policy.',
    hint: 'Creating the account also confirms agreement.',
  },
  {
    key: 'freeze',
    title: 'Freeze access',
    text: 'Your account is frozen — accept the new terms to continue.',
    hint: 'Sign-in and personal data access remain available; product access waits for acceptance.',
  },
  {
    key: 'remind',
    title: 'Keep reminding',
    text: 'The terms have changed — read the differences.',
    hint: 'The product stays available and the reminder remains.',
  },
] as const

export const legalStandardSetSchema = z.looseObject({
  version: z.number().int(),
  publishedOn: z.string(),
  significance: z.enum(['substantial', 'editorial']),
  clauses: z.array(
    z.looseObject({ clauseKey: z.string(), statement: z.string() }),
  ),
})
export const legalDeviationSchema = z.looseObject({
  clauseKey: z.string(),
  standardStatement: z.string(),
  statement: z.string(),
  text: z.string(),
  direction: z.enum(['stricter', 'looser']),
})
export const legalCatalogRefusalSchema = z.string().nullable()
export const legalAdminDocumentSchema = z.looseObject({
  document: z.string(),
  declared: z.boolean(),
  covered: z.number().int().nonnegative(),
  window: z.number().int().nonnegative(),
  lapsed: z.number().int().nonnegative(),
  standardSetCatalogClass: z.string(),
  projectCatalogClass: z.string().nullable(),
  revision: legalRevisionSchema.nullable(),
  set: legalStandardSetSchema.nullable(),
  newerSet: z
    .looseObject({
      version: z.number().int(),
      publishedOn: z.string(),
      significance: z.enum(['substantial', 'editorial']),
      changes: z.array(legalChangeSchema),
    })
    .nullable(),
  deviations: z.array(legalDeviationSchema),
})
export const legalAdminRevisionSchema = z.looseObject({
  document: z.string(),
  revisionId: z.string(),
  declared: z.boolean(),
  revision: legalRevisionSchema.nullable(),
  current: z.boolean().nullable(),
  predecessorId: z.string().nullable(),
  clauses: z.array(legalClauseSchema).nullable(),
  changes: z.array(legalChangeSchema).nullable(),
})

export type HilosLegalAdminDocument = z.infer<typeof legalAdminDocumentSchema>
export type HilosLegalAdminRevision = z.infer<typeof legalAdminRevisionSchema>

/**
 * Reads the common document data of all four document pages. Undefined means
 * the page has not supplied it; null or malformed data clears the last value.
 * A catalog refusal remains in `LEGAL_CATALOG_REFUSAL_SECTION`.
 */
export function createHilosLegalDocument(
  context: HilosLegalContext,
): ReadonlySignal<HilosLegalAdminDocument | null | undefined> {
  const source = context.scopes.pageDataSignal(LEGAL_DOCUMENT_SECTION)
  return computedSignal(() => {
    const value = source.get()
    if (value === undefined) return undefined
    const parsed = legalAdminDocumentSchema.safeParse(value)
    return parsed.success ? parsed.data : null
  })
}

export interface HilosLegalDocumentRow {
  readonly rowKey: string
  readonly documentLabel: string
  readonly publishedOn: string | null
  readonly declared: boolean
  readonly revision: HilosLegalRevision | null
  readonly covered: number
  readonly window: number
  readonly lapsed: number
}
export interface HilosLegalCheckRow {
  readonly rowKey: string
  readonly ok: boolean
  readonly items: readonly Record<string, unknown>[]
}
export interface HilosLegalDocumentSetRow {
  readonly rowKey: string
  readonly clauseKey: string
  readonly statement: string
  readonly position: number
}
export interface HilosLegalDocumentDeviationRow extends HilosLegalDocumentSetRow {
  readonly standardStatement: string
  readonly text: string
  /** Unknown only when the received direction is malformed. */
  readonly direction: 'stricter' | 'looser' | null
}
export interface HilosLegalRevisionRow {
  readonly rowKey: string
  readonly declared: boolean
  readonly revision: HilosLegalRevision | null
  /** Calendar dates, unchanged by the reader's time zone. */
  readonly publishedOn: string | null
  readonly effectiveOn: string | null
  /** Projected from nullable revision metadata, never from a placeholder. */
  readonly significance: HilosLegalRevision['significance'] | null
  readonly setVersion: number | null
  readonly current: boolean
  readonly origin: string | null
  readonly heldCount: number
  readonly acceptedCount: number
}
export interface HilosLegalAcceptanceRow {
  readonly rowKey: number
  readonly userId: number
  /** The person's name; hidden from a viewer of the admin view mode. */
  readonly name: Hideable<string>
  /** The person's verified email, or null; hidden from a viewer of the admin view mode. */
  readonly email: Hideable<string | null>
  readonly document: string
  readonly revisionId: string
  readonly declared: boolean | null
  readonly acceptedAt: string
}
export interface HilosLegalSettingRow {
  readonly rowKey: string
  /** The value in force; hidden from a viewer of the admin view mode. */
  readonly value: Hideable<string>
  /** The catalog default; hidden from a viewer of the admin view mode. */
  readonly defaultValue: Hideable<string>
}
export interface HilosLegalTable<R> {
  readonly controller: TableViewportController<R>
  start(): void
  dispose(): void
}

/** Reads the inline fragment of a row. */
function slotOf(row: TableRow, slot: string): Record<string, unknown> {
  const data = row.slots[slot]
  return typeof data === 'object' && data !== null && !Array.isArray(data)
    ? (data as Record<string, unknown>)
    : {}
}

/** A declaration's metadata, absent for a revision no longer in code. */
function revisionOf(slot: Record<string, unknown>): HilosLegalRevision | null {
  const parsed = legalRevisionSchema.safeParse(slot[HilosLegalRowKey.revision])
  return parsed.success ? parsed.data : null
}

/** Resolves the document summary without deriving coverage in the browser. */
export function resolveHilosLegalDocumentRow(
  row: TableRow,
): HilosLegalDocumentRow {
  const slot = slotOf(row, 'document')
  return {
    rowKey: String(row.rowKey),
    documentLabel: readString(slot, HilosLegalRowKey.documentLabel),
    publishedOn: readStringOrNull(slot, HilosLegalRowKey.publishedOn),
    declared: readBoolean(slot, HilosLegalRowKey.declared),
    revision: revisionOf(slot),
    covered: readNumber(slot, HilosLegalRowKey.covered),
    window: readNumber(slot, HilosLegalRowKey.window),
    lapsed: readNumber(slot, HilosLegalRowKey.lapsed),
  }
}

/** Resolves the server's check verdict and its diagnostic entries. */
export function resolveHilosLegalCheckRow(row: TableRow): HilosLegalCheckRow {
  const slot = slotOf(row, 'check')
  const items = z
    .array(z.record(z.string(), z.unknown()))
    .safeParse(slot[HilosLegalRowKey.items])
  return {
    rowKey: String(row.rowKey),
    ok: readBoolean(slot, HilosLegalRowKey.ok),
    items: items.success ? items.data : [],
  }
}

/** Resolves a clause in the adopted standard set, preserving catalog order. */
export function resolveHilosLegalDocumentSetRow(
  row: TableRow,
): HilosLegalDocumentSetRow {
  const slot = slotOf(row, 'clause')
  return {
    rowKey: String(row.rowKey),
    clauseKey: readString(slot, HilosLegalRowKey.clauseKey),
    statement: readString(slot, HilosLegalRowKey.statement),
    position: readNumber(slot, HilosLegalRowKey.position),
  }
}

/** Resolves the project's deviation beside the adopted standard statement. */
export function resolveHilosLegalDocumentDeviationRow(
  row: TableRow,
): HilosLegalDocumentDeviationRow {
  const slot = slotOf(row, 'deviation')
  const direction = legalDeviationSchema.shape.direction.safeParse(
    slot[HilosLegalRowKey.direction],
  )
  return {
    rowKey: String(row.rowKey),
    clauseKey: readString(slot, HilosLegalRowKey.clauseKey),
    statement: readString(slot, HilosLegalRowKey.statement),
    position: readNumber(slot, HilosLegalRowKey.position),
    standardStatement: readString(slot, HilosLegalRowKey.standardStatement),
    text: readString(slot, HilosLegalRowKey.text),
    direction: direction.success ? direction.data : null,
  }
}

/** Resolves a declared or historical revision and both server-computed counts. */
export function resolveHilosLegalRevisionRow(
  row: TableRow,
): HilosLegalRevisionRow {
  const slot = slotOf(row, 'revision')
  const revision = revisionOf(slot)
  return {
    rowKey: String(row.rowKey),
    declared: readBoolean(slot, HilosLegalRowKey.declared),
    revision,
    publishedOn: readStringOrNull(slot, HilosLegalRowKey.publishedOn),
    effectiveOn: readStringOrNull(slot, HilosLegalRowKey.effectiveOn),
    significance: revision?.significance ?? null,
    setVersion: revision?.setVersion ?? null,
    current: readBoolean(slot, HilosLegalRowKey.current),
    origin: readStringOrNull(slot, HilosLegalRowKey.origin),
    heldCount: readNumber(slot, HilosLegalRowKey.heldCount),
    acceptedCount: readNumber(slot, HilosLegalRowKey.acceptedCount),
  }
}

/** Preserves an unknown declaration status when the legal catalog refused. */
export function resolveHilosLegalAcceptanceRow(
  row: TableRow,
): HilosLegalAcceptanceRow {
  const slot = slotOf(row, 'acceptance')
  const declared = slot[HilosLegalRowKey.declared]
  const acceptedAtMs = readNumberOrNull(slot, HilosLegalRowKey.acceptedAt)
  return {
    rowKey: Number(row.rowKey),
    userId: readNumber(slot, HilosLegalRowKey.userId),
    name: readHideableString(slot, HilosLegalRowKey.name),
    email: readHideableStringOrNull(slot, HilosLegalRowKey.email),
    document: readString(slot, HilosLegalRowKey.document),
    revisionId: readString(slot, HilosLegalRowKey.revisionId),
    declared: typeof declared === 'boolean' ? declared : null,
    acceptedAt:
      acceptedAtMs === null
        ? '—'
        : (formatMoment(acceptedAtMs, { kind: 'dateTime' }) ?? '—'),
  }
}

/** Resolves the effective setting value and its catalog default. */
export function resolveHilosLegalSettingRow(
  row: TableRow,
): HilosLegalSettingRow {
  const slot = slotOf(row, 'setting')
  return {
    rowKey: String(row.rowKey),
    value: readHideableString(slot, HilosLegalRowKey.value),
    defaultValue: readHideableString(slot, HilosLegalRowKey.defaultValue),
  }
}

/** An exact revision window distinguishes waiting, a confirmed zero and a refused read. */
export function createHilosLegalRevisionAcceptanceSummary(
  controller: TableViewportController<HilosLegalRevisionRow>,
  revisionId: ReadonlySignal<string>,
): ReadonlySignal<string> {
  return computedSignal(() => {
    const body = controller.frame.body.get()
    if (body === 'loading') return 'Loading acceptance count…'
    if (body === 'unavailable' || body === 'empty_page')
      return 'Acceptance count unavailable.'
    if (body === 'empty' || body === 'empty_filtered') return '0 acceptances'
    const count = controller.rows
      .get()
      .find(({ row }) => row?.rowKey === revisionId.get())?.row?.acceptedCount
    return count === undefined
      ? 'Acceptance count unavailable.'
      : `${count} acceptances`
  })
}

/** Describes diagnostics; the verdict and counts always come from the server. */
export function describeHilosLegalCheck(row: HilosLegalCheckRow): {
  title: string
  lines: string[]
} {
  const titles: Record<string, string> = {
    undeclared_revision: 'Accepted revisions missing from code',
    zero_window: 'Zero-length acceptance windows',
    newer_standard_set: 'New framework standard sets',
    deviations: 'Declared project deviations',
  }
  const lines = row.items.map((item) => {
    const document = hilosLegalDocumentLabel(
      readString(item, HilosLegalRowKey.document),
    )
    switch (row.rowKey) {
      case 'undeclared_revision':
        return `${document}: ${readString(item, HilosLegalRowKey.revisionId)} — ${readNumber(item, LegalCheckItemRowKey.people)} people.`
      case 'zero_window':
        return `${document}: ${readString(item, HilosLegalRowKey.revisionId)} takes effect on publication.`
      case 'newer_standard_set':
        return `${document}: set ${readNumber(item, LegalCheckItemRowKey.setVersion)} (${readString(item, LegalCheckItemRowKey.significance)}); the project uses set ${readNumber(item, LegalCheckItemRowKey.documentSetVersion)}.`
      case 'deviations':
        return readNumber(item, LegalCheckItemRowKey.deviations) === 0
          ? `${document}: the consent screen will say there are no differences.`
          : `${document}: ${readNumber(item, LegalCheckItemRowKey.deviations)} deviations.`
      default:
        return document
    }
  })
  return { title: titles[row.rowKey] ?? row.rowKey, lines }
}

/** Binds one controller and its route filter for the lifetime of a view. */
function tableHandle<R>(
  context: HilosLegalContext,
  page: string,
  tableKey: string,
  controller: TableViewportController<R>,
  document?: ReadonlySignal<string>,
  revisionId?: ReadonlySignal<string>,
): HilosLegalTable<R> {
  let teardown: Array<() => void> = []
  return {
    controller,
    start() {
      for (const off of teardown.splice(0)) off()
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          { page, tableKey },
          controller,
        ),
      ]
      if (document !== undefined)
        teardown.push(
          subscribeSignal(document, (value) =>
            controller.setFilter(LEGAL_DOCUMENT_FILTER, value),
          ),
        )
      if (revisionId !== undefined)
        teardown.push(
          subscribeSignal(revisionId, (value) =>
            controller.setFilter(LEGAL_REVISION_FILTER, value),
          ),
        )
    },
    dispose() {
      for (const off of teardown.splice(0)) off()
    },
  }
}

/** Builds a table over the same backend key used by the page registration. */
function tableFor<R>(
  context: HilosLegalContext,
  page: string,
  tableKey: string,
  resolve: (row: TableRow) => R,
  frame: HilosTableFrame,
  document?: ReadonlySignal<string>,
  revisionId?: ReadonlySignal<string>,
): HilosLegalTable<R> {
  return tableHandle(
    context,
    page,
    tableKey,
    new TableViewportController<R>({
      resolve,
      sendViewport: (descriptor) =>
        context.connection.sendTableViewport(page, tableKey, descriptor),
      sendRendered: (rendered) =>
        context.connection.sendTableRendered(page, tableKey, rendered),
      ...(tableKey === HilosLegalTableKey.settings
        ? {
            sendFocus: (rowKey: string) =>
              context.connection.sendTableRowFocus(page, tableKey, rowKey),
          }
        : {}),
      ...(document !== undefined
        ? {
            initialFilter: {
              [LEGAL_DOCUMENT_FILTER]: document.get(),
              ...(revisionId !== undefined
                ? { [LEGAL_REVISION_FILTER]: revisionId.get() }
                : {}),
            },
            initialFilterInSubscription: true,
          }
        : {}),
      frame,
    }),
    document,
    revisionId,
  )
}

/**
 * Where the third count of a document on the section's root leads (HIL-945):
 * the people list narrowed to those past that document's deadline — exactly the
 * people the count counted, whatever the refusal setting says.
 *
 * @param document The document key of the row.
 */
export function hilosLegalLapsedHref(document: string): string {
  return hilosUsersPath(document)
}

const DOCUMENT_COLUMNS: HilosTableColumnOf<HilosLegalDocumentRow>[] = [
  { key: HilosLegalRowKey.rowKey, label: 'Document' },
  {
    key: HilosLegalRowKey.revision,
    label: 'Current revision',
    reads: [HilosLegalRowKey.declared],
  },
  { key: HilosLegalRowKey.covered, label: 'Covered' },
  { key: HilosLegalRowKey.window, label: 'In the window' },
  { key: HilosLegalRowKey.lapsed, label: 'Past deadline' },
  { key: HILOS_TABLE_ACTIONS_KEY, label: '', reads: [HilosLegalRowKey.rowKey] },
]
const REVISION_COLUMNS: HilosTableColumnOf<HilosLegalRevisionRow>[] = [
  {
    key: HilosLegalRowKey.rowKey,
    label: 'Revision',
    reads: [
      HilosLegalRowKey.declared,
      HilosLegalRowKey.current,
      HilosLegalRowKey.revision,
    ],
  },
  { key: HilosLegalRowKey.heldCount, label: 'Held by' },
  { key: HILOS_TABLE_ACTIONS_KEY, label: '', reads: [HilosLegalRowKey.rowKey] },
]
const ACCEPTANCE_COLUMNS: HilosTableColumnOf<HilosLegalAcceptanceRow>[] = [
  {
    key: HilosLegalRowKey.name,
    label: 'Person',
    reads: [HilosLegalRowKey.email],
  },
  { key: HilosLegalRowKey.document, label: 'Document' },
  {
    key: HilosLegalRowKey.revisionId,
    label: 'Revision',
    reads: [HilosLegalRowKey.declared],
  },
  { key: HilosLegalRowKey.acceptedAt, label: 'Accepted', sortable: true },
  { key: HILOS_TABLE_ACTIONS_KEY, label: '', reads: [HilosLegalRowKey.userId] },
]

/**
 * Document tallies on the dedicated page, with legacy root slots by default.
 *
 * @param context The page's shared connection and scopes.
 * @param page The subscribing page, defaulting to the legacy root.
 * @param refusal The live refusal policy, including its hidden viewer value.
 */
export function createHilosLegalDocumentsTable(
  context: HilosLegalContext,
  page: string = HilosPages.LEGAL,
  refusal?: ReadonlySignal<Hideable<string> | undefined>,
): HilosLegalTable<HilosLegalDocumentRow> {
  const frame: HilosTableFrame =
    page === HilosPages.LEGAL_DOCUMENTS
      ? {
          search: { placeholder: 'Document or key' },
          columns: (): HilosTableColumnOf<HilosLegalDocumentRow>[] => [
            {
              key: HilosLegalRowKey.documentLabel,
              label: 'Document',
              sortable: true,
              card: 'title',
              reads: [HilosLegalRowKey.rowKey, HilosLegalRowKey.declared],
            },
            {
              key: HilosLegalRowKey.publishedOn,
              label: 'Revision',
              sortable: true,
              reads: [HilosLegalRowKey.revision, HilosLegalRowKey.declared],
            },
            { key: HilosLegalRowKey.covered, label: 'Covered', sortable: true },
            {
              key: HilosLegalRowKey.window,
              label: 'In the window',
              sortable: true,
            },
            {
              key: HilosLegalRowKey.lapsed,
              label: hilosLegalLapsedLabel(refusal?.get()),
              sortable: true,
              reads: [HilosLegalRowKey.rowKey],
            },
            {
              key: HILOS_TABLE_ACTIONS_KEY,
              label: '',
              reads: [HilosLegalRowKey.rowKey],
            },
          ],
          empty: { title: 'The project has not declared any documents.' },
        }
      : {
          title: 'Documents',
          columns: DOCUMENT_COLUMNS,
          empty: { title: 'The project has not declared any documents.' },
        }
  return tableFor(
    context,
    page,
    HilosLegalTableKey.documents,
    resolveHilosLegalDocumentRow,
    frame,
  )
}

/** The four checks, evaluated by the backend. */
export function createHilosLegalChecksTable(
  context: HilosLegalContext,
): HilosLegalTable<HilosLegalCheckRow> {
  const columns: HilosTableColumnOf<HilosLegalCheckRow>[] = [
    {
      key: HilosLegalRowKey.rowKey,
      label: 'Check',
      reads: [HilosLegalRowKey.ok, HilosLegalRowKey.items],
    },
  ]
  return tableFor(
    context,
    HilosPages.LEGAL,
    HilosLegalTableKey.checks,
    resolveHilosLegalCheckRow,
    { title: 'Checks', columns },
  )
}

/** The adopted standard set, searched and ordered on the server. */
export function createHilosLegalDocumentSetTable(
  context: HilosLegalContext,
  document: ReadonlySignal<string>,
): HilosLegalTable<HilosLegalDocumentSetRow> {
  const columns: HilosTableColumnOf<HilosLegalDocumentSetRow>[] = [
    { key: HilosLegalRowKey.clauseKey, label: 'Clause', sortable: true },
    { key: HilosLegalRowKey.statement, label: 'Statement', sortable: true },
  ]
  return tableFor(
    context,
    HilosPages.LEGAL_DOCUMENT_SET,
    HilosLegalTableKey.documentSet,
    resolveHilosLegalDocumentSetRow,
    {
      columns,
      search: { placeholder: 'Clause or statement' },
      empty: { title: 'No adopted standard set.' },
    },
    document,
  )
}

/** The current revision's deviations from its adopted standard set. */
export function createHilosLegalDocumentDeviationsTable(
  context: HilosLegalContext,
  document: ReadonlySignal<string>,
): HilosLegalTable<HilosLegalDocumentDeviationRow> {
  const columns: HilosTableColumnOf<HilosLegalDocumentDeviationRow>[] = [
    {
      key: HilosLegalRowKey.statement,
      label: 'Deviation',
      reads: [HilosLegalRowKey.text, HilosLegalRowKey.standardStatement],
    },
    { key: HilosLegalRowKey.direction, label: 'Direction', sortable: true },
    {
      key: HilosLegalRowKey.clauseKey,
      label: 'Standard clause',
      sortable: true,
    },
  ]
  return tableFor(
    context,
    HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
    HilosLegalTableKey.documentDeviations,
    resolveHilosLegalDocumentDeviationRow,
    {
      columns,
      search: { placeholder: 'Statement, text or clause' },
      empty: { title: 'No project deviations are declared.' },
    },
    document,
  )
}

/** Revision history on either the document page or an individual revision page. */
export function createHilosLegalRevisionsTable(
  context: HilosLegalContext,
  document: ReadonlySignal<string>,
  page: string = HilosPages.LEGAL_DOCUMENT,
  revisionId?: ReadonlySignal<string>,
): HilosLegalTable<HilosLegalRevisionRow> {
  const columns: HilosTableColumnOf<HilosLegalRevisionRow>[] = [
    {
      key: HilosLegalRowKey.publishedOn,
      label: 'Revision',
      sortable: true,
      reads: [
        HilosLegalRowKey.rowKey,
        HilosLegalRowKey.current,
        HilosLegalRowKey.declared,
      ],
    },
    {
      key: HilosLegalRowKey.significance,
      label: 'Significance',
      reads: [HilosLegalRowKey.revision],
    },
    { key: HilosLegalRowKey.effectiveOn, label: 'Effective', sortable: true },
    {
      key: HilosLegalRowKey.setVersion,
      label: 'Set',
      reads: [HilosLegalRowKey.revision],
    },
    { key: HilosLegalRowKey.heldCount, label: 'Held by', sortable: true },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [HilosLegalRowKey.rowKey],
    },
  ]
  return tableFor(
    context,
    page,
    HilosLegalTableKey.revisions,
    resolveHilosLegalRevisionRow,
    page === HilosPages.LEGAL_DOCUMENT_REVISIONS
      ? { columns, search: { placeholder: 'Revision or publication date' } }
      : { title: 'Revisions', columns: REVISION_COLUMNS },
    document,
    revisionId,
  )
}

/** The two setting rows can also supply the root's deadline label. */
export function createHilosLegalSettingsTable(
  context: HilosLegalContext,
  page: string = HilosPages.LEGAL_SETTINGS,
): HilosLegalTable<HilosLegalSettingRow> {
  const columns: HilosTableColumnOf<HilosLegalSettingRow>[] = [
    { key: HilosLegalRowKey.rowKey, label: 'Setting' },
    {
      key: HilosLegalRowKey.value,
      label: 'Value',
      reads: [HilosLegalRowKey.defaultValue],
    },
    {
      key: HILOS_TABLE_ACTIONS_KEY,
      label: '',
      reads: [HilosLegalRowKey.value, HilosLegalRowKey.defaultValue],
    },
  ]
  return tableFor(
    context,
    page,
    HilosLegalTableKey.settings,
    resolveHilosLegalSettingRow,
    { columns },
  )
}

/** Clears the dependent revision atomically with a document change. */
class LegalAcceptanceController extends TableViewportController<HilosLegalAcceptanceRow> {
  override setFilters(values: Record<string, unknown>): void {
    super.setFilters(
      Object.hasOwn(values, LEGAL_DOCUMENT_FILTER) &&
        values[LEGAL_DOCUMENT_FILTER] !==
          this.filter.get()[LEGAL_DOCUMENT_FILTER]
        ? { ...values, [LEGAL_REVISION_FILTER]: null }
        : values,
    )
  }
}

/** Acceptance options come from the page's complete vocabulary, never its current window. */
export function createHilosLegalAcceptancesTable(
  context: HilosLegalContext,
): HilosLegalTable<HilosLegalAcceptanceRow> {
  const vocabulary = createSignal<HilosLegalAcceptanceFilters>({
    documents: [],
  })
  const document = createSignal<unknown>(undefined)
  const controller = new LegalAcceptanceController({
    resolve: resolveHilosLegalAcceptanceRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.LEGAL_ACCEPTANCES,
        HilosLegalTableKey.acceptances,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.LEGAL_ACCEPTANCES,
        HilosLegalTableKey.acceptances,
        rendered,
      ),
    sendFacets: (facets) =>
      context.connection.sendTableFacets(
        HilosPages.LEGAL_ACCEPTANCES,
        HilosLegalTableKey.acceptances,
        facets,
      ),
    sendFocus: (rowKey: string) =>
      context.connection.sendTableRowFocus(
        HilosPages.LEGAL_ACCEPTANCES,
        HilosLegalTableKey.acceptances,
        rowKey,
      ),
    frame: {
      columns: ACCEPTANCE_COLUMNS,
      search: { placeholder: 'Name or email' },
      empty: { title: 'No acceptance records.' },
      filters: [
        {
          kind: 'select',
          key: LEGAL_DOCUMENT_FILTER,
          label: 'Document',
          anyLabel: 'All documents',
          options: () =>
            vocabulary.get().documents.map((entry) => ({
              value: entry.document,
              label: hilosLegalDocumentLabel(entry.document),
            })),
        },
        {
          kind: 'select',
          key: LEGAL_REVISION_FILTER,
          label: 'Revision',
          anyLabel: 'All revisions',
          options: () =>
            (
              vocabulary
                .get()
                .documents.find((entry) => entry.document === document.get())
                ?.revisions ?? []
            ).map((entry) => ({
              value: entry.revisionId,
              label:
                entry.declared === false
                  ? `${entry.revisionId} (not in code)`
                  : entry.revisionId,
            })),
        },
      ],
    },
  })
  const handle = tableHandle(
    context,
    HilosPages.LEGAL_ACCEPTANCES,
    HilosLegalTableKey.acceptances,
    controller,
  )
  let teardown: Array<() => void> = []
  return {
    controller,
    start() {
      for (const off of teardown.splice(0)) off()
      document.set(controller.filter.get()[LEGAL_DOCUMENT_FILTER])
      teardown = [
        subscribeSignal(controller.filter, (filter) =>
          document.set(filter[LEGAL_DOCUMENT_FILTER]),
        ),
        context.connection.on('projectSignal', (frame) => {
          if (frame.type !== LEGAL_ACCEPTANCES_SIGNAL) return
          const parsed = legalAcceptanceFiltersSchema.safeParse(frame.data)
          if (parsed.success) vocabulary.set(parsed.data)
        }),
      ]
      handle.start()
    },
    dispose() {
      handle.dispose()
      for (const off of teardown.splice(0)) off()
    },
  }
}

/** Tracked writes use the legal page's gate and the backend setting rules. */
export function createHilosLegalSettingsActions(
  context: Pick<HilosLegalContext, 'actions'>,
): { sendSettingSet(key: string, value: string): ActionHandle } {
  return {
    sendSettingSet: (key, value) =>
      context.actions.dispatch(LEGAL_SETTING_SET, { key, value }),
  }
}
