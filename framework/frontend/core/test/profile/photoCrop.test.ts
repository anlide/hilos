import { describe, expect, it } from 'vitest'
import {
  clampHilosPhotoCrop,
  hilosPhotoSourceSquare,
  moveHilosPhotoCrop,
} from '../../src/profile/photoCrop.js'

describe('profile photo crop geometry', () => {
  it.each([
    {
      width: 1200,
      height: 800,
      zoom: 1,
      expected: { sx: 200, sy: 0, side: 800 },
    },
    {
      width: 800,
      height: 1200,
      zoom: 1,
      expected: { sx: 0, sy: 200, side: 800 },
    },
    { width: 800, height: 800, zoom: 1, expected: { sx: 0, sy: 0, side: 800 } },
    {
      width: 800,
      height: 1200,
      zoom: 4,
      expected: { sx: 300, sy: 500, side: 200 },
    },
  ])(
    'extracts a square inside $width × $height at zoom $zoom',
    ({ width, height, zoom, expected }) => {
      expect(
        hilosPhotoSourceSquare(width, height, {
          zoom,
          centerX: width / 2,
          centerY: height / 2,
        }),
      ).toEqual(expected)
    },
  )

  it('clamps a dragged picture at every edge, leaving no empty part of the circle', () => {
    const first = clampHilosPhotoCrop(1200, 800, {
      zoom: 4,
      centerX: -100,
      centerY: 900,
    })
    const moved = moveHilosPhotoCrop(1200, 800, first, -10_000, 10_000, 128)
    const square = hilosPhotoSourceSquare(1200, 800, moved)

    expect(first).toEqual({ zoom: 4, centerX: 100, centerY: 700 })
    expect(square).toEqual({ sx: 1000, sy: 0, side: 200 })
    expect(square.sx + square.side).toBeLessThanOrEqual(1200)
    expect(square.sy + square.side).toBeLessThanOrEqual(800)
  })

  it('limits zoom and converts picture movement from preview pixels to source pixels', () => {
    expect(
      clampHilosPhotoCrop(800, 800, { zoom: 9, centerX: 400, centerY: 400 })
        .zoom,
    ).toBe(4)
    expect(
      moveHilosPhotoCrop(
        800,
        800,
        { zoom: 2, centerX: 400, centerY: 400 },
        64,
        0,
        128,
      ),
    ).toEqual({ zoom: 2, centerX: 200, centerY: 400 })
  })
})
