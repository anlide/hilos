import { describe, expect, it } from 'vitest'

import {
  EMPTY_WAREHOUSE_DRAFT,
  canAddWarehouse,
  isWarehouseDraftDirty,
} from '../../../src/views/AdminWarehouses/warehouseAddDraft.js'

describe('warehouse add draft', () => {
  it('adds only when both the name and the address hold something', () => {
    expect(canAddWarehouse(EMPTY_WAREHOUSE_DRAFT)).toBe(false)
    expect(canAddWarehouse({ name: 'North', address: '' })).toBe(false)
    expect(canAddWarehouse({ name: '', address: '1 Main St' })).toBe(false)
    expect(canAddWarehouse({ name: 'North', address: '1 Main St' })).toBe(true)
  })

  it('reads the raw value, leaving blank text for the server to refuse', () => {
    expect(canAddWarehouse({ name: ' ', address: ' ' })).toBe(true)
  })

  it('is dirty as soon as either field holds something', () => {
    expect(isWarehouseDraftDirty(EMPTY_WAREHOUSE_DRAFT)).toBe(false)
    expect(isWarehouseDraftDirty({ name: 'N', address: '' })).toBe(true)
    expect(isWarehouseDraftDirty({ name: '', address: 'A' })).toBe(true)
  })
})
