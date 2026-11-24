import { HilosModal, useTrackedAction } from '@hilos/react/base'

export function Editor() {
  const action = useTrackedAction()
  const message = 'Save failed.'
  return (
    <HilosModal>
      <div className="position-relative">
        <p className="invisible" aria-hidden="true">
          {message}
        </p>
        {action.error && (
          <p className="position-absolute top-0 start-0">{message}</p>
        )}
        {!action.error && (
          <p className="position-absolute top-0 end-0" role="status">
            Autosave ready.
          </p>
        )}
      </div>
    </HilosModal>
  )
}
