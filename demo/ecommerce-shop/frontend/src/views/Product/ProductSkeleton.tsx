/** Decorative placeholders for the product photo, heading, and details. */
export default function ProductSkeleton() {
  return (
    <div className="placeholder-glow" aria-hidden="true">
      <div className="small mb-2">
        <span className="placeholder col-6 col-md-3" />
      </div>
      <div className="row g-4">
        <div className="col-md-5">
          <div className="ratio ratio-4x3 placeholder rounded" />
        </div>
        <div className="col-md-7">
          <div className="h4 mb-1">
            <span className="placeholder col-8" />
          </div>
          <div className="d-flex gap-2 mb-3">
            <span className="placeholder rounded-pill col-3" />
            <span className="placeholder rounded-pill col-3" />
          </div>
          <div className="h4 mb-3">
            <span className="placeholder col-3" />
          </div>
          <p>
            <span className="placeholder col-10" />
          </p>
        </div>
      </div>
    </div>
  )
}
