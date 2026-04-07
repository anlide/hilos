<template>
  <I18nSectionShell title="Countries">
    <p class="text-body-secondary">Stub list.</p>
    <div class="list-group">
      <router-link
        :to="`/hilos/i18n/countries/${encodeURIComponent(stub.countryId)}`"
        class="list-group-item list-group-item-action"
      >
        Sample country ({{ stub.countryId }})
      </router-link>
    </div>
  </I18nSectionShell>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'
import I18nSectionShell from '../I18nSectionShell.vue'
import { hilosI18nStub as stub } from '../../../../constants/hilosI18nStubs'

useHead({ title: 'Countries | I18n | Chat Hilos Demo' })
</script>
