// Modal layer depth for modals opened over modals: records each open modal
// instance with the document it lives in and hands the next one a depth one
// above the deepest layer still open there, so a layer knows its place without
// the surface that opens it passing anything. The depth is the deepest live
// layer plus one rather than the count of live layers: the lower modal may
// close first, and a count would then give two open layers the same number.
// Leaving without a matching entry is a no-op, including where no browser
// document exists. A core/dom helper (browser-only, plain DOM, no framework)
// keeps this rule out of the three views (multiframework-core.md), next to the
// scroll lock it is taken beside.

/** The identity of one component instance standing as a modal layer. */
export type ModalLayerOwner = object

const layerOwners = new Map<ModalLayerOwner, { doc: Document; depth: number }>()

/**
 * Register one owner as an open modal layer and return its depth.
 *
 * The first layer of a document gets 0; each next one gets the deepest layer
 * still open in the same document plus one. An owner that is already
 * registered keeps its depth.
 *
 * @param doc The document the modal is drawn into.
 * @param owner The component instance opening its layer.
 * @returns The depth of the owner's layer.
 */
export function enterModalLayer(doc: Document, owner: ModalLayerOwner): number {
  const registered = layerOwners.get(owner)
  if (registered !== undefined) {
    return registered.depth
  }
  let depth = 0
  for (const layer of layerOwners.values()) {
    if (layer.doc === doc) {
      depth = Math.max(depth, layer.depth + 1)
    }
  }
  layerOwners.set(owner, { doc, depth })
  return depth
}

/**
 * Remove the modal layer held by one owner.
 *
 * An owner that holds no layer touches nothing, which makes cleanup safe in
 * both closed nested modals and a server render.
 *
 * @param owner The component instance leaving its layer.
 */
export function leaveModalLayer(owner: ModalLayerOwner): void {
  layerOwners.delete(owner)
}
