// Mount the framework Change Log page with the project context.
import { HilosChangeLogFeedPage } from '@hilos/react/admin/change-log'

import { hilosChangeLogContext } from './hilosChangeLogContext.js'

export default function ChangeLogFeed() {
  return <HilosChangeLogFeedPage context={hilosChangeLogContext} />
}
