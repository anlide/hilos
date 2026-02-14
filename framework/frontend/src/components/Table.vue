<template>
  <div>
    <!-- Search, Controls and Snapshot Update Banner -->
    <div v-if="searchable || showActions || hasPendingChanges" class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2 flex-wrap flex-grow-1">
        <div v-if="searchable" class="flex-grow-1 input-group">
          <span class="input-group-text">
            <i class="bi bi-search" aria-hidden="true"></i>
            <span class="visually-hidden">Search</span>
          </span>
          <input
              v-model="searchQuery"
              type="text"
              class="form-control"
              :placeholder="searchPlaceholder"
              :aria-label="searchPlaceholder || 'Search'"
          />
        </div>
        <div v-if="hasPendingChanges" class="d-flex align-items-center gap-2 flex-wrap">
          <span
              v-if="pendingChanges.added > 0"
              class="badge bg-success"
              :title="`${pendingChanges.added} to add`"
          >
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span class="visually-hidden">Pending additions</span>
          </span>
          <span
              v-if="pendingChanges.updated > 0"
              class="badge bg-warning"
              :title="`${pendingChanges.updated} to update`"
          >
            <i class="bi bi-pencil" aria-hidden="true"></i>
            <span class="visually-hidden">Pending updates</span>
          </span>
          <span
              v-if="pendingChanges.deleted > 0"
              class="badge bg-danger"
              :title="`${pendingChanges.deleted} to delete`"
          >
            <i class="bi bi-trash" aria-hidden="true"></i>
            <span class="visually-hidden">Pending deletions</span>
          </span>
          <button
              type="button"
              class="btn btn-primary btn-sm"
              title="Update Snapshot"
              aria-label="Update Snapshot"
              @click="$emit('updateSnapshot')"
          >
            <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
            <span class="visually-hidden">Update Snapshot</span>
          </button>
        </div>
      </div>
      <div v-if="showActions" class="d-flex gap-2">
        <slot name="actions">
          <button
              v-if="showAddButton"
              type="button"
              class="btn btn-primary btn-sm"
              title="Add"
              aria-label="Add"
              @click="$emit('add')"
          >
            <i class="bi bi-plus-lg" aria-hidden="true"></i>
            <span class="visually-hidden">Add</span>
          </button>
        </slot>
      </div>
    </div>

    <!-- Table -->
    <div class="table-responsive">
      <table class="table table-striped table-hover" aria-label="Data table" :aria-rowcount="filteredItems.length" :items="items">
        <thead>
        <tr>
          <slot name="header" :sort="sortState" :handleSort="handleSort" :isFieldSortable="isFieldSortable">
            <!-- Default header if no slot provided -->
          </slot>
        </tr>
        </thead>
        <tbody>
        <template v-if="paginatedItems.length > 0">
          <tr
              v-for="(item, index) in paginatedItems"
              :key="getItemKey(item, index)"
              :class="getRowChangeClass(item)"
          >
            <slot
                name="row"
                :item="item"
                :index="index"
                :handleEdit="showEditButton ? () => $emit('edit', item) : undefined"
                :handleDelete="showDeleteButton ? () => $emit('delete', item) : undefined"
                :showEditButton="showEditButton"
                :showDeleteButton="showDeleteButton"
                :changeType="getChangeType(item)"
            >
              <!-- Default row if no slot provided -->
            </slot>
          </tr>
        </template>
        <tr v-else>
          <td :colspan="colspan" class="text-center text-muted py-4">
            <slot name="empty">
              <i class="bi bi-inbox display-4" aria-hidden="true"></i>
              <span class="visually-hidden">No data available</span>
            </slot>
          </td>
        </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="paginated" class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
      <div v-if="fixedItemsPerPage === undefined" class="d-flex align-items-center gap-2">
        <label for="table-items-per-page" class="mb-0 d-flex align-items-center gap-1" title="Items per page">
          <i class="bi bi-list-ul" aria-hidden="true"></i>
          <span class="visually-hidden">Items per page</span>
        </label>
        <select id="table-items-per-page" v-model="itemsPerPage" class="form-select form-select-sm d-inline-block w-auto" aria-label="Items per page">
          <option v-for="option in itemsPerPageOptions" :key="option" :value="option">
            {{ option }}
          </option>
        </select>
      </div>
      <div v-else class="d-flex align-items-center gap-2">
        <span class="text-muted d-flex align-items-center gap-1">
          <i class="bi bi-list-ul" aria-hidden="true"></i>
          <span class="visually-hidden">Items per page:</span>
          {{ fixedItemsPerPage }}
        </span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <span class="text-muted d-flex align-items-center gap-1">
          <i class="bi bi-collection" aria-hidden="true"></i>
          <span class="visually-hidden">Showing</span>
          {{ startIndex + 1 }}–{{ endIndex }}
          <i class="bi bi-slash" aria-hidden="true"></i>
          <span class="visually-hidden">of</span>
          {{ filteredItems.length }}
        </span>
        <nav aria-label="Pagination">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item" :class="{ disabled: currentPage === 1 }">
              <button type="button" class="page-link" @click="currentPage = 1" :disabled="currentPage === 1" aria-label="First page">
                <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
              </button>
            </li>
            <li class="page-item" :class="{ disabled: currentPage === 1 }">
              <button type="button" class="page-link" @click="currentPage--" :disabled="currentPage === 1" aria-label="Previous">
                <i class="bi bi-chevron-left" aria-hidden="true"></i>
              </button>
            </li>
            <li class="page-item" :class="{ active: page === currentPage }" v-for="page in visiblePages" :key="page">
              <button type="button" class="page-link" @click="currentPage = page" :aria-label="`Page ${page}`" :aria-current="page === currentPage ? 'page' : undefined">
                {{ page }}
              </button>
            </li>
            <li class="page-item" :class="{ disabled: currentPage === totalPages }">
              <button type="button" class="page-link" @click="currentPage++" :disabled="currentPage === totalPages" aria-label="Next">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
              </button>
            </li>
            <li class="page-item" :class="{ disabled: currentPage === totalPages }">
              <button type="button" class="page-link" @click="currentPage = totalPages" :disabled="currentPage === totalPages" aria-label="Last page">
                <i class="bi bi-chevron-double-right" aria-hidden="true"></i>
              </button>
            </li>
          </ul>
        </nav>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'

