import { HilosModal, useTrackedAction } from '@hilos/react/base'

export function CategoryDialog({ hasProducts }: { hasProducts: boolean }) {
  const deletion = useTrackedAction()
  const message = 'Delete the products before deleting this category.'
  return (
    <HilosModal>
      <div>
        {hasProducts ? (
          <p>{message}</p>
        ) : (
          <p className="invisible" aria-hidden="true">
            {message}
          </p>
        )}
        {deletion.error && <p>{message}</p>}
      </div>
      <button type="button" disabled={hasProducts}>
        Delete category
      </button>
    </HilosModal>
  )
}
