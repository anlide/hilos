import type { Expect } from '@playwright/test'
import type { ThemeFrameLog } from '../../../../../framework/frontend/e2e/index.js'

/** Reject malformed wire objects rather than treating them as a guest. */
function object(expect: Expect, value: unknown): Record<string, unknown> {
  expect(value).not.toBeNull()
  expect(typeof value).toBe('object')
  expect(Array.isArray(value)).toBe(false)
  return value as Record<string, unknown>
}

/** Validate every observed handshake before using its identity. */
export function currentUser(
  expect: Expect,
  frame: ThemeFrameLog,
): Record<string, unknown> | null {
  const entities = object(expect, object(expect, frame.data).entities)
  expect(Object.hasOwn(entities, 'currentUser'), frame.raw).toBe(true)
  if (entities.currentUser === null) return null

  const user = object(expect, entities.currentUser)
  expect(user.id, frame.raw).toEqual(expect.any(Number))
  expect(user.id, frame.raw).toBeGreaterThan(0)
  expect(Object.hasOwn(user, 'timeZone'), frame.raw).toBe(true)
  expect(user.timeZone, frame.raw).toBeNull()
  return user
}
