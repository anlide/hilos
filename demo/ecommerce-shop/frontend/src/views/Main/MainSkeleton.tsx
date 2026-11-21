// The main page's skeleton: what the routed outlet draws while the home waits
// for its first answer. It stands in the home's own shape — the title and its
// line in the same classes, and six tiles of picture, name and description —
// so nothing moves when the page lands. The title is the page's own fixed
// words, but not a heading here: the page's one h1 arrives with the page.
// Decorative as a whole: the outlet announces the wait once, so the skeleton
// has no status of its own.
const PLACEHOLDER_TILES = 6

export default function MainSkeleton() {
  return (
    <div aria-hidden="true">
      <div className="h4 mb-1">Flowers and everything for them</div>
      <p className="text-body-secondary">
        Author bouquets, plants in their pots, and what they need to grow.
      </p>
      <div className="row row-cols-2 row-cols-md-3 g-3">
        {Array.from({ length: PLACEHOLDER_TILES }, (_, index) => (
          <div key={index} className="col">
            <div className="card h-100 placeholder-glow">
              <div className="ratio ratio-4x3 placeholder rounded-top" />
              <div className="card-body p-3">
                <span className="placeholder col-7 mb-2" />
                <span className="placeholder col-10" />
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
