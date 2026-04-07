<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
          <h5 class="mb-0">Change Log — Tracked tables</h5>
          <router-link class="btn btn-sm btn-outline-secondary" to="/hilos/change-log">Dashboard</router-link>
        </div>
        <div class="card-body">
          <p class="text-body-secondary">Stub list. Each row links to per-table trigger / field config.</p>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead>
                <tr>
                  <th scope="col">Table</th>
                  <th scope="col">Expected triggers</th>
                  <th scope="col">Active triggers</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.tableId">
                  <td>
                    <router-link
                      :to="`/hilos/change-log/tables/${encodeURIComponent(row.tableId)}`"
                      class="text-reset"
                    >
                      <code>{{ row.displayName }}</code>
                    </router-link>
                  </td>
                  <td>{{ row.expectedTriggers }}</td>
                  <td>{{ row.activeTriggers }}</td>
                  <td>
                    <span
                      class="badge"
                      :class="{
                        'text-bg-success': row.status === 'ok',
                        'text-bg-warning': row.status === 'mismatch',
                        'text-bg-danger': row.status === 'missing',
                      }"
                    >
                      {{ row.status }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import { hilosChangeLogTablesStub as rows } from '../../../constants/hilosChangeLogStubs'

useHead({
  title: 'Change Log — Tables | Hilos | Chat Demo',
})
</script>