interface Props {
  items: unknown[]
  itemKey?: string | ((item: unknown, index: number) => string | number)
  colspan?: number
  searchable?: boolean
  searchPlaceholder?: string
  searchFields?: string[]
  sortable?: boolean
  sortableFields?: string[]
  paginated?: boolean
  itemsPerPageOptions?: number[]
  fixedItemsPerPage?: number
  showActions?: boolean
  showAddButton?: boolean
  showEditButton?: boolean
  showDeleteButton?: boolean
  pendingChanges?: {
    added: number
    updated: number
    deleted: number
  }
  changeMarkers?: {
    added?: (string | number)[]
    updated?: (string | number)[]
    deleted?: (string | number)[]
  }
}

const props = withDefaults(defineProps<Props>(), {
  itemKey: 'id',
  colspan: 1,
  searchable: false,
  searchPlaceholder: 'Search...',
  searchFields: () => [],
  sortable: false,
  sortableFields: () => [],
  paginated: false,
  itemsPerPageOptions: () => [10, 25, 50, 100],
  fixedItemsPerPage: undefined,
  showActions: false,
  showAddButton: false,
  showEditButton: true,
  showDeleteButton: true,
  pendingChanges: () => ({ added: 0, updated: 0, deleted: 0 }),
  changeMarkers: () => ({ added: [], updated: [], deleted: [] }),
})

defineEmits<{
  add: []
  edit: [item: unknown]
  delete: [item: unknown]
  updateSnapshot: []
}>()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(props.fixedItemsPerPage ?? (props.itemsPerPageOptions[0] || 10))
const sortState = ref<{ field: string | null; direction: 'asc' | 'desc' | null }>({
  field: null,
  direction: null,
})

// Watch for fixedItemsPerPage changes
watch(() => props.fixedItemsPerPage, (newValue: number | undefined) => {
  if (newValue !== undefined) {
    itemsPerPage.value = newValue
  }
}, { immediate: true })

const getItemKey = (item: unknown, index: number): string | number => {
  if (typeof props.itemKey === 'function') {
    return props.itemKey(item, index)
  }

  if (typeof item === 'object' && item !== null && props.itemKey in item) {
    const key = (item as Record<string, unknown>)[props.itemKey]
    return key !== null && key !== undefined ? String(key) : `row-${index}`
  }

  return `row-${index}`
}

const getItemId = (item: unknown): string | number | null => {
  if (typeof props.itemKey === 'function') {
    const result: string | number = props.itemKey(item, 0)
    if (typeof result === 'string') return result
    return result
  }

  if (typeof item === 'object' && item !== null && props.itemKey in item) {
    const key = (item as Record<string, unknown>)[props.itemKey]
    if (key !== null && key !== undefined && (typeof key === 'string' || typeof key === 'number')) {
      return key
    }
  }

  return null
}

const hasPendingChanges = computed(() => {
  const changes = props.pendingChanges
  return changes.added > 0 || changes.updated > 0 || changes.deleted > 0
})

