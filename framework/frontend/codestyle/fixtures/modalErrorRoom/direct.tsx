import {
  HilosActionError,
  type TrackedAction,
  HilosFormError as SharedError,
  HilosModal as LocalDialog,
} from '@hilos/react/base'

export function Direct({
  action,
  refusal,
}: {
  action: TrackedAction
  refusal: string
}) {
  const denied = action.error !== null
  return (
    <>
      <LocalDialog
        initialFocus="dialog"
        actions={() =>
          denied ? (
            <p>Changes could not be saved.</p>
          ) : (
            <div style={{ minHeight: 32 }} aria-hidden="true" />
          )
        }
        body={() => {
          return (
            <div style={{ visibility: denied ? 'visible' : 'hidden' }}>
              {refusal}
            </div>
          )
        }}
      />
      <LocalDialog initialFocus="dialog">
        <div data-id="save-refusal-slot">
          <div
            className={`opacity-0 ${denied ? 'small' : 'm-0'}`}
            data-id="save-refusal-twin"
            aria-hidden="true"
          >
            &nbsp;
          </div>
          {denied && <div data-id="save-refusal">{refusal}</div>}
        </div>

        <div className="invisible" data-id="shared-error-wrapper">
          Save error
          <SharedError message={refusal} />
        </div>
        <HilosActionError />
        <div role="status" className="invisible">
          Waiting for a response.
        </div>
        {denied && <p>You cannot continue.</p>}
        {/* <p className="invisible">Changes could not be saved.</p> */}
        {'<p className="invisible">Changes could not be saved.</p>'}
      </LocalDialog>
      <p className="invisible alert-danger">{action.error}</p>
    </>
  )
}
