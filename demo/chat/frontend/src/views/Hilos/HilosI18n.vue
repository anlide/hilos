<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-xl-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">Internationalization</h5>
        </div>
        <div class="card-body">
          <p class="text-body-secondary">
            Stub navigation for I18n catalogs and translation screens. Use the links below to explore routes.
          </p>
          <div class="list-group">
            <router-link class="list-group-item list-group-item-action" to="/hilos/i18n/languages">Languages</router-link>
            <router-link class="list-group-item list-group-item-action" to="/hilos/i18n/countries">Countries</router-link>
            <router-link class="list-group-item list-group-item-action" to="/hilos/i18n/entities">Entities</router-link>
            <router-link class="list-group-item list-group-item-action" to="/hilos/i18n/ui-pages">UI pages</router-link>
            <router-link class="list-group-item list-group-item-action" to="/hilos/i18n/groups">Groups</router-link>
            <router-link class="list-group-item list-group-item-action" to="/hilos/i18n/actions">Actions</router-link>
            <router-link class="list-group-item list-group-item-action" to="/hilos/i18n/emails">Emails</router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'

useHead({
  title: 'Internationalization | Chat Hilos Demo',
  meta: [
    {
      name: 'description',
      content: 'Hilos internationalization stub section for the chat demo.',
    },
  ],
})
</script>
