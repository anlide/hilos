import { useEffect, useMemo, useState } from 'react'
import { HilosPages } from '@hilos/core/base'
import {
  createHilosChannelFrame,
  createHilosChannelFrameEdit,
  createHilosCommunicationsActions,
  LETTER_FRAME_FIELD,
  type HilosCommunicationsContext,
} from '@hilos/core/admin/communications'

import { ConflictActions } from '../../ConflictActions.js'
import { ConflictHeader } from '../../ConflictHeader.js'
import { HilosActionError } from '../../HilosActionError.js'
import { HilosAdminPage } from '../../HilosAdminPage.js'
import { HilosEditNotice } from '../../HilosEditNotice.js'
import { HilosHiddenMark } from '../../HilosHiddenMark.js'
import { HilosHideable } from '../../HilosHideable.js'
import { HilosModal } from '../../HilosModal.js'
import { LoadingButton } from '../../LoadingButton.js'
import { useSignal } from '../../useSignal.js'
import { useTrackedAction } from '../../useTrackedAction.js'
import { useHilosCommunicationsChannelHead } from './hilosCommunicationsChannelHead.js'

/** The project supplies the connection, scopes and tracked action lifecycle. */
export interface HilosCommunicationsChannelFramePageProps {
  context: HilosCommunicationsContext
}

/** A server-rendered preview and the shared frame edit session. */
export function HilosCommunicationsChannelFramePage({
  context,
}: HilosCommunicationsChannelFramePageProps) {
  const [binding, setBinding] = useState({ context, revision: 0 })
  if (binding.context !== context)
    setBinding({ context, revision: binding.revision + 1 })
  return <ChannelFrame key={binding.revision} context={context} />
}

