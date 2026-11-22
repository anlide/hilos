// HilosUsersPage — the framework Hilos users-list page (HilosPages.USERS): the
// users table inside the admin shell. All table logic, the row view-model, and the
// frame the table declares — its columns, search, and empty words — are the core
// headless's (createHilosUsersTable / HilosUserRow); this view owns only the cell
// markup, so a project mounts it by passing its HilosUsersContext. The framework
// owns every cell except the trailing actions cell, which a project fills through
// the `rowActions` render prop (e.g. a link to the detail page); the takeover
// lives on the person's card since HIL-1170. The bar's "Past deadline on" filter
// narrows the list to the people past one legal document's deadline and lives
// in the address too (`/hilos/users/terms`, HIL-945): the legal section's root
// links its count there, and changing the filter rewrites the address. An
// account folded into another one wears a gray "Merged" badge beside its name
// (HIL-1292). The presence badge says one of three words (HIL-1566) and holds the
// room of the widest one, so a presence changing live neither moves the row nor
// spills out of its column. An account being erased wears "Being deleted" beside
// its name (a merged one stays Merged), and every account of its circle carries
// a caption above its row bar: the summary, then the parts not done, the
// owners awaited, and the refusals, three entries a line and "+N more"
// (hilosUsersErasureCaption, HIL-1961). A bar about other work gets no
// caption. A merge bar carries its summary, unfinished parts, waits and refusal.
// Bootstrap classes only (styling-rules.md).
import { Fragment, useContext, useEffect, useMemo } from 'react'
import type { ReactNode } from 'react'
import { HILOS_TABLE_ACTIONS_KEY, HilosPages } from '@hilos/core/base'
import {
  USER_OPEN_SESSION_COUNT_FIELD,
  USER_PRESENCE_FIELD,
  HILOS_PRESENCE_WIDEST_LABEL,
  HILOS_USER_ERASURE_COPY,
  HILOS_USERS_MERGE_COPY,
  createHilosUsersTable,
  hilosPresenceBadge,
  hilosStandingBadge,
} from '@hilos/core/admin/users'
import type { HilosUserRow, HilosUsersContext } from '@hilos/core/admin/users'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosTableText } from '../../HilosTableText.js'
import { HilosViewportTable } from '../../HilosViewportTable.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

// The badges the list draws: the merged standing and an account being erased,
// read off the row's merge slot and the live erasures map rather than off a
// standing the list does not carry.
const mergedBadge = hilosStandingBadge('merged')
const erasingBadge = hilosStandingBadge('erasing')

/** Props for {@link HilosUsersPage}. */
export interface HilosUsersPageProps {
  /** The project context: scope stores, connection, and the user collection. */
  context: HilosUsersContext
  /** The trailing actions cell for one row (e.g. an "Open" link). */
  rowActions?: (row: HilosUserRow) => ReactNode
}

/**
 * The framework users admin page: the searchable, sortable users table.
 *
 * @param props The project context and the optional trailing-actions renderer.
 */
