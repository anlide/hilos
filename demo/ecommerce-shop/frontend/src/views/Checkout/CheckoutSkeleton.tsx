/** Placeholders while Checkout waits for its cart snapshot. */
export default function CheckoutSkeleton() {
  return (
    <>
      <h1 className="visually-hidden">Checkout</h1>
      <p className="visually-hidden" role="status" aria-live="polite">
        Loading checkout…
      </p>
      <div className="placeholder-glow" aria-hidden="true">
        <div className="h4 mb-3">
          <span className="placeholder col-3 col-lg-2" />
        </div>
        <div className="row g-4">
          <div className="col-lg-7">
            <div className="card">
              <div className="card-body">
                <span className="placeholder col-4" />
                <span className="placeholder col-10 d-block mt-2" />
              </div>
            </div>
          </div>
          <div className="col-lg-5">
            <div className="card">
              <div className="card-body">
                <span className="placeholder col-5" />
                <span className="placeholder col-12 d-block mt-2" />
                <span className="placeholder col-12 d-block mt-1" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  )
}
