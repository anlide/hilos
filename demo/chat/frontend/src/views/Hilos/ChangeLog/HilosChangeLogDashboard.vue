<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">Change Log</h5>
        </div>
        <div class="card-body">
          <p class="text-body-secondary">
            Audit triggers and change tracking configuration (stub). Expected vs actual trigger state will be
            driven by code config; manual sync and dry-run are not wired yet.
          </p>

          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <div class="border rounded p-3 h-100">
                <div class="small text-body-secondary">Triggers expected (config)</div>
                <div class="fs-4">{{ stub.triggersExpected }}</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="border rounded p-3 h-100">
                <div class="small text-body-secondary">Triggers active (DB)</div>
                <div class="fs-4">{{ stub.triggersActive }}</div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="border rounded p-3 h-100">
                <div class="small text-body-secondary">Deviations</div>
                <div class="fs-4">{{ stub.deviationsCount }}</div>
              </div>
            </div>
          </div>

          <section class="mb-4">
            <h6 class="text-body-secondary text-uppercase small">Retention policy (stub)</h6>
            <p class="mb-0">
              Configured: <strong>{{ stub.retentionLabel }}</strong>
              <span class="text-body-secondary small ms-1">
                (production must choose: forever, 100 days, 1 year, or 10 years)
              </span>
            </p>
          </section>

          <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="button" class="btn btn-sm btn-primary" disabled title="Not implemented">
              Sync triggers
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Not implemented">
              Dry-run sync
            </button>
            <router-link class="btn btn-sm btn-outline-primary" to="/hilos/change-log/tables">
              Tracked tables
            </router-link>
          </div>

          <p class="small text-body-secondary mb-0">
            Trigger naming: <code>{{ triggerNamingExample }}</code>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import { hilosChangeLogDashboardStub as stub } from '@/constants/hilosChangeLogStubs'

const triggerNamingExample = 'hilos_cl_{table}_{after_insert|after_update|after_delete}'

useHead({
  title: 'Change Log | Hilos | Chat Demo',
})
</script>