function ChannelFrame({ context }: HilosCommunicationsChannelFramePageProps) {
  const { headProps } = useHilosCommunicationsChannelHead(context)
  const source = useMemo(() => createHilosChannelFrame(context), [context])
  const frame = useSignal(source)
  const actions = useMemo(
    () => createHilosCommunicationsActions(context),
    [context],
  )
  const editor = useMemo(
    () => createHilosChannelFrameEdit(source, actions),
    [source, actions],
  )
  useEffect(() => {
    editor.start()
    return editor.dispose
  }, [editor])
  const editOpen = useSignal(editor.opened)
  const form = useSignal(editor.form)
  const live = useSignal(editor.state)
  const notice = useSignal(editor.noticeText)
  const saveLabel = useSignal(editor.saveLabel)
  const canSave = useSignal(editor.saveAvailable)
  const saving = useSignal(editor.saving)
  const edit = useTrackedAction()
  const reset = useTrackedAction()
  const [resetOpen, setResetOpen] = useState(false)
  const resetGone = frame?.source !== 'settings'
  const sourceLabel = frame
    ? { settings: 'Override', project: 'Project', default: 'Default' }[
        frame.source
      ]
    : ''
  function openEdit(): void {
    edit.clearError()
    editor.open()
  }
  function submitEdit(): void {
    void editor.save(edit.run)
  }
  async function submitReset(): Promise<void> {
    if (reset.busy || resetGone) return
    if (await reset.run(actions.sendChannelReset('email', LETTER_FRAME_FIELD)))
      setResetOpen(false)
  }
  return (
    <HilosAdminPage
      page={HilosPages.COMMUNICATIONS_CHANNEL_FRAME}
      {...headProps}
    >
      {frame ? (
        <section className="card" data-id="hilos-channel-frame">
          <div className="card-header d-flex flex-wrap align-items-center gap-2">
            <div className="flex-grow-1">
              <h2 className="h6 mb-1">Letter frame</h2>
              <div className="small text-body-secondary">
                Header, footer and styles around every letter
              </div>
            </div>
            <span
              className="badge text-bg-secondary-subtle text-secondary-emphasis"
              data-id="hilos-channel-frame-source"
            >
              {sourceLabel}
            </span>
            <div className="d-flex gap-1">
              <button
                type="button"
                className="btn btn-sm btn-outline-primary"
                title="Edit frame"
                aria-label="Edit frame"
                data-id="hilos-channel-frame-edit"
                onClick={openEdit}
              >
                <i className="bi bi-pencil" aria-hidden="true" />
              </button>
              <button
                type="button"
                className="btn btn-sm btn-outline-secondary"
                title="Reset frame"
                aria-label="Reset frame"
                disabled={resetGone}
                data-id="hilos-channel-frame-reset"
                onClick={() => {
                  reset.clearError()
                  setResetOpen(true)
                }}
              >
                <i
                  className="bi bi-arrow-counterclockwise"
                  aria-hidden="true"
                />
              </button>
            </div>
          </div>
          <div className="card-body">
            <div className="rounded-3 p-3 p-sm-4 bg-body-tertiary">
              <HilosHideable value={frame.previewHtml}>
                {(value) => (
                  <iframe
                    className="w-100 border rounded-3 bg-body"
                    sandbox=""
                    title="Letter frame preview"
                    srcDoc={value}
                    data-id="hilos-channel-frame-preview"
                  />
                )}
              </HilosHideable>
            </div>
            <div className="small text-body-secondary mt-2">
              Preview with <code>auth.password_reset</code> in English. Plain
              text goes without the frame.
            </div>
          </div>
        </section>
      ) : null}
      <HilosModal
        open={editOpen}
        size="wide"
        aria-label="Edit · Letter frame"
        copyText={form.html}
        confirmOnClose={live.dirty}
        busy={saving}
        onClose={() => editor.close()}
        header={
          <ConflictHeader
            title="Edit · Letter frame"
            conflict={live.conflict}
          />
        }
        actions={({ requestClose }) => (
          <ConflictActions
            conflict={live.conflict}
            disableSave={!canSave}
            saveLabel={saveLabel}
            onSave={submitEdit}
            onAcceptMine={() => editor.keepMine()}
            onAcceptTheirs={() => editor.takeTheirs()}
            cancelButton={
              <button
                type="button"
                className="btn btn-secondary"
                disabled={saving}
                data-id="hilos-channel-frame-edit-cancel"
                onClick={requestClose}
              >
                Cancel
              </button>
            }
            saveButton={({ disabled, onSave }) => (
              <LoadingButton
                className="btn-primary"
                loading={edit.busy}
                disabled={disabled}
                data-id="hilos-channel-frame-save"
                onClick={onSave}
              >
                {saveLabel}
              </LoadingButton>
            )}
          />
        )}
      >
        <HilosActionError
          action={edit}
          detailsTitle="Couldn't save the frame"
        />
        <form
          onSubmit={(event) => {
            event.preventDefault()
            submitEdit()
          }}
        >
          <label className="form-label" htmlFor="hilos-channel-frame-html">
            Frame HTML
          </label>
          {frame && typeof frame.html !== 'string' ? (
            <HilosHiddenMark />
          ) : (
            <textarea
              id="hilos-channel-frame-html"
              className="form-control font-monospace small"
              rows={14}
              disabled={saving}
              data-id="hilos-channel-frame-html"
              data-autofocus
              value={form.html}
              onChange={(event) =>
                editor.patchForm({ html: event.target.value })
              }
            />
          )}
          <HilosEditNotice
            kind={live.notice?.kind ?? null}
            text={notice}
            dataId="hilos-channel-frame-notice"
          />
          {live.conflict ? (
            <div className="mt-2" data-id="hilos-channel-frame-incoming">
              <div className="small text-body-secondary">Changed elsewhere</div>
              <HilosHideable value={live.fields.html.incoming}>
                {(value) => (
                  <pre className="small text-wrap text-break">{value}</pre>
                )}
              </HilosHideable>
            </div>
          ) : null}
          <div className="small mt-2">
            <div className="text-body-secondary mb-1">Placeholders</div>
            <ul className="list-unstyled mb-0">
              <li>
                <code>[[:content]]</code> — the letter itself — required, once
              </li>
              <li>
                <code>[[:lang]]</code> — reader's language
              </li>
              <li>
                <code>[[:dir]]</code> — ltr or rtl, by the reader's language
              </li>
            </ul>
            <div className="form-text">
              Any other name, or no <code>[[:content]]</code>, is refused on
              save. The frame carries no words of its own: every sentence
              belongs to a template.
            </div>
          </div>
        </form>
      </HilosModal>
      <HilosModal
        open={resetOpen}
        title="Reset · Letter frame"
        busy={reset.busy}
        initialFocus="dialog"
        onClose={() => setResetOpen(false)}
        actions={({ requestClose }) => (
          <>
            <button
              type="button"
              className="btn btn-secondary"
              disabled={reset.busy}
              onClick={requestClose}
            >
              Cancel
            </button>
            <LoadingButton
              className="btn-danger"
              loading={reset.busy}
              disabled={resetGone}
              data-id="hilos-channel-frame-reset-confirm"
              onClick={() => void submitReset()}
            >
              Reset
            </LoadingButton>
          </>
        )}
      >
        <HilosActionError
          action={reset}
          detailsTitle="Couldn't reset the frame"
        />
        <dl className="row mb-0">
          <dt className="col-4">Now</dt>
          <dd
            className="col-8 text-break"
            data-id="hilos-channel-frame-reset-now"
          >
            <span className="badge text-bg-secondary-subtle text-secondary-emphasis">
              {sourceLabel}
            </span>
          </dd>
          <dt className="col-4">Back to</dt>
          <dd
            className="col-8 mb-0"
            data-id="hilos-channel-frame-reset-back-to"
          >
            the project frame, or the framework frame when the project has none
          </dd>
        </dl>
        <HilosEditNotice
          kind={resetGone ? 'updated' : null}
          text="Already reset elsewhere."
          dataId="hilos-channel-frame-reset-state"
        />
      </HilosModal>
    </HilosAdminPage>
  )
}
