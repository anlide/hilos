interface ProductViewerCountProps {
  /** The last viewer count, when the current card has one. */
  readonly count: number | undefined
  /** The shop excludes its own viewer. The admin Overview shows the total. */
  readonly surface: 'shop' | 'admin'
  /** Whether the surface currently shows the reading. */
  readonly visible?: boolean
}

function viewerCountText(
  count: number,
  surface: ProductViewerCountProps['surface'],
): string {
  const shop = surface === 'shop'
  if (count === 0) {
    return `${shop ? 'Nobody else' : 'Nobody'} is looking at it now`
  }
  if (count === 1) {
    return `1 ${shop ? 'other' : 'person'} is looking at it now`
  }
  return `${count} ${shop ? 'others' : 'people'} are looking at it now`
}

/** A live viewer count with the wording of the surface that shows it. */
export function ProductViewerCount({
  count,
  surface,
  visible = true,
}: ProductViewerCountProps) {
  const text =
    visible && count !== undefined ? viewerCountText(count, surface) : ''
  const reading = (
    <span data-id="product-viewer-count" aria-hidden="true">
      {text && (
        <i className="bi bi-eye me-1 text-body-secondary" aria-hidden="true" />
      )}
      {text || '—'}
    </span>
  )

  return (
    <>
      <span className="visually-hidden" role="status" aria-live="polite">
        {text}
      </span>
      {visible &&
        (surface === 'shop' ? (
          <div className="border rounded p-2 mb-3 small">{reading}</div>
        ) : (
          reading
        ))}
    </>
  )
}
