// HilosLogsViewPage — the framework Hilos log viewer (HilosPages.LOGS_VIEW):
// the lines of ONE file on ONE node, for an administrator who came to work out what
// already happened. The node, the source (the live journal or one archived batch)
// and the stream together ARE the address of this screen, so choosing another file
// rewrites the address in place rather than navigating — it is another file of the
// same page, and a navigation would re-subscribe it and drop the catalog. Nothing
// is filtered here: the level and the substring are fields of the read request, and
// the server answers with what matched. The Follow switch runs the live tail, and
// there is no Refresh button on purpose: freshness arrives as a push. Scrolling up
// releases only the STICKING — the tail keeps running, what arrives while the
// reader is up waits beside the pane, and the return control at the bottom carries
// its count; so nothing ever moves under the reader's eyes, and nothing is lost
// without saying so. The catalog, the address, the read, the buffer and the row
// view-model are the core headless's (hilosLogViewer), including the threshold that
// decides "at the tail" and the wording of the notes — this view owns only the
// markup and the scrolling, so a project mounts it by passing its
// HilosLogViewerContext and the Vue and Angular ports measure the same edge.
// Bootstrap classes only, save the one pre-wrap declaration the pane calls for
// because Bootstrap has no utility for it (styling-rules.md, same exception as
// HilosActionError).
import {
  useContext,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react'
import {
  HILOS_LOG_LEVEL_OPTIONS,
  HilosPages,
  LOG_SOURCE_LIVE,
  createHilosLogViewer,
  createSignal,
  hasLogViewerNodes,
  isLogViewerPinned,
  logLevelVariant,
  logViewerNodeOf,
  logViewerPaneState,
  logViewerStreamsOf,
} from '@hilos/core'
import type {
  HilosLogViewerAddress,
  HilosLogViewerContext,
  HilosLogViewerEntry,
  HilosLogViewerNotice,
  PageRouteMatch,
} from '@hilos/core'

import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosLogsViewPage}. */
export interface HilosLogsViewPageProps {
  /** The project context: the connection and the action lifecycle. */
  context: HilosLogViewerContext
}

/** The icon one note is drawn with; its wording belongs to the headless. */
const NOTICE_ICONS: Record<HilosLogViewerNotice, string> = {
  rotated: 'bi-arrow-repeat',
  skipped: 'bi-fast-forward',
  dropped: 'bi-scissors',
  stopped: 'bi-stop-circle',
}

/**
 * The value the source select carries for one choice.
 *
 * @param source The live journal, one archived batch, or nothing chosen.
 */
function sourceValue(source: typeof LOG_SOURCE_LIVE | number | null): string {
  return source === null ? '' : String(source)
}

/**
 * The label of one archived batch in the source select.
 *
 * @param batch The batch's rotation moment, in seconds.
 */
function batchLabel(batch: number): string {
  return new Date(batch * 1000).toLocaleString()
}

/**
 * The framework log viewer: the three-slot address, the read controls, the pane
 * of lines with its stacks and notes, and the live tail.
 *
 * @param props The project context (the connection and the action lifecycle).
 */
