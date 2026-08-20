// The Hilos users list page (HilosPages.USERS): a thin project binding of the
// framework HilosUsersPage to this app's context. The table, the row view-model,
// and search/sort/paging are the framework's; the project supplies the context and
// fills the trailing actions cell through the `rowActions` render prop — here, a
// link to the user detail page. Bootstrap classes only (styling-rules.md).
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import { HilosLink, HilosUsersPage } from '@hilos/react'

import { hilosUsersContext } from './hilosUsersContext'

/** The detail route for one user, from the framework page catalog. */
function userHref(id: number): string {
  return HILOS_PAGE_ROUTES[HilosPages.USER].replace('{userId}', String(id))
}

export default function Users() {
  return (
    <HilosUsersPage
      context={hilosUsersContext}
      rowActions={(row) => (
        <HilosLink
          to={userHref(row.id)}
          className="btn btn-sm btn-outline-primary"
          data-id={`hilos-users-open-${row.id}`}
        >
          Open
        </HilosLink>
      )}
    />
  )
}
