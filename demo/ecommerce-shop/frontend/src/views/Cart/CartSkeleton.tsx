/** Placeholders for the cart's heading, items, and summary. */
export default function CartSkeleton() {
  return (
    <>
      <h1 className="visually-hidden">Cart</h1>
      <div
        className="placeholder-glow"
        aria-hidden="true"
        data-id="shop-cart-skeleton"
      >
        <div className="h4 mb-3">
          <span className="placeholder col-2 col-lg-1" />
        </div>
        <div className="row g-4">
          <div className="col-lg-8">
            <div className="d-flex align-items-center gap-3 py-3 border-bottom">
              <div className="col-2">
                <div className="ratio ratio-4x3 placeholder rounded" />
              </div>
              <div className="flex-grow-1">
                <span className="placeholder col-8" />
                <span className="placeholder col-4 d-block mt-2" />
              </div>
              <span className="placeholder col-2" />
            </div>
          </div>
          <div className="col-lg-4">
            <div className="card">
              <div className="card-body">
                <span className="placeholder col-12 mb-1" />
                <span className="placeholder col-12" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  )
}
