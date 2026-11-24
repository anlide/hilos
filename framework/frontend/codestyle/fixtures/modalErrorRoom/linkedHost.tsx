import * as SDK from '@hilos/react/base'
import { Panel as ModalPanel } from './linkedPanel.js'

const action = SDK.useTrackedAction()

function Outside() {
  return <p className="invisible">{action.error}</p>
}

function TableOnly() {
  return <p className="invisible">{action.error}</p>
}

function localActions() {
  return <ModalPanel />
}

const cells = {
  name: () => (
    <>
      <TableOnly />
      <SDK.HilosModal open title="Edit">
        <p className="invisible">{action.error}</p>
      </SDK.HilosModal>
    </>
  ),
}

export function LinkedHost() {
  const customBody = () => <ModalPanel />
  return (
    <>
      <ModalPanel />
      <Outside />
      <SDK.HilosModal
        open
        title="Edit"
        body={customBody}
        actions={localActions}
      />
      <SDK.HilosModal open title="Edit">
        <SDK.HilosViewportTable cells={cells} />
      </SDK.HilosModal>
    </>
  )
}

export function LocalModal() {
  function ErrorLine() {
    return <p className="invisible">{action.error}</p>
  }
  const Dialog = SDK.HilosModal
  return (
    <Dialog open title="Edit">
      <ErrorLine />
    </Dialog>
  )
}

export function Unrelated() {
  const SDK = {
    HilosModal: ({ children }: { children: React.ReactNode }) => children,
  }
  return (
    <SDK.HilosModal>
      <p className="invisible">{action.error}</p>
    </SDK.HilosModal>
  )
}
