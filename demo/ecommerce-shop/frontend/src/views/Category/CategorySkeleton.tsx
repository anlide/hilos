const PLACEHOLDER_TILES = 4

/** Decorative placeholders for the category heading and initial tile view. */
export default function CategorySkeleton() {
  return (
    <div className="placeholder-glow" aria-hidden="true">
      <div className="small mb-2">
        <span className="placeholder col-4 col-md-2" />
      </div>
      <div className="h4 mb-1">
        <span className="placeholder col-7 col-md-4" />
      </div>
      <p>
        <span className="placeholder col-10 col-md-6" />
      </p>
      <div className="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-3">
        {Array.from({ length: PLACEHOLDER_TILES }, (_, index) => (
          <div key={index} className="col">
            <div className="card h-100">
              <div className="ratio ratio-4x3 placeholder rounded-top" />
              <div className="card-body p-3">
                <span className="placeholder col-8" />
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
