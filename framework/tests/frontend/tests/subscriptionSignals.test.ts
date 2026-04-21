import { describe, expect, it } from 'vitest'
import { subscriptionPageError } from '@/signals/subscriptionSignals'

/**
 * Infrastructure-level unit tests for subscription signal parsers.
 *
 * These pin down the wire-format contract of `subscription_page_error`:
 * signals parsed into typed payloads, or `undefined` when the shape is invalid.
 */
describe('subscriptionPageError parser', () => {
  it('parses a valid error payload', () => {
    const parsed = subscriptionPageError.parse({
      page: 'subscription_page_hilos_user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'User #123 not found',
    })

    expect(parsed).toEqual({
      page: 'subscription_page_hilos_user',
      httpCode: 404,
      errorCode: 'not_found',
      message: 'User #123 not found',
    })
  })

  it('parses 403 forbidden error', () => {
    const parsed = subscriptionPageError.parse({
      page: 'subscription_page_hilos_settings',
      httpCode: 403,
      errorCode: 'forbidden',
      message: 'Access forbidden',
    })

    expect(parsed).toEqual({
      page: 'subscription_page_hilos_settings',
      httpCode: 403,
      errorCode: 'forbidden',
      message: 'Access forbidden',
    })
  })

  it('parses 500 internal error', () => {
    const parsed = subscriptionPageError.parse({
      page: 'subscription_page_hilos_users',
      httpCode: 500,
      errorCode: 'internal_error',
      message: 'Internal error during subscription',
    })

    expect(parsed).toEqual({
      page: 'subscription_page_hilos_users',
      httpCode: 500,
      errorCode: 'internal_error',
      message: 'Internal error during subscription',
    })
  })

  it('returns undefined when page is missing', () => {
    const parsed = subscriptionPageError.parse({
      httpCode: 404,
      errorCode: 'not_found',
      message: 'Not found',
    })

    expect(parsed).toBeUndefined()
  })

  it('returns undefined when httpCode is not a number', () => {
    const parsed = subscriptionPageError.parse({
      page: 'test_page',
      httpCode: '404',
      errorCode: 'not_found',
      message: 'Not found',
    })

    expect(parsed).toBeUndefined()
  })

  it('returns undefined when errorCode is missing', () => {
    const parsed = subscriptionPageError.parse({
      page: 'test_page',
      httpCode: 404,
      message: 'Not found',
    })

    expect(parsed).toBeUndefined()
  })

  it('returns undefined when message is missing', () => {
    const parsed = subscriptionPageError.parse({
      page: 'test_page',
      httpCode: 404,
      errorCode: 'not_found',
    })

    expect(parsed).toBeUndefined()
  })

  it('returns undefined on null input', () => {
    expect(subscriptionPageError.parse(null)).toBeUndefined()
  })

  it('returns undefined on non-object input', () => {
    expect(subscriptionPageError.parse('not an object')).toBeUndefined()
    expect(subscriptionPageError.parse(123)).toBeUndefined()
    expect(subscriptionPageError.parse([])).toBeUndefined()
  })
})
