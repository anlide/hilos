import {
  endableProfileSessionCount,
  formatProfileDateTime,
  type HilosProfileSession,
  type HilosProfileSessionActions,
} from '@hilos/core'
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

/** The shared session list, tab disclosure, and end-session controls. */
export function HilosProfileSessions({
  sessions,
  actions,
}: HilosProfileSessionsProps) {
  const [expanded, setExpanded] = useState<ReadonlySet<number>>(new Set())
  const [endingSessionId, setEndingSessionId] = useState<number | null>(null)
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

  function toggleTabs(sessionId: number): void {
    const next = new Set(expanded)
    if (next.has(sessionId)) {
      next.delete(sessionId)
    } else {
      next.add(sessionId)
    }
    setExpanded(next)
  }

  async function confirmEnd(): Promise<void> {
    if (selectedSession === undefined) {
      return
    }
    if (await endAction.run(actions.endSession(selectedSession.id))) {
      setEndingSessionId(null)
    }
  }

  async function endOthers(): Promise<void> {
    if (endableCount > 0) {
      await endOthersAction.run(actions.endOtherSessions())
    }
  }

  return (
    <section aria-label="Sessions" data-id="profile-sessions">
      <p>
        A session is a browser that signed in. Its open tabs are listed inside
        it. Ending a session takes effect at once.
      </p>
      <div className="alert alert-info small" role="note">
        The list of tabs is what is happening right now and can lag behind in a
        cluster. The list of sessions cannot: it comes from the database.
      </div>
      {sessions.length === 0 ? (
        <div className="text-body-secondary">No sessions.</div>
      ) : (
        <div className="d-flex flex-column gap-3">
          {sessions.map((session) => (
            <article
              key={session.id}
              className="card"
              data-id="profile-session-row"
            >
              <div className="card-body">
                <div className="d-flex flex-wrap align-items-start justify-content-between gap-2">
                  <div>
                    <h3 className="h6 mb-1">Session #{session.id}</h3>
                    <div data-id="profile-session-device">
                      {session.deviceName ?? 'Unknown device'}
                    </div>
                    {session.current ? (
                      <span
                        className="badge text-bg-primary me-1"
                        data-id="profile-session-this"
                      >
                        this one
                      </span>
                    ) : null}
                    {session.impersonated ? (
                      <span
                        className="badge text-bg-warning"
                        data-id="profile-session-impersonated"
                      >
                        an administrator is working as you
                      </span>
                    ) : null}
                  </div>
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
                <div className="small text-body-secondary mt-2">
                  created {formatProfileDateTime(session.createdAt)} · last seen{' '}
                  {session.tabs.length > 0
                    ? 'now'
                    : formatProfileDateTime(session.lastSeenAt)}{' '}
                  · expires {formatProfileDateTime(session.expiresAt)}
                </div>
                <button
                  type="button"
                  className="btn btn-link btn-sm px-0 mt-2"
                  aria-expanded={expanded.has(session.id)}
                  data-id="profile-session-tabs"
                  onClick={() => toggleTabs(session.id)}
                >
                  tabs: {session.tabs.length}
                </button>
                {expanded.has(session.id) ? (
                  <div className="mt-2">
                    {session.tabs.length === 0 ? (
                      <p className="text-body-secondary small mb-0">
                        No open tabs: the sign-in stays valid, but the browser
                        is closed right now.
                      </p>
                    ) : (
                      <ul className="list-group list-group-flush">
                        {session.tabs.map((tab) => (
                          <li
                            key={tab.acceptKey}
                            className="list-group-item px-0 d-flex flex-wrap justify-content-between gap-2"
                            data-id="profile-session-tab"
                          >
                            <code>{tab.acceptKey.slice(0, 8)}</code>
                            <span className="small text-body-secondary">
                              opened{' '}
                              {formatProfileDateTime(tab.connectedAt * 1000)}
                              {tab.current ? (
                                <span
                                  className="badge text-bg-primary ms-1"
                                  data-id="profile-session-tab-this"
                                >
                                  this tab
                                </span>
                              ) : null}
                            </span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      )}
      <LoadingButton
        className="btn-outline-danger mt-3"
        loading={endOthersAction.loading}
        disabled={endableCount === 0 || endOthersAction.busy}
        data-id="profile-sessions-end-others"
        onClick={() => void endOthers()}
      >
        Sign out everywhere else
      </LoadingButton>

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
              loading={endAction.loading}
              disabled={selectedSession === undefined || endAction.busy}
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
        <HilosFormError message={endError} dataId="profile-session-end-error" />
      </HilosModal>
    </section>
  )
}