const getChangeType = (item: unknown): 'added' | 'updated' | 'deleted' | null => {
  const id = getItemId(item)
  if (id === null) return null

  const idStr = String(id)
  const markers = props.changeMarkers

  if (markers.added?.includes(idStr) || markers.added?.includes(id)) {
    return 'added'
  }
  if (markers.updated?.includes(idStr) || markers.updated?.includes(id)) {
    return 'updated'
  }
  if (markers.deleted?.includes(idStr) || markers.deleted?.includes(id)) {
    return 'deleted'
  }

  return null
}

const getRowChangeClass = (item: unknown): string => {
  const changeType = getChangeType(item)
  switch (changeType) {
    case 'added':
      return 'table-success'
    case 'updated':
      return 'table-warning'
    case 'deleted':
      return 'table-danger'
    default:
      return ''
  }
}

const handleSort = (field: string): void => {
  if (!props.sortable) return

  // Check if field is allowed for sorting
  if (props.sortableFields.length > 0 && !props.sortableFields.includes(field)) {
    return
  }

  if (sortState.value.field === field) {
    if (sortState.value.direction === 'asc') {
      sortState.value.direction = 'desc'
    } else if (sortState.value.direction === 'desc') {
      sortState.value.field = null
      sortState.value.direction = null
    } else {
      sortState.value.direction = 'asc'
    }
  } else {
    sortState.value.field = field
    sortState.value.direction = 'asc'
  }
}

const isFieldSortable = (field: string): boolean => {
  if (!props.sortable) return false
  if (props.sortableFields.length === 0) return true
  return props.sortableFields.includes(field)
}

const filteredItems = computed(() => {
  let result = [...props.items]

  // Apply search
  if (props.searchable && searchQuery.value.trim()) {
    const query = searchQuery.value.toLowerCase().trim()
    const fields = props.searchFields.length > 0 ? props.searchFields : []

    result = result.filter((item) => {
      if (typeof item !== 'object' || item === null) return false

      const obj = item as Record<string, unknown>

      if (fields.length > 0) {
        return fields.some((field: string) => {
          const value = obj[field]
          return value !== null && value !== undefined && String(value).toLowerCase().includes(query)
        })
      } else {
        // Search in all string/number fields
        return Object.values(obj).some((value) => {
          if (value === null || value === undefined) return false
          return String(value).toLowerCase().includes(query)
        })
      }
    })
  }

  // Apply sorting
  if (props.sortable && sortState.value.field && sortState.value.direction) {
    const field = sortState.value.field
    const direction = sortState.value.direction

    result.sort((a, b) => {
      if (typeof a !== 'object' || a === null || typeof b !== 'object' || b === null) return 0

      const aVal = (a as Record<string, unknown>)[field]
      const bVal = (b as Record<string, unknown>)[field]

      if (aVal === null || aVal === undefined) return 1
      if (bVal === null || bVal === undefined) return -1

      const aStr = String(aVal)
      const bStr = String(bVal)

      const comparison = aStr.localeCompare(bStr, undefined, { numeric: true, sensitivity: 'base' })
      return direction === 'asc' ? comparison : -comparison
    })
  }

  return result
})

const totalPages = computed(() => {
  if (!props.paginated) return 1
  return Math.ceil(filteredItems.value.length / itemsPerPage.value)
})

const startIndex = computed(() => {
  if (!props.paginated) return 0
  return (currentPage.value - 1) * itemsPerPage.value
})

const endIndex = computed(() => {
  if (!props.paginated) return filteredItems.value.length
  return Math.min(startIndex.value + itemsPerPage.value, filteredItems.value.length)
})

const paginatedItems = computed(() => {
  if (!props.paginated) return filteredItems.value
  return filteredItems.value.slice(startIndex.value, endIndex.value)
})

const visiblePages = computed(() => {
  const pages: number[] = []
  const total = totalPages.value
  const current = currentPage.value

  if (total <= 7) {
    // Show all pages if 7 or fewer
    for (let i = 1; i <= total; i++) {
      pages.push(i)
    }
  } else {
    // Show first, last, current, and neighbors
    if (current <= 3) {
      for (let i = 1; i <= 5; i++) pages.push(i)
      pages.push(total)
    } else if (current >= total - 2) {
      pages.push(1)
      for (let i = total - 4; i <= total; i++) pages.push(i)
    } else {
      pages.push(1)
      for (let i = current - 1; i <= current + 1; i++) pages.push(i)
      pages.push(total)
    }
  }

  return pages
})

// Reset to first page when search changes
watch(searchQuery, () => {
  currentPage.value = 1
})

// Reset to first page when items per page changes (only if not fixed)
watch(itemsPerPage, () => {
  if (props.fixedItemsPerPage === undefined) {
    currentPage.value = 1
  }
})

// Ensure current page is valid when total pages changes
watch(totalPages, (newTotal: number) => {
  if (currentPage.value > newTotal && newTotal > 0) {
    currentPage.value = newTotal
  }
})
</script>
