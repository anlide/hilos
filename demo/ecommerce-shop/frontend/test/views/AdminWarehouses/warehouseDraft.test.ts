import { describe, expect, it } from 'vitest'

import {
  EMPTY_WAREHOUSE_DRAFT,
  isWarehouseDraftComplete,
  isWarehouseDraftDirty,
} from '../../../src/views/AdminWarehouses/warehouseDraft.js'

describe('warehouse draft', () => {
  it('is complete only when both the name and the address hold something', () => {
    expect(isWarehouseDraftComplete(EMPTY_WAREHOUSE_DRAFT)).toBe(false)
    expect(isWarehouseDraftComplete({ name: 'North', address: '' })).toBe(false)
    expect(isWarehouseDraftComplete({ name: '', address: '1 Main St' })).toBe(
      false,
    )
    expect(
      isWarehouseDraftComplete({ name: 'North', address: '1 Main St' }),
    ).toBe(true)
  })

  it('reads the raw value, leaving blank text for the server to refuse', () => {
    expect(isWarehouseDraftComplete({ name: ' ', address: ' ' })).toBe(true)
  })

  it('is dirty as soon as either field holds something', () => {
    expect(isWarehouseDraftDirty(EMPTY_WAREHOUSE_DRAFT)).toBe(false)
    expect(isWarehouseDraftDirty({ name: 'N', address: '' })).toBe(true)
    expect(isWarehouseDraftDirty({ name: '', address: 'A' })).toBe(true)
  })
})
