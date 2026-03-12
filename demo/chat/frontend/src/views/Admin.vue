<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Admin</h5>
        </div>
        <div class="card-body">
          <client-only>
            <div class="list-group">
              <router-link to="/admin/users" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h6 class="mb-1">Users</h6>
              </div>
              <p class="mb-1">Manage users</p>
            </router-link>
            <router-link to="/admin/moderator" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h6 class="mb-1">Moderator prompt pieces</h6>
              </div>
              <p class="mb-1">Moderator rules and prompts</p>
            </router-link>
            <router-link to="/admin/bots" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h6 class="mb-1">Bots</h6>
              </div>
              <p class="mb-1">Bot management</p>
            </router-link>
            <router-link to="/hilos" class="list-group-item list-group-item-action">
              <div class="d-flex w-100 justify-content-between">
                <h6 class="mb-1">Hilos</h6>
              </div>
              <p class="mb-1">Hilos system administration</p>
            </router-link>
          </div>
            <template #placeholder>
              <p class="mb-0 text-body-secondary">Access denied for guests.</p>
            </template>
          </client-only>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// Admin page with links to admin subpages
</script>