export function HilosLogsViewPage({ context }: HilosLogsViewPageProps) {
  const router = useContext(HilosRouterContext)
  // The address IS what this screen is showing, so the navigator is what it reads
  // and writes. A router-less mount (none in practice) still reads files — it just
  // keeps the choice out of the location bar.
  const address: HilosLogViewerAddress = useMemo(
    () =>
      router ?? {
        currentRoute: createSignal<PageRouteMatch>({
          page: HilosPages.LOGS_VIEW,
          params: {},
          admin: true,
        }),
        replacePath: () => {},
      },
    [router],
  )

  const viewer = useMemo(
    () => createHilosLogViewer(context, address),
    [context, address],
  )

  useEffect(() => {
    viewer.start()

    return () => viewer.dispose()
  }, [viewer])

  const catalog = useSignal(viewer.catalog)
  const selection = useSignal(viewer.selection)
  const rows = useSignal(viewer.rows)
  const level = useSignal(viewer.level)
  const substring = useSignal(viewer.substring)
  const busy = useSignal(viewer.busy)
  const readable = useSignal(viewer.readable)
  const hasMore = useSignal(viewer.hasMore)
  const refusal = useSignal(viewer.refusal)
  const followRequested = useSignal(viewer.followRequested)
  const following = useSignal(viewer.following)
  const canFollow = useSignal(viewer.canFollow)
  const pinned = useSignal(viewer.pinned)
  const pendingLines = useSignal(viewer.pendingLines)

  // The node select exists only where nodes have names: a picker with one nameless
  // option is furniture for a choice that does not exist.
  const clustered = hasLogViewerNodes(catalog)
  const node = logViewerNodeOf(catalog, selection.nodeId)
  const streams = logViewerStreamsOf(node, selection.source)

  // Which state the pane is in — the discrimination is the headless's, because it
  // is the same question in all three view frameworks. The count is of ROWS and
  // not of entries: a file with no lines that has just been rotated has something
  // to say, and "this file is empty" would hide the note saying it.
  const paneState = logViewerPaneState(
    catalog,
    selection,
    rows.length,
    readable,
    level !== '' || substring !== '',
  )

  // The counter beside the Earlier button counts entries, because that is the word
  // it uses; a note is not one.
  const entryCount = rows.filter((row) => row.kind === 'entry').length

  // Newest batch first: an operator opening the archive is looking for what just
  // happened far more often than for what happened last month.
  const batches = [...(node?.batches ?? [])].reverse()

  // The pane is the scrolling element, so it is the one whose position answers
  // "is the reader at the tail" — the threshold itself is the headless's, so that
  // the Vue and Angular ports do not each invent their own.
  const pane = useRef<HTMLDivElement | null>(null)

  function onScroll(): void {
    const element = pane.current
    if (element === null) {
      return
    }

    viewer.setPinned(
      isLogViewerPinned(
        element.scrollTop,
        element.scrollHeight,
        element.clientHeight,
      ),
    )
  }

  // Sticking to the bottom happens after the rows are drawn and BEFORE the browser
  // paints, because the height to scroll to does not exist until the rows are in
  // the DOM and a painted frame at the old offset is a visible jerk on every batch
  // of lines.
  useLayoutEffect(() => {
    if (!pinned) {
      return
    }

    const element = pane.current
    if (element !== null) {
      element.scrollTop = element.scrollHeight
    }
    // The pin is read but is not what re-runs this: sticking follows the rows.
  }, [rows])

  // The substring is a field of the read request, so it is sent when the field is
  // COMMITTED — on blur, on Enter, on the search box's own clear button — and not on
  // every keystroke, which would be one read per character. React models onChange as
  // the input event and has no prop for the DOM change event, so this is the one
  // place the port reaches past it to the element.
  const substringField = useRef<HTMLInputElement | null>(null)

  useEffect(() => {
    const element = substringField.current
    if (element === null) {
      return
    }

    const commit = (): void => viewer.setSubstring(element.value)
    element.addEventListener('change', commit)

    return () => element.removeEventListener('change', commit)
  }, [viewer])

  // Which stacks are open, by entry key. The key survives a page of older lines
  // arriving above, so an opened stack stays open when the pane grows upwards.
  const [opened, setOpened] = useState<ReadonlySet<string>>(new Set())

  function isOpen(entry: HilosLogViewerEntry): boolean {
    return opened.has(entry.key)
  }

  function toggle(entry: HilosLogViewerEntry): void {
    setOpened((current) => {
      const next = new Set(current)
      if (!next.delete(entry.key)) {
        next.add(entry.key)
      }

      return next
    })
  }

  return (
    <HilosAdminPage page={HilosPages.LOGS_VIEW}>
      <div className="border rounded-3 p-3 mb-3">
        <div className="row g-2 align-items-end">
          {clustered ? (
            <div className="col-6 col-md-2">
              <label
                className="form-label small fw-semibold mb-1"
                htmlFor="hilos-log-node"
              >
                Node
              </label>
              <select
                id="hilos-log-node"
                className="form-select form-select-sm"
                value={selection.nodeId ?? ''}
                data-id="hilos-log-node"
                onChange={(event) =>
                  viewer.select({ nodeId: event.target.value })
                }
              >
                <option value="" disabled>
                  Choose a node
                </option>
                {(catalog?.nodes ?? []).map((entry) => (
                  <option key={entry.nodeId} value={entry.nodeId}>
                    {entry.nodeId}
                  </option>
                ))}
              </select>
            </div>
          ) : null}
          <div className="col-6 col-md-3">
            <label
              className="form-label small fw-semibold mb-1"
              htmlFor="hilos-log-source"
            >
              Source
            </label>
            <select
              id="hilos-log-source"
              className="form-select form-select-sm"
              value={sourceValue(selection.source)}
              data-id="hilos-log-source"
              onChange={(event) =>
                viewer.select({
                  source:
                    event.target.value === LOG_SOURCE_LIVE
                      ? LOG_SOURCE_LIVE
                      : Number(event.target.value),
                })
              }
            >
              <option value={LOG_SOURCE_LIVE}>Live journal</option>
              {batches.map((batch) => (
                <option key={batch} value={batch}>
                  Batch — {batchLabel(batch)}
                </option>
              ))}
            </select>
          </div>
          <div className="col-12 col-md-3">
            <label
              className="form-label small fw-semibold mb-1"
              htmlFor="hilos-log-stream"
            >
              Stream
            </label>
            <select
              id="hilos-log-stream"
              className="form-select form-select-sm"
              value={selection.stream ?? ''}
              data-id="hilos-log-stream"
              onChange={(event) =>
                viewer.select({
                  stream: event.target.value === '' ? null : event.target.value,
                })
              }
            >
              <option value="">Choose a stream</option>
              {streams.map((stream) => (
                <option key={stream.key} value={stream.key}>
                  {stream.key}
                </option>
              ))}
            </select>
          </div>
          <div className="col-6 col-md-2">
            <label
              className="form-label small fw-semibold mb-1"
              htmlFor="hilos-log-level"
            >
              Level
            </label>
            <select
              id="hilos-log-level"
              className="form-select form-select-sm"
              value={level}
              data-id="hilos-log-level"
              onChange={(event) => viewer.setLevel(event.target.value)}
            >
              {HILOS_LOG_LEVEL_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
          <div className="col-6 col-md-2">
            <span className="form-label small fw-semibold mb-1 d-block">
              Follow
            </span>
            <div className="form-check form-switch mb-1">
              <input
                id="hilos-log-follow"
                className="form-check-input"
                type="checkbox"
                checked={followRequested}
                disabled={!canFollow}
                data-id="hilos-log-follow"
                onChange={(event) => viewer.setFollow(event.target.checked)}
              />
              <label
                className="form-check-label small"
                htmlFor="hilos-log-follow"
              >
                tail
              </label>
            </div>
            {!canFollow ? (
              <span
                className="small text-body-secondary"
                data-id="hilos-log-follow-off"
              >
                An archived batch has no tail.
              </span>
            ) : null}
          </div>
          <div className="col-12">
            <label className="visually-hidden" htmlFor="hilos-log-substring">
              Search inside the lines
            </label>
            <input
              id="hilos-log-substring"
              ref={substringField}
              type="search"
              className="form-control form-control-sm"
              placeholder="Search inside the lines"
              defaultValue={substring}
              data-id="hilos-log-substring"
            />
          </div>
        </div>
      </div>

      <div className="d-flex flex-wrap align-items-center gap-2 mb-2">
        <button
          type="button"
          className="btn btn-sm btn-outline-secondary"
          disabled={!hasMore || busy}
          data-id="hilos-log-earlier"
          onClick={() => viewer.readOlder()}
        >
          <i className="bi bi-arrow-up me-1" aria-hidden="true" />
          Earlier
        </button>
        <span className="small text-body-secondary" data-id="hilos-log-count">
          {entryCount} entries shown
        </span>
        {following ? (
          <span
            className="ms-auto badge text-bg-success-subtle text-success-emphasis border border-success-subtle"
            data-id="hilos-log-tail-badge"
          >
            <i className="bi bi-broadcast me-1" aria-hidden="true" />
            Tail is running
          </span>
        ) : null}
      </div>

      <div className="position-relative">
        <div
          ref={pane}
          className="border rounded-3 bg-body-tertiary py-2 overflow-auto"
          style={{ maxHeight: '26rem', whiteSpace: 'pre-wrap' }}
          data-id="hilos-log-pane"
          onScroll={onScroll}
        >
          {refusal ? (
            <p
              className="px-3 mb-0 small text-danger"
              data-id="hilos-log-refusal"
            >
              {refusal}
            </p>
          ) : paneState === 'unknown' ? (
            <p
              className="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-unknown"
            >
              The cluster picture has not arrived yet, so there is nothing to
              choose between — not nothing to read.
            </p>
          ) : paneState === 'unreadable' ? (
            <p
              className="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-unreadable"
            >
              No node could read its log store.
            </p>
          ) : paneState === 'empty' ? (
            <p
              className="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-catalog"
            >
              No streams have been reported yet.
            </p>
          ) : paneState === 'unchosen' ? (
            <p
              className="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-unchosen"
            >
              Choose a stream to read.
            </p>
          ) : paneState === 'missing' ? (
            <p
              className="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-missing"
            >
              This file cannot be read. The rotation may have carried it off, or
              nothing has written to it yet.
            </p>
          ) : paneState === 'nomatch' ? (
            <p
              className="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-nomatch"
            >
              Nothing in this file matched.
            </p>
          ) : paneState === 'silent' ? (
            <p
              className="px-3 mb-0 small text-body-secondary"
              data-id="hilos-log-empty-silent"
            >
              This file is empty.
            </p>
          ) : (
            rows.map((row) =>
              row.kind === 'notice' ? (
                <div
                  key={row.key}
                  className="d-flex align-items-center justify-content-center gap-2 px-3 py-1 small text-body-secondary"
                  data-id="hilos-log-notice"
                >
                  <i
                    className={`bi ${NOTICE_ICONS[row.notice]}`}
                    aria-hidden="true"
                  />
                  <span>{row.text}</span>
                </div>
              ) : (
                <div key={row.key} data-id="hilos-log-entry">
                  <div
                    className={`d-flex gap-2 px-3 py-1 font-monospace small text-break border-start border-4 border-${logLevelVariant(row.level)}${row.orphan ? ' opacity-75' : ''}`}
                  >
                    <span
                      className={`fw-semibold text-nowrap text-${logLevelVariant(row.level)}`}
                    >
                      {row.level}
                    </span>
                    <span className="text-body-secondary text-nowrap">
                      {row.time}
                    </span>
                    <span className="flex-grow-1">{row.text}</span>
                    {row.frames.length > 0 ? (
                      <button
                        type="button"
                        className="btn btn-sm btn-link p-0 text-decoration-none text-nowrap"
                        aria-expanded={isOpen(row)}
                        aria-label={`Call stack of this entry, ${row.frames.length} frames`}
                        data-id="hilos-log-stack-toggle"
                        onClick={() => toggle(row)}
                      >
                        <i
                          className="bi bi-info-circle me-1"
                          aria-hidden="true"
                        />
                        {row.frames.length}
                      </button>
                    ) : null}
                  </div>
                  {row.frames.length > 0 && isOpen(row) ? (
                    <div className="bg-body" data-id="hilos-log-stack">
                      {row.frames.map((frame, index) => (
                        <div
                          key={index}
                          className={`d-flex gap-2 px-3 py-1 font-monospace small text-break opacity-75 border-start border-4 border-${logLevelVariant(row.level)}`}
                        >
                          <span className="flex-grow-1">{frame.text}</span>
                        </div>
                      ))}
                    </div>
                  ) : null}
                </div>
              ),
            )
          )}
        </div>
        {!pinned ? (
          <button
            type="button"
            className="btn btn-sm btn-primary position-absolute bottom-0 start-50 translate-middle-x mb-2"
            data-id="hilos-log-back-to-tail"
            onClick={() => viewer.returnToTail()}
          >
            Back to the tail{pendingLines > 0 ? ` · ${pendingLines} new` : ''}
          </button>
        ) : null}
      </div>
    </HilosAdminPage>
  )
}
