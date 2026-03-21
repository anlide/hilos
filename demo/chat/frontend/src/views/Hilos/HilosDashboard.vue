<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-8 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">Hilos</h5>
        </div>
        <div class="card-body">
          <div class="list-group">
            <router-link to="/hilos/settings" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h6 class="mb-1">Settings</h6>
              </div>
              <p class="mb-1">Project settings</p>
            </router-link>
            <router-link to="/hilos/i18n" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h6 class="mb-1">Internationalization</h6>
              </div>
              <p class="mb-1">Languages, countries, locales, translations</p>
            </router-link>
            <router-link to="/hilos/guardian" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h6 class="mb-1">Guardian</h6>
              </div>
              <p class="mb-1">Project validation robots</p>
            </router-link>
            <router-link to="/hilos/analytics" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h6 class="mb-1">Analytics</h6>
              </div>
              <p class="mb-1">Visit statistics</p>
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
</script>
