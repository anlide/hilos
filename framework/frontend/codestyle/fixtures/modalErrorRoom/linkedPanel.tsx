import { useTrackedAction } from '@hilos/react/base'

import { Reason as LinkedReason } from './linkedReason.js'

export function Panel() {
  const action = useTrackedAction()
  return <LinkedReason action={action} />
}
