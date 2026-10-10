import { z } from 'zod'

import { formatBytes } from '../../format/bytes.js'

/** Page-data section containing the opening journal snapshot. */
export const CHANGE_LOG_OVERVIEW_SECTION = 'changeLogOverview'

/** Journal snapshot carried in the change-log page response. */
export const changeLogOverviewSchema = z.looseObject({
  journalEntries: z.number().int(),
  journalBytes: z.number().int(),
  oldestAt: z.string().nullable(),
  journaledTables: z.array(z.string()),
  liveTables: z.number().int(),
})

/** Validated journal snapshot. */
export type HilosChangeLogOverview = z.infer<typeof changeLogOverviewSchema>

/** Explains where the journal comes from and what this section can do. */
export const CHANGE_LOG_SECTION_NOTE =
  "Database triggers write the journal, and the code decides what goes in: the entity's journal label and its column exceptions. The journal lives in its own database beside the main one. This section reads it — there is nothing to switch on or off here."

/** Label of the journal-database tile. */
export const CHANGE_LOG_TILE_JOURNAL_LABEL = 'In the journal database'
/** Label of the tracked-table tile. */
export const CHANGE_LOG_TILE_TRACKED_LABEL = 'Tracked'
/** Note below the tracked-table figure. */
export const CHANGE_LOG_TILE_TRACKED_NOTE = 'tables with the journal label'
/** Link text for the tracked-tables page when no table is tracked. */
export const CHANGE_LOG_OPEN_TABLES_LABEL = 'Open tables'

/**
 * Validate the page-data section before a view reads its figures.
 *
 * @param raw Untrusted page data.
 */
export function readHilosChangeLogOverview(
  raw: unknown,
): HilosChangeLogOverview | null {
  const parsed = changeLogOverviewSchema.safeParse(raw)
  return parsed.success ? parsed.data : null
}

/**
 * Format the exact journal-entry count compactly in the reader's locale.
 *
 * @param entries Number of journal rows.
 */
export function formatChangeLogEntries(entries: number): string {
  return new Intl.NumberFormat(undefined, {
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(entries)
}

/**
 * Describe journal size and its first entry.
 *
 * @param overview Journal snapshot.
 */
export function changeLogJournalNote(overview: HilosChangeLogOverview): string {
  if (overview.oldestAt === null) return 'nothing recorded yet'
  const since = new Date(overview.oldestAt).toLocaleDateString(undefined, {
    month: 'long',
    year: 'numeric',
  })
  return `${formatBytes(overview.journalBytes)} · since ${since}`
}

/**
 * Show how many live database tables carry the journal label.
 *
 * @param overview Journal snapshot.
 */
export function formatChangeLogCoverage(
  overview: HilosChangeLogOverview,
): string {
  return `${overview.journaledTables.length} of ${overview.liveTables}`
}

/**
 * Distinguish an installation with no journaled tables from an empty journal.
 *
 * @param overview Journal snapshot, or null before page data arrives.
 */
export function changeLogFeedEmpty(overview: HilosChangeLogOverview | null): {
  kind: 'untracked' | 'empty'
  title: string
  hint: string
} {
  return overview !== null && overview.journaledTables.length === 0
    ? {
        kind: 'untracked',
        title: 'No table is tracked',
        hint: 'No entity carries the journal label, so no journal triggers exist and nothing is written.',
      }
    : {
        kind: 'empty',
        title: 'Nothing recorded yet',
        hint: 'The tracked tables have not changed since the journal started.',
      }
}