export function HilosUsersPage({ context, rowActions }: HilosUsersPageProps) {
  // The lapsed filter is read from the address the page opened on and written
  // back as it changes; mounted without a navigator, the list opens whole and
  // leaves the address alone.
  const router = useContext(HilosRouterContext)
  const users = useMemo(
    () => createHilosUsersTable(context, router ?? undefined),
    [context, router],
  )
  const erasures = useSignal(users.erasures)
  const mergeCaptions = useSignal(users.mergeCaptions)

  // Bind the server-windowed table to the connection on mount, request the first
  // window, and unbind on unmount.
  useEffect(() => {
    users.start()

    return () => users.dispose()
  }, [users])

  return (
    <HilosAdminPage page={HilosPages.USERS}>
      <HilosViewportTable
        controller={users.controller}
        rowProgress={(_bar, rowKey) => {
          const caption = erasures.get(rowKey)
          if (caption !== undefined) {
            return (
              <>
                <div data-id="hilos-users-erasure-summary">
                  {caption.summary}
                </div>
                {caption.expected.shown.length > 0 ? (
                  <div data-id="hilos-users-erasure-expected">
                    {HILOS_USER_ERASURE_COPY.expected}:{' '}
                    {caption.expected.shown.map((part, index) => (
                      <Fragment key={part.key}>
                        {index > 0 ? '; ' : null}
                        <code>{part.participant}</code>
                        {part.account !== null ? ` · #${part.account}` : null}
                      </Fragment>
                    ))}
                    {caption.expected.more !== null
                      ? `; ${caption.expected.more}`
                      : null}
                  </div>
                ) : null}
                {caption.waits.shown.length > 0 ? (
                  <div data-id="hilos-users-erasure-waits">
                    {HILOS_USER_ERASURE_COPY.waits}:{' '}
                    {caption.waits.shown.map((wait, index) => (
                      <Fragment key={wait.key}>
                        {index > 0 ? '; ' : null}
                        <code>{wait.participant}</code>
                        {wait.account !== null ? ` · #${wait.account}` : null}
                      </Fragment>
                    ))}
                    {caption.waits.more !== null
                      ? `; ${caption.waits.more}`
                      : null}
                  </div>
                ) : null}
                {caption.refusals.shown.length > 0 ? (
                  <div data-id="hilos-users-erasure-refusals">
                    {HILOS_USER_ERASURE_COPY.refusals}:{' '}
                    {caption.refusals.shown.map((refusal, index) => (
                      <Fragment key={refusal.key}>
                        {index > 0 ? '; ' : null}
                        {refusal.account !== null
                          ? `#${refusal.account} · `
                          : null}
                        {refusal.text}
                      </Fragment>
                    ))}
                    {caption.refusals.more !== null
                      ? `; ${caption.refusals.more}`
                      : null}
                  </div>
                ) : null}
              </>
            )
          }

          const mergeCaption = mergeCaptions.get(rowKey)
          if (mergeCaption === undefined) {
            return null
          }

          return (
            <>
              <div data-id="hilos-users-merge-summary">
                {mergeCaption.summary}
              </div>
              {mergeCaption.expected.shown.length > 0 ? (
                <div data-id="hilos-users-merge-expected">
                  {HILOS_USERS_MERGE_COPY.expected}:{' '}
                  {mergeCaption.expected.shown.map((part, index) => (
                    <Fragment key={part.key}>
                      {index > 0 ? '; ' : null}
                      {part.participant}
                      {part.account !== null ? ` · #${part.account}` : null}
                    </Fragment>
                  ))}
                  {mergeCaption.expected.more !== null
                    ? `; ${mergeCaption.expected.more}`
                    : null}
                </div>
              ) : null}
              {mergeCaption.waits.shown.length > 0 ? (
                <div data-id="hilos-users-merge-waits">
                  {HILOS_USERS_MERGE_COPY.waits}:{' '}
                  {mergeCaption.waits.shown.map((wait, index) => (
                    <Fragment key={wait.key}>
                      {index > 0 ? '; ' : null}
                      {wait.participant}
                      {wait.account !== null ? ` · #${wait.account}` : null}
                    </Fragment>
                  ))}
                  {mergeCaption.waits.more !== null
                    ? `; ${mergeCaption.waits.more}`
                    : null}
                </div>
              ) : null}
              {mergeCaption.refusal !== null ? (
                <div data-id="hilos-users-merge-refusal">
                  {mergeCaption.refusal}
                </div>
              ) : null}
            </>
          )
        }}
        cells={{
          id: (row) => row.id,
          name: (row) => (
            <div className="d-flex align-items-start gap-1">
              <div className="flex-grow-1">
                <HilosTableText value={row.name} />
              </div>
              {row.merged && mergedBadge !== null ? (
                <span
                  className={`badge text-bg-${mergedBadge.tone} flex-shrink-0`}
                  data-id="hilos-users-merged"
                >
                  <i
                    className={`bi ${mergedBadge.icon} me-1`}
                    aria-hidden="true"
                  />
                  {mergedBadge.label}
                </span>
              ) : null}
              {!row.merged &&
              erasures.has(String(row.id)) &&
              erasingBadge !== null ? (
                <span
                  className={`badge text-bg-${erasingBadge.tone} flex-shrink-0`}
                  data-id="hilos-users-erasing"
                >
                  <i
                    className={`bi ${erasingBadge.icon} me-1`}
                    aria-hidden="true"
                  />
                  {erasingBadge.label}
                </span>
              ) : null}
            </div>
          ),
          [USER_PRESENCE_FIELD]: (row) => {
            const badge = hilosPresenceBadge(row.presence)

            return (
              <span className="hilos-stack d-inline-grid">
                <span className="badge invisible" aria-hidden="true">
                  {HILOS_PRESENCE_WIDEST_LABEL}
                </span>
                <span className={`badge text-bg-${badge.tone}`}>
                  {badge.label}
                </span>
              </span>
            )
          },
          [USER_OPEN_SESSION_COUNT_FIELD]: (row) => row.openSessionCount ?? '—',
          lastActivity: (row) => row.lastActivity ?? '—',
          [HILOS_TABLE_ACTIONS_KEY]: (row) => rowActions?.(row),
        }}
      />
    </HilosAdminPage>
  )
}
