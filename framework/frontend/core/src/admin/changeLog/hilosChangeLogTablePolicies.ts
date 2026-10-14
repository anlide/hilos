import { z } from 'zod'

/** Page-data section containing the opening noisy-column snapshot. */
export const CHANGE_LOG_NOISY_COLUMNS_SECTION = 'changeLogNoisyColumns'

/** Live tracked columns omitted from the journal, with their declared reasons. */
export const changeLogNoisyColumnsSchema = z.array(
  z.object({
    table: z.string().min(1),
    column: z.string().min(1),
    reason: z.string().min(1),
  }),
)

/** Validated noisy-column declaration from the live schema. */
export type HilosChangeLogNoisyColumn = z.infer<
  typeof changeLogNoisyColumnsSchema
>[number]

/** Explains who declares journal coverage and what the screen can do. */
export const CHANGE_LOG_TABLES_NOTE =
  'The entity declares whether its table is journaled with a journal label and exceptions for individual columns. This screen shows those declarations; there is nothing to change here.'

/** Heading of the live noisy-column declarations. */
export const CHANGE_LOG_NOISY_COLUMNS_TITLE = 'Noisy columns'
/** Explains why automatic changes are omitted from the journal. */
export const CHANGE_LOG_NOISY_COLUMNS_NOTE =
  'A noisy column changes automatically during operation rather than by a decision. Its history can drown out real changes. An update touching only noisy columns is not journaled at all. A table made entirely of noisy columns may have no journal label.'
/** Empty state for a valid snapshot with no noisy columns. */
export const CHANGE_LOG_NOISY_COLUMNS_EMPTY =
  'No noisy columns are declared on tracked tables'
/** Label of a noisy column, qualified by its table. */
export const CHANGE_LOG_NOISY_COLUMNS_COLUMN_LABEL = 'Column'
/** Label of the reason declared by the entity. */
export const CHANGE_LOG_NOISY_COLUMNS_REASON_LABEL = 'Why noisy'
/** Link text for opening a tracked table. */
export const CHANGE_LOG_TABLES_OPEN_LABEL = 'Open'

/** Heading of the phase-1 column-mode reference. */
export const CHANGE_LOG_COLUMN_MODES_TITLE = 'How a column enters the journal'

/** Phase-1 journal modes and their ready-to-render reference cards. */
export const CHANGE_LOG_COLUMN_MODES = [
  {
    key: 'value',
    label: 'With value',
    icon: 'bi-check2',
    tone: 'text-bg-success-subtle text-success-emphasis border border-success-subtle',
    description:
      'An update records the old and new values. Numbers, short strings, and dates stay inline in the journal row; long text and JSON go into a neighboring journal table.',
  },
  {
    key: 'personal',
    label: 'Personal — only a fact',
    icon: 'bi-incognito',
    tone: 'text-bg-warning-subtle text-warning-emphasis border border-warning-subtle',
    description:
      'A personal column records only the fact that it changed, never its value.',
  },
  {
    key: 'secret',
    label: 'Secret — not written',
    icon: 'bi-shield-lock',
    tone: 'text-bg-danger-subtle text-danger-emphasis border border-danger-subtle',
    description:
      'Hashes, keys, and codes are not written to the journal: neither their values nor the fact that they changed.',
  },
  {
    key: 'noise',
    label: 'Noisy — not written',
    icon: 'bi-volume-mute',
    tone: 'text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
    description:
      'Automatic changes such as last activity, sign-in time, counters, and an updated-at stamp are not written. An update touching only noisy columns produces no journal row.',
  },
  {
    key: 'binary',
    label: 'Binary — only a fact',
    icon: 'bi-file-binary',
    tone: 'text-bg-warning-subtle text-warning-emphasis border border-warning-subtle',
    description:
      'A binary column records only the fact that it changed, never its binary value.',
  },
  {
    key: 'record_key',
    label: 'Record key',
    icon: 'bi-key',
    tone: 'text-bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
    description:
      'The primary key identifies the record in each journal row; it is not recorded as a separate field change.',
  },
] as const

/**
 * Validate the opening section without changing declared names or reasons.
 *
 * @param raw Untrusted page-data section.
 */
export function readHilosChangeLogNoisyColumns(
  raw: unknown,
): readonly HilosChangeLogNoisyColumn[] | null {
  const parsed = changeLogNoisyColumnsSchema.safeParse(raw)
  return parsed.success ? parsed.data : null
}
