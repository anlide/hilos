// The shop's home page (PAGE_MAIN): the categories as tiles in the
// administrators' order, each with its picture, description and how many of
// its products stand on the shop front, and a tile leads to its category's
// page. The tiles and numbers follow the page lists live; with no category yet
// the page says the shop is being set up. Rendered by HilosView when the
// navigator's route is the main page.
//
// Beside the content the page keeps hidden marks of who is looking. A visitor
// is not a user: with an account the marks name the account from the session
// scope, without one a single mark says the visit is anonymous.
import { HilosLink, useSignal } from '@hilos/react/base'

import { currentUserId, currentUserName } from '../../bootstrap/session.js'
import { productCountLabel } from './homeTiles.js'
import { homeTiles } from './mainPage.js'

export default function Main() {
  const selfName = useSignal(currentUserName)
  const selfId = useSignal(currentUserId)
  const tiles = useSignal(homeTiles)
  // What decides the branch is whether the session names a user, never whether
  // a name string came out empty: the id and the name are read off one
  // session-scope ref, so an absent ref gives a null id and an empty name together.
  return (
    <>
      <h1 className="h4 mb-1">Flowers and everything for them</h1>
      <p className="text-body-secondary">
        Author bouquets, plants in their pots, and what they need to grow.
      </p>
      {selfId !== null ? (
        <>
          <span className="visually-hidden" data-id="self-user">
            {selfName}
          </span>
          <span data-id="self-user-id" hidden>
            {selfId}
          </span>
        </>
      ) : (
        <span className="visually-hidden" data-id="self-anonymous">
          Browsing anonymously
        </span>
      )}
      {tiles.length === 0 ? (
        <div data-id="shop-home-empty" className="text-center py-5">
          <i
            className="bi bi-flower1 fs-1 text-body-secondary d-block mb-2"
            aria-hidden="true"
          />
          <div className="fw-semibold small mb-1">The shop is being set up</div>
          <p className="small text-body-secondary mb-0">
            Categories will appear here as soon as the shop adds them.
          </p>
        </div>
      ) : (
        <div className="row row-cols-2 row-cols-md-3 g-3">
          {tiles.map((tile) => (
            <div key={tile.key} className="col">
              <HilosLink
                to={`/category/${tile.slug}`}
                data-id="shop-home-tile"
                className="card h-100 border text-decoration-none link-body-emphasis"
              >
                <div className="ratio ratio-4x3">
                  <img
                    src={tile.picture}
                    alt=""
                    className="object-fit-cover rounded-top"
                  />
                </div>
                <div className="card-body p-3">
                  <div className="fw-semibold mb-1">{tile.name}</div>
                  <div className="small text-body-secondary mb-1">
                    {tile.description}
                  </div>
                  <div className="small text-body-secondary">
                    {productCountLabel(tile.productCount)}
                  </div>
                </div>
              </HilosLink>
            </div>
          ))}
        </div>
      )}
    </>
  )
}
