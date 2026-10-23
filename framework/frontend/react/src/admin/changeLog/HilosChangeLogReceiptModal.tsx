// HilosChangeLogReceiptModal — the local read-only detail surface of the Change
// Log section. The core reader owns requests, snapshots, attribution, value
// sides and pagination; HilosModal owns the keyboard and focus. Hide's
// focus/scroll placement is the only DOM effect this view adds. A record whose
// key is not masked links to the Tracked table page once the router gives that
// page an address; following it closes the dialog. Not exported from the
// package root: the page that opens it owns its reader. Bootstrap classes only
// (styling-rules.md).
import { useContext, useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import {
  CHANGE_LOG_RECEIPT_COPY as copy,
  CHANGE_LOG_RECORD_ROUTE_PARAM,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  changeLogEntryDetails,
  changeLogReceiptAttribution,
  changeLogReceiptPagination,
  changeLogRecordRouteParam,
  formatChangeLogReceiptTitle,
  formatChangeLogTime,
} from '@hilos/core/admin/change-log'
import { HilosPages, hilosTimeZone, isHiddenValue } from '@hilos/core/base'
import type {
  HilosChangeLogEntry,
  HilosChangeLogReceiptDialog,
  HilosChangeLogReceiptPerson,
  HilosChangeLogReceiptReader,
} from '@hilos/core/admin/change-log'
import type { HilosRouter } from '@hilos/core/base'

import { HilosFormError } from '../../HilosFormError.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosLink } from '../../HilosLink.js'
import { HilosLongText } from '../../HilosLongText.js'
import { HilosModal } from '../../HilosModal.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosChangeLogReceiptModal}. */
export interface HilosChangeLogReceiptModalProps {
  /** The current lazy snapshot, or null when closed. */
  dialog: HilosChangeLogReceiptDialog | null
  /** Intents of the page's one shared receipt reader. */
  reader: HilosChangeLogReceiptReader
}

/**
 * Address the Tracked table page narrowed to the entry's record, when that page
 * is built and no part of the key is masked.
 *
 * @param router The navigator in context, or null without one.
 * @param entry Journal entry of the open receipt.
 */
function recordPath(
  router: HilosRouter | null,
  entry: HilosChangeLogEntry,
): string | undefined {
  const key = entry.recordKey
  const parts = (key ?? []).filter(
    (part): part is number | string => part !== null,
  )
  if (key === null || parts.length === 0 || parts.length !== key.length) {
    return undefined
  }
  return router?.resolvePath(HilosPages.CHANGE_LOG_TABLE, {
    [CHANGE_LOG_TABLE_ROUTE_PARAM]: entry.table,
    [CHANGE_LOG_RECORD_ROUTE_PARAM]: changeLogRecordRouteParam(parts),
  })
}

/**
 * Draw a receipt person: the hidden mark beside the retained number, or the label.
 *
 * @param person The actor or the subject of the receipt.
 */
function personLabel(person: HilosChangeLogReceiptPerson): ReactNode {
  return isHiddenValue(person.label) ? (
    <>
      <HilosHiddenMark />
      {` (#${person.id})`}
    </>
  ) : (
    person.label
  )
}

/**
 * The read-only receipt dialog over the page's one shared reader.
 *
 * @param props The current snapshot and the reader's intents.
 */
export function HilosChangeLogReceiptModal({
  dialog,
  reader,
}: HilosChangeLogReceiptModalProps) {
  useSignal(hilosTimeZone)
  const router = useContext(HilosRouterContext)
  const attribution = dialog?.header
    ? changeLogReceiptAttribution(dialog.header)
    : null
  const entries = (dialog?.entries ?? []).map((entry) => ({
    entryId: entry.entryId,
    details: changeLogEntryDetails(entry),
    recordPath: recordPath(router, entry),
  }))
  const pagination = dialog ? changeLogReceiptPagination(dialog) : null
  const changesHeading = useRef<HTMLHeadingElement>(null)
  // Set by Hide, cleared once the collapsed portion has rendered and the
  // beginning of What changed has taken focus.
  const [returning, setReturning] = useState(false)

  useEffect(() => {
    if (!returning) return
    setReturning(false)
    changesHeading.current?.focus({ preventScroll: true })
    changesHeading.current?.scrollIntoView({ block: 'start' })
  }, [returning])

  /** Restore the first portion, then return to the beginning of What changed. */
  function hide(): void {
    reader.collapse()
    setReturning(true)
  }

  return (
    <HilosModal
      open={dialog !== null}
      title={formatChangeLogReceiptTitle(dialog)}
      size="wide"
      initialFocus="dialog"
      onClose={() => reader.close()}
      actions={({ requestClose }) => (
        <button
          type="button"
          className="btn btn-primary"
          data-id="hilos-change-log-detail-close"
          onClick={requestClose}
        >
          {copy.close}
        </button>
      )}
    >
      <div data-id="hilos-change-log-detail">
        <div className="visually-hidden" role="status" aria-live="polite">
          {dialog?.busyInitial
            ? copy.loading
            : dialog?.busyMore
              ? copy.loadingMore
              : ''}
        </div>
        <HilosFormError
          message={dialog?.refusal ?? null}
          dataId="hilos-change-log-detail-error"
          announce
        />
        <div className="mb-2">
          <button
            type="button"
            className={`btn btn-sm btn-outline-secondary${dialog?.refusal ? '' : ' invisible'}`}
            disabled={!dialog?.refusal || dialog.busyInitial || dialog.busyMore}
            aria-hidden={!dialog?.refusal || undefined}
            tabIndex={dialog?.refusal ? 0 : -1}
            data-id="hilos-change-log-retry"
            onClick={() => void reader.retry()}
          >
            {copy.retry}
          </button>
        </div>
        {dialog?.busyInitial && (
          <p className="text-body-secondary">{copy.loading}</p>
        )}
        {attribution ? (
          <dl className="row mb-2">
            <dt className="col-sm-4">{copy.when}</dt>
            <dd className="col-sm-8" data-id="hilos-change-log-receipt-time">
              {attribution.when}
            </dd>
            <dt className="col-sm-4">{copy.who}</dt>
            <dd className="col-sm-8 text-break">
              {personLabel(attribution.actor)}
              {attribution.actor.explanation && (
                <div className="small text-body-secondary">
                  {attribution.actor.explanation}
                </div>
              )}
            </dd>
            {attribution.subject && (
              <>
                <dt className="col-sm-4">{copy.onBehalfOf}</dt>
                <dd className="col-sm-8 text-break">
                  {personLabel(attribution.subject)}
                  {attribution.subject.explanation && (
                    <div className="small text-body-secondary">
                      {attribution.subject.explanation}
                    </div>
                  )}
                  <div className="small text-body-secondary">
                    {copy.impersonation} {personLabel(attribution.actor)}
                  </div>
                </dd>
              </>
            )}
            <dt className="col-sm-4">{copy.channel}</dt>
            <dd className="col-sm-8">
              {attribution.channel ? (
                <span className="badge bg-body-secondary text-body border">
                  <i
                    className={`bi ${attribution.channel.icon} me-1`}
                    aria-hidden="true"
                  />
                  {attribution.channel.label}
                </span>
              ) : (
                copy.unknown
              )}
            </dd>
            <dt className="col-sm-4">{copy.source}</dt>
            <dd className="col-sm-8">
              <code className="text-break">{attribution.source}</code>
            </dd>
            <dt className="col-sm-4">{copy.action}</dt>
            <dd className="col-sm-8">
              <code className="text-break">{attribution.action}</code>
            </dd>
            <dt className="col-sm-4">{copy.agent}</dt>
            <dd className="col-sm-8">
              <code className="text-break">{attribution.agent}</code>
            </dd>
          </dl>
        ) : (
          dialog?.kind === 'entry' &&
          dialog.entries.length > 0 && (
            <dl className="row mb-2">
              <dt className="col-sm-4">{copy.when}</dt>
              <dd className="col-sm-8">
                {formatChangeLogTime(dialog.entries[0]!.createdAt)}
              </dd>
              <dt className="col-sm-4">{copy.who}</dt>
              <dd className="col-sm-8">
                {copy.unknownPerson}
                <div className="small text-body-secondary">
                  {copy.noReceipt}
                </div>
              </dd>
            </dl>
          )
        )}

        {entries.length > 0 && (
          <section>
            <h2
              ref={changesHeading}
              className="h6 mb-2"
              tabIndex={-1}
              data-id="hilos-change-log-changes-heading"
            >
              {copy.whatChanged}
            </h2>
            {entries.map((entry) => (
              <div
                key={entry.entryId}
                className="border rounded-2 p-2 mb-2"
                data-id={`hilos-change-log-entry-${entry.entryId}`}
              >
                <h3 className="h6 d-flex flex-wrap align-items-center gap-2 mb-2">
                  {entry.recordPath !== undefined ? (
                    <HilosLink
                      to={entry.recordPath}
                      data-id={`hilos-change-log-receipt-record-${entry.entryId}`}
                      onClick={() => reader.close()}
                    >
                      <code className="text-break">{entry.details.record}</code>
                    </HilosLink>
                  ) : (
                    <code className="text-break">{entry.details.record}</code>
                  )}
                  <span className="badge bg-body-secondary text-body border">
                    {entry.details.mutation}
                  </span>
                </h3>
                {entry.details.explanation && (
                  <p className="small text-body-secondary mb-1">
                    {entry.details.explanation}
                  </p>
                )}
                <ul className="list-unstyled mb-0 d-flex flex-column gap-3">
                  {entry.details.fields.map((field) => (
                    <li key={field.field}>
                      <code className="text-break">{field.field}</code>
                      {field.kind === 'fact' ? (
                        <span className="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1 text-wrap">
                          {field.oldValue.text}
                        </span>
                      ) : (
                        <div className="row g-2 mt-1">
                          <div
                            className={field.newValue ? 'col-sm-6' : 'col-12'}
                          >
                            <div className="small text-body-secondary mb-1">
                              {copy.oldValue}
                            </div>
                            <HilosLongText
                              kind="output"
                              text={field.oldValue.text}
                              dataId={`hilos-change-log-value-${entry.entryId}-${field.field}-old`}
                            />
                          </div>
                          {field.newValue && (
                            <div className="col-sm-6">
                              <div className="small text-body-secondary mb-1">
                                {copy.newValue}
                              </div>
                              <HilosLongText
                                kind="output"
                                text={field.newValue.text}
                                dataId={`hilos-change-log-value-${entry.entryId}-${field.field}-new`}
                              />
                            </div>
                          )}
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            ))}
            {pagination && (
              <div className="mt-3">
                <div
                  className="small text-body-secondary mb-2"
                  data-id="hilos-change-log-detail-count"
                >
                  {pagination.count}
                </div>
                <div className="d-flex flex-wrap gap-2">
                  {pagination.showMore && (
                    <button
                      type="button"
                      className="btn btn-sm btn-outline-secondary"
                      disabled={dialog?.busyMore}
                      aria-busy={dialog?.busyMore}
                      data-id="hilos-change-log-more"
                      onClick={() => void reader.more()}
                    >
                      {copy.showMore}
                    </button>
                  )}
                  {pagination.showHide && (
                    <button
                      type="button"
                      className="btn btn-sm btn-outline-secondary"
                      data-id="hilos-change-log-hide"
                      onClick={hide}
                    >
                      {copy.hide}
                    </button>
                  )}
                </div>
              </div>
            )}
          </section>
        )}
      </div>
    </HilosModal>
  )
}
