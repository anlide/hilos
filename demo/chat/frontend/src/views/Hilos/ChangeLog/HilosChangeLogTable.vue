<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
          <h5 class="mb-0">Table: <code>{{ tableId }}</code></h5>
          <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/change-log/tables">All tables</router-link>
        </div>
        <div class="card-body">
          <p class="text-body-secondary">
            Stub field-level tracking. Convention:
            <code>{{ triggerNamingExample }}</code>
          </p>

          <div v-if="detail" class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead>
                <tr>
                  <th scope="col">Field</th>
                  <th scope="col">Track values</th>
                  <th scope="col">Trigger OK (stub)</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="f in detail.fields" :key="f.name">
                  <td><code>{{ f.name }}</code></td>
                  <td>{{ f.trackValues ? 'yes' : 'no' }}</td>
                  <td>
                    <span
                      class="badge"
                      :class="f.triggerOk ? 'text-bg-success' : 'text-bg-warning'"
                    >
                      {{ f.triggerOk ? 'ok' : 'review' }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-body-secondary mb-0">No stub data for this table id.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import { hilosChangeLogTableDetailStub } from '@/constants/hilosChangeLogStubs'

const route = useRoute()
const tableId = computed(() => (typeof route.params.tableId === 'string' ? route.params.tableId : ''))
const detail = computed(() => hilosChangeLogTableDetailStub[tableId.value])
const triggerNamingExample = 'hilos_cl_{table}_{after_insert|after_update|after_delete}'

useHead({
  title: () => (tableId.value ? `Change Log — ${tableId.value} | Hilos` : 'Change Log — Table | Hilos'),
})
</script>
