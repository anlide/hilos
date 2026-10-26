import {
  endableProfileSessionCount,
  formatProfileDateTime,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core/base'
import { useState } from 'react'

import { HilosFormError } from '../HilosFormError.js'
import { HilosModal } from '../HilosModal.js'
import { LoadingButton } from '../LoadingButton.js'
import { useTrackedAction } from '../useTrackedAction.js'

/** Props for {@link HilosProfileSessions}. */
export interface HilosProfileSessionsProps {
  readonly sessions: readonly HilosProfileSession[]
  readonly actions: HilosProfileSessionActions
}

/**
 * Display form of a tab key.
 *
 * Eight characters or fewer stay whole. A longer key keeps four characters
 * at each end. The list identity stays the full accept key.
 */
function tabKeyLabel(acceptKey: string): string {
  if (acceptKey.length <= 8) {
    return acceptKey
  }
  return `${acceptKey.slice(0, 4)}…${acceptKey.slice(-4)}`
}

/** The shared session list, tab disclosure, and end-session controls. */
export function HilosProfileSessions({
  sessions,
  actions,
}: HilosProfileSessionsProps) {
  const [expanded, setExpanded] = useState<ReadonlySet<number>>(new Set())
  const [endingSessionId, setEndingSessionId] = useState<number | null>(null)
  const [endOthersOpen, setEndOthersOpen] = useState(false)
  const endAction = useTrackedAction()
  const endOthersAction = useTrackedAction()
  const selectedSession = sessions.find(
    (session) => session.id === endingSessionId,
  )
  const endError =
    selectedSession === undefined
      ? 'This session has already ended'
      : endAction.error
  const endableCount = endableProfileSessionCount(sessions)
  const endOthersError =
    endableCount === 0
      ? 'Your other sessions have already ended'
      : endOthersAction.error

  function toggleTabs(sessionId: number): void {
    const next = new Set(expanded)
    if (next.has(sessionId)) {
      next.delete(sessionId)
    } else {
      next.add(sessionId)
    }
    setExpanded(next)
  }

  function openEndOthers(): void {
    endOthersAction.clearError()
    setEndOthersOpen(true)
  }

  async function confirmEnd(): Promise<void> {
    if (selectedSession === undefined) {
      return
    }
    if (await endAction.run(actions.endSession(selectedSession.id))) {
      setEndingSessionId(null)
    }
  }

  async function confirmEndOthers(): Promise<void> {
    if (endableCount === 0) {
      return
    }
    if (await endOthersAction.run(actions.endOtherSessions())) {
      setEndOthersOpen(false)
    }
  }

  return (
    <section aria-label="Sessions" data-id="profile-sessions">
      <p className="small text-body-secondary">
        {
          'A session is a browser that signed in. Its open tabs are listed inside it. Ending a session takes effect at once.'
        }
      </p>
      {sessions.length === 0 ? (
        <div className="text-body-secondary">No sessions.</div>
      ) : (
        <div>
          {sessions.map((session) => (
            <article
              key={session.id}
              className="border-bottom"
              data-id="profile-session-row"
            >
              <div className="d-flex flex-wrap align-items-center gap-2 py-3">
                {session.impersonated ? (
                  <i
                    className="bi bi-people-fill fs-5 text-warning-emphasis"
                    aria-hidden="true"
                  />
                ) : (
                  <i
                    className="bi bi-window fs-5 text-body-secondary"
                    aria-hidden="true"
                  />
                )}
                <div className="flex-grow-1 text-break">
                  <h3 className="h6 mb-1">
                    {`Session #${session.id}`}
                    {session.current ? (
                      <span
                        className="badge text-bg-success ms-1"
                        data-id="profile-session-this"
                      >
                        this one
                      </span>
                    ) : null}
                    {session.impersonated ? (
                      <span
                        className="badge bg-warning-subtle text-warning-emphasis ms-1"
                        data-id="profile-session-impersonated"
                      >
                        an administrator is working as you
                      </span>
                    ) : null}
                  </h3>
                  <div data-id="profile-session-device">
                    {session.deviceName ?? 'Unknown device'}
                  </div>
                  <div className="small text-body-secondary">
                    {`created ${formatProfileDateTime(session.createdAt)} · last seen ${
                      session.tabs.length > 0
                        ? 'now'
                        : formatProfileDateTime(session.lastSeenAt)
                    } · expires ${formatProfileDateTime(session.expiresAt)}`}
                  </div>
                </div>
                <button
                  type="button"
                  className="btn btn-link btn-sm p-0 text-decoration-none"
                  aria-expanded={expanded.has(session.id)}
                  data-id="profile-session-tabs"
                  onClick={() => toggleTabs(session.id)}
                >
                  <i
                    className={`bi ${
                      expanded.has(session.id)
                        ? 'bi-caret-down-fill'
                        : 'bi-caret-right-fill'
                    }`}
                    aria-hidden="true"
                  />
                  {`tabs: ${session.tabs.length}`}
                </button>
                {!session.current && !session.impersonated ? (
                  <button
                    type="button"
                    className="btn btn-outline-danger btn-sm"
                    data-id="profile-session-revoke"
                    onClick={() => {
                      endAction.clearError()
                      setEndingSessionId(session.id)
                    }}
                  >
                    Revoke
                  </button>
                ) : null}
              </div>
              {expanded.has(session.id) ? (
                <div className="bg-body-tertiary rounded-2 mb-2 px-2 py-1">
                  {session.tabs.length === 0 ? (
                    <p className="text-body-secondary small mb-0">
                      {
                        'No open tabs: the sign-in stays valid, but the browser is closed right now.'
                      }
                    </p>
                  ) : (
                    <ul className="list-unstyled mb-0">
                      {session.tabs.map((tab) => (
                        <li
                          key={tab.acceptKey}
                          className="d-flex flex-wrap align-items-center gap-2 py-1"
                          data-id="profile-session-tab"
                        >
                          <i
                            className="bi bi-plug text-body-secondary"
                            aria-hidden="true"
                          />
                          <code>{tabKeyLabel(tab.acceptKey)}</code>
                          <span className="small text-body-secondary">
                            {`opened ${formatProfileDateTime(tab.connectedAt)}`}
                          </span>
                          {/* A newline between the tags is dropped, so the
                              boundary is its own text node, and only while
                              this tab is shown. */}
                          {tab.current ? (
                            <>
                              {' '}
                              <span
                                className="small text-body-secondary"
                                data-id="profile-session-tab-this"
                              >
                                this tab
                              </span>
                            </>
                          ) : null}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              ) : null}
            </article>
          ))}
        </div>
      )}
      <button
        type="button"
        className="btn btn-outline-danger btn-sm mt-3"
        disabled={endableCount === 0}
        data-id="profile-sessions-end-others"
        onClick={openEndOthers}
      >
        Sign out everywhere else
      </button>

      <HilosModal
        open={endingSessionId !== null}
        title={`End session #${endingSessionId ?? ''}`}
        initialFocus="dialog"
        onClose={() => setEndingSessionId(null)}
        actions={(args) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={endAction.busy}
              onClick={args.requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={endAction.busy}
              disabled={selectedSession === undefined}
              data-id="profile-session-end-confirm"
              onClick={() => void confirmEnd()}
            >
              End
            </LoadingButton>
          </>
        )}
      >
        <p>
          Tabs of this session lose the account at once: where an account is
          needed, the sign-in form takes the place of the content.
        </p>
        <p>Your other sessions are not touched.</p>
        <HilosFormError
          message={endError}
          dataId="profile-session-end-error"
          announce
        />
      </HilosModal>

      <HilosModal
        open={endOthersOpen}
        title="Sign out everywhere else"
        initialFocus="dialog"
        onClose={() => setEndOthersOpen(false)}
        actions={(args) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={endOthersAction.busy}
              onClick={args.requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={endOthersAction.busy}
              disabled={endableCount === 0}
              data-id="profile-sessions-end-others-confirm"
              onClick={() => void confirmEndOthers()}
            >
              Sign out
            </LoadingButton>
          </>
        )}
      >
        <p>
          Other sessions that will end:{' '}
          <strong data-id="profile-sessions-end-others-count">
            {endableCount}
          </strong>
          .
        </p>
        <p>
          Their tabs lose the account at once: where an account is needed, the
          sign-in form takes the place of the content.
        </p>
        <p>This session stays signed in.</p>
        <HilosFormError
          message={endOthersError}
          dataId="profile-sessions-end-others-error"
          announce
        />
      </HilosModal>
    </section>
  )
}
