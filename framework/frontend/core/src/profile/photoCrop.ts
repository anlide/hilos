/** Minimum magnification: the shorter image side exactly covers the circle. */
export const HILOS_PHOTO_MIN_ZOOM = 1
/** Maximum magnification the crop slider permits. */
export const HILOS_PHOTO_MAX_ZOOM = 4
/** Square JPEG side sent to the upload target, in pixels. */
export const HILOS_PHOTO_OUTPUT_SIDE = 512
/** JPEG quality of the square produced in the browser. */
export const HILOS_PHOTO_JPEG_QUALITY = 0.9
/** Largest file the browser opens for cropping, in bytes. */
export const HILOS_PHOTO_PICK_MAX_BYTES = 41_943_040

/** Crop center in source pixels and magnification relative to cover. */
export interface HilosPhotoCrop {
  readonly zoom: number
  readonly centerX: number
  readonly centerY: number
}

/** A square region of the source bitmap, in its own pixel coordinates. */
export interface HilosPhotoSourceSquare {
  readonly sx: number
  readonly sy: number
  readonly side: number
}

/**
 * Keep the requested square entirely inside the bitmap.
 *
 * @param width Bitmap width in pixels.
 * @param height Bitmap height in pixels.
 * @param crop Requested zoom and source-pixel center.
 */
export function clampHilosPhotoCrop(
  width: number,
  height: number,
  crop: HilosPhotoCrop,
): HilosPhotoCrop {
  if (
    !Number.isFinite(width) ||
    !Number.isFinite(height) ||
    width <= 0 ||
    height <= 0
  ) {
    throw new RangeError('Photo dimensions must be positive')
  }
  const zoom = Number.isFinite(crop.zoom)
    ? Math.min(HILOS_PHOTO_MAX_ZOOM, Math.max(HILOS_PHOTO_MIN_ZOOM, crop.zoom))
    : HILOS_PHOTO_MIN_ZOOM
  const half = Math.min(width, height) / zoom / 2
  const centerX = Number.isFinite(crop.centerX) ? crop.centerX : width / 2
  const centerY = Number.isFinite(crop.centerY) ? crop.centerY : height / 2

  return {
    zoom,
    centerX: Math.min(width - half, Math.max(half, centerX)),
    centerY: Math.min(height - half, Math.max(half, centerY)),
  }
}

/**
 * Describe the square to extract from a bitmap for preview and export.
 *
 * @param width Bitmap width in pixels.
 * @param height Bitmap height in pixels.
 * @param crop Requested zoom and source-pixel center.
 */
export function hilosPhotoSourceSquare(
  width: number,
  height: number,
  crop: HilosPhotoCrop,
): HilosPhotoSourceSquare {
  const bounded = clampHilosPhotoCrop(width, height, crop)
  const side = Math.min(width, height) / bounded.zoom

  return {
    sx: bounded.centerX - side / 2,
    sy: bounded.centerY - side / 2,
    side,
  }
}

/**
 * Move the picture under the fixed circle, clamping at the bitmap edges.
 *
 * @param width Bitmap width in pixels.
 * @param height Bitmap height in pixels.
 * @param crop Current crop.
 * @param dxPx Picture movement to the right in preview pixels.
 * @param dyPx Picture movement downward in preview pixels.
 * @param viewSidePx Preview circle diameter in pixels.
 */
export function moveHilosPhotoCrop(
  width: number,
  height: number,
  crop: HilosPhotoCrop,
  dxPx: number,
  dyPx: number,
  viewSidePx: number,
): HilosPhotoCrop {
  if (!Number.isFinite(viewSidePx) || viewSidePx <= 0) {
    throw new RangeError('Photo preview size must be positive')
  }
  const bounded = clampHilosPhotoCrop(width, height, crop)
  const sourcePerPreviewPx = Math.min(width, height) / bounded.zoom / viewSidePx

  return clampHilosPhotoCrop(width, height, {
    zoom: bounded.zoom,
    centerX: bounded.centerX - dxPx * sourcePerPreviewPx,
    centerY: bounded.centerY - dyPx * sourcePerPreviewPx,
  })
}
