<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-8 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h2 class="h4 mb-0 fw-bold">Terms of Use</h2>
        </div>
        <div class="card-body">
          <h3 class="h5 mt-0 mb-2 fw-bold">Acceptance</h3>
          <p class="mb-3">
            By using this service you agree to these terms. If you do not agree, do not use the service.
          </p>
          <h3 class="h5 mt-3 mb-2 fw-bold">Service description</h3>
          <p class="mb-3">
            This is a demo WebSocket chat. The service is provided &quot;as is&quot; with no warranties. We may change, suspend or discontinue it at any time.
          </p>
          <h3 class="h5 mt-3 mb-2 fw-bold">User obligations</h3>
          <p class="mb-3">
            Use the service lawfully. Do not spam, harass, abuse, or violate others&apos; rights. Do not attempt to break or bypass the service.
          </p>
          <h3 class="h5 mt-3 mb-2 fw-bold">Account & content</h3>
          <p class="mb-3">
            You are responsible for your chosen nickname and for your messages. We may remove content that violates these terms without prior notice.
          </p>
          <h3 class="h5 mt-3 mb-2 fw-bold">Limitation of liability</h3>
          <p class="mb-3">
            We are not liable for any indirect, incidental or consequential damages arising from use of the service. Liability is limited to the extent permitted by law.
          </p>
          <h3 class="h5 mt-3 mb-2 fw-bold">Modification</h3>
          <p class="mb-3">
            We may change these terms at any time. Continued use of the service after changes constitutes acceptance of the new terms.
          </p>
          <h3 class="h5 mt-3 mb-2 fw-bold">Contact</h3>
          <p class="mb-0">
            For questions about these terms: <a href="mailto:elfanlide@gmail.com">elfanlide@gmail.com</a>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'

useHead({
  title: 'Terms of Use | Chat Hilos Demo',
  meta: [
    {
      name: 'description',
      content: 'Terms of use for the demo WebSocket chat service.'
    }
  ]
})
</script>
