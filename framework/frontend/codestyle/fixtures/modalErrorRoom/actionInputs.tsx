import {
  HilosModal,
  useTrackedAction as useSave,
  type TrackedAction,
} from '@hilos/react/base'

function Message({ operation }: { operation: TrackedAction }) {
  return operation.error ? (
    <p>{operation.error}</p>
  ) : (
    <p className="invisible" aria-hidden="true">
      Save failed.
    </p>
  )
}

export function Dialog({
  warehouse,
}: {
  warehouse: { moveAction: { error: string } }
}) {
  const save = useSave()
  const deletion = useSave()
  return (
    <>
      <Message operation={save} />
      <HilosModal>
        <Message operation={save} />
        <p className="invisible">{warehouse.moveAction.error}</p>
        {deletion.error ? (
          <p>{deletion.error}</p>
        ) : (
          <div>
            <p className="invisible" aria-hidden="true">
              Delete failed.
            </p>
          </div>
        )}
      </HilosModal>
    </>
  )
}
