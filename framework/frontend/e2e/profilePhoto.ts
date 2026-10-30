import type { Expect, Page } from '@playwright/test'
import { clickSubmit } from './input.js'
import { expectPageReady } from './page.js'

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

/** Check the assembled profile head, keyboard navigation and photo focus return. */
export async function expectProfileHeadAccessibility(
  expect: Expect,
  page: Page,
  name: string,
): Promise<void> {
  await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
  const navigation = page.getByRole('navigation', {
    name: 'Profile pages',
    exact: true,
  })
  await expect(navigation).toBeVisible()
  await expect(
    navigation.getByRole('link', { name: 'Overview', exact: true }),
  ).toHaveAttribute('aria-current', 'page')
  const data = page.getByTestId('hilos-page-tab-hilos_profile_data')
  await data.focus()
  await page.keyboard.press('Enter')
  await expectPageReady(expect, page)
  await expect(page).toHaveURL(/\/profile\/data$/)
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
  await expect(data).toHaveAttribute('aria-current', 'page')
  const photo = page.getByTestId('profile-photo-open')
  await expect(photo).toHaveAccessibleName('Change your photo')
  await photo.focus()
  await page.keyboard.press('Enter')
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(photo).toBeFocused()
  const overview = page.getByTestId('hilos-page-tab-hilos_profile')
  await overview.focus()
  await page.keyboard.press('Enter')
  await expectPageReady(expect, page)
  await expect(page.getByTestId('profile-name')).toHaveText(name)
}
