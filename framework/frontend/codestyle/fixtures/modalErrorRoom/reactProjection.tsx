import type { ReactNode } from 'react'
import {
  HilosModal,
  HilosViewportTable,
  useTrackedAction,
  type HilosModalProps,
} from '@hilos/react/base'
function Frame({
  children,
  actions,
}: {
  children: ReactNode
  actions: HilosModalProps['actions']
}) {
  return <HilosModal actions={actions}>{children}</HilosModal>
}

function TableFrame({ children }: { children: ReactNode }) {
  return (
    <HilosModal>
      <HilosViewportTable cells={{ name: () => children }} />
    </HilosModal>
  )
}

export function Host() {
  const action = useTrackedAction()
  return (
    <>
      <p className="invisible">{action.error}</p>
      <TableFrame>
        <p className="invisible">{action.error}</p>
      </TableFrame>
      <Frame actions={() => <p className="invisible">{action.error}</p>}>
        <p className="invisible">{action.error}</p>
      </Frame>
    </>
  )
}
