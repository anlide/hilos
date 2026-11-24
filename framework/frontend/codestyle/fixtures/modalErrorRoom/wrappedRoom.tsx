import { HilosModal, useTrackedAction } from '@hilos/react/base'

const twinClass = 'invisible'

export function Editor() {
  const action = useTrackedAction()
  return (
    <HilosModal>
      {action.error ? (
        <div>
          <p>{action.error}</p>
        </div>
      ) : (
        <div>
          <p className={twinClass} aria-hidden="true">
            Save failed.
          </p>
        </div>
      )}
    </HilosModal>
  )
}
