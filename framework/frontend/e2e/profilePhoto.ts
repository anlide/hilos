import type { Expect, Page } from '@playwright/test'
import { clickSubmit } from './input.js'

/** A colored rectangle in a demo's photo fixture. */
export interface PhotoFixtureRectangle {
  color: string
  x: number
  y: number
  width: number
  height: number
}

/** The demo's picture dimensions, encoding and ordered color regions. */
export interface PhotoFixtureRecipe {
  width: number
  height: number
  mimeType: 'image/jpeg' | 'image/png'
  quality?: number
  rectangles: readonly PhotoFixtureRectangle[]
}

/** A pixel coordinate in the decoded public picture. */
export interface PhotoSamplePoint {
  x: number
  y: number
}

/** Decoded dimensions and RGBA samples in the requested point order. */
export interface PhotoPixelSamples {
  width: number
  height: number
  colors: number[][]
}

/** Select a picture in an open photo window and await the ready crop controls. */
export async function selectPhoto(
  expect: Expect,
  page: Page,
  buffer: Buffer,
  mimeType: string,
): Promise<void> {
  await page.getByTestId('profile-photo-input').setInputFiles({
    name: mimeType === 'image/png' ? 'photo.png' : 'photo.jpg',
    mimeType,
    buffer,
  })
  await expect(page.getByTestId('profile-photo-preview')).toBeVisible()
  await expect(page.getByTestId('profile-photo-save')).toBeEnabled()
}

/** Open the photo window, select a picture and await the ready crop controls. */
export async function openAndSelectPhoto(
  expect: Expect,
  page: Page,
  buffer: Buffer,
  mimeType: string,
): Promise<void> {
  await clickSubmit(expect, page.getByTestId('profile-photo-open'))
  await selectPhoto(expect, page, buffer, mimeType)
}

/** Draw a demo's recipe in the browser without a binary fixture in the tree. */
export async function drawPhotoFixture(
  page: Page,
  recipe: PhotoFixtureRecipe,
): Promise<Buffer> {
  const dataUrl = await page.evaluate((recipe) => {
    const canvas = document.createElement('canvas')
    try {
      canvas.width = recipe.width
      canvas.height = recipe.height
      const drawing = canvas.getContext('2d')
      if (!drawing) throw new Error('The browser has no 2D canvas')
      for (const rectangle of recipe.rectangles) {
        drawing.fillStyle = rectangle.color
        drawing.fillRect(
          rectangle.x,
          rectangle.y,
          rectangle.width,
          rectangle.height,
        )
      }
      return canvas.toDataURL(recipe.mimeType, recipe.quality)
    } finally {
      canvas.remove()
    }
  }, recipe)
  return Buffer.from(dataUrl.split(',')[1] ?? '', 'base64')
}

/** Decode public picture bytes and sample the demo's coordinates in order. */
export async function samplePhotoPixels(
  page: Page,
  bytes: Buffer,
  points: readonly PhotoSamplePoint[],
): Promise<PhotoPixelSamples> {
  return page.evaluate(
    async ({ data, points }) => {
      const bitmap = await createImageBitmap(new Blob([new Uint8Array(data)]))
      const canvas = document.createElement('canvas')
      try {
        canvas.width = bitmap.width
        canvas.height = bitmap.height
        const drawing = canvas.getContext('2d')
        if (!drawing) throw new Error('The browser has no 2D canvas')
        drawing.drawImage(bitmap, 0, 0)
        return {
          width: bitmap.width,
          height: bitmap.height,
          colors: points.map(({ x, y }) =>
            Array.from(drawing.getImageData(x, y, 1, 1).data),
          ),
        }
      } finally {
        canvas.remove()
        bitmap.close()
      }
    },
    { data: Array.from(bytes), points },
  )
}
