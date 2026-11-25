import { PRODUCT_COUNT_VARIANTS } from './productCountVariants.js'

interface ProductViewerCountProps {
  /** The last viewer count, when the current card has one. */
  readonly count: number | undefined
  /** The shop excludes its own viewer. The admin Overview shows the total. */
  readonly surface: 'shop' | 'admin'
  /** Whether the surface currently shows the reading. */
  readonly visible?: boolean
}

function viewerCountLabel(
  count: number,
  surface: ProductViewerCountProps['surface'],
) {
  const shop = surface === 'shop'
  if (count === 0) {
    return `${shop ? 'Nobody else' : 'Nobody'} is looking at it now`
  }
  const singular = shop ? 'other is' : 'person is'
  const plural = shop ? 'others are' : 'people are'
  return (
    <>
      <span className="font-monospace">{count}</span>{' '}
      {count === 1 ? singular : plural} looking at it now
    </>
  )
}

function ViewerIcon() {
  return <i className="bi bi-eye me-1 text-body-secondary" aria-hidden="true" />
}

/** A live viewer count with the wording of the surface that shows it. */
export function ProductViewerCount({
  count,
  surface,
  visible = true,
}: ProductViewerCountProps) {
  const label =
    visible && count !== undefined ? viewerCountLabel(count, surface) : null

  return (
    <>
      <span className="visually-hidden" role="status" aria-live="polite">
        {label}
      </span>
      {visible && (
        <span className="hilos-stack mw-100 text-break">
          {PRODUCT_COUNT_VARIANTS.map((variant) => (
            <span key={variant} className="invisible" aria-hidden="true" inert>
              <ViewerIcon />
              {viewerCountLabel(variant, surface)}
            </span>
          ))}
          <span data-id="product-viewer-count" aria-hidden="true">
            {label !== null && <ViewerIcon />}
            {label ?? '—'}
          </span>
        </span>
      )}
    </>
  )
}
