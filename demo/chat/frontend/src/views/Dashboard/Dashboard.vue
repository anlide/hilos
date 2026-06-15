<!-- The chat dashboard (HilosPages.DASHBOARD): a thin project binding of the
framework HilosDashboardPage that adds this app's own admin areas above the
framework sections through the page's `#top` slot — the "Chat administration"
group linking to the bots and moderation admin pages. The framework owns the
section catalog and the card layout; the project supplies only its extra cards.
Bootstrap classes only (styling-rules.md). -->
<script setup lang="ts">
import { HilosDashboardPage, HilosLink } from '@hilos/vue'

defineOptions({ name: 'DashboardPage' })

/** This app's own admin areas, shown above the framework sections. */
const chatItems = [
  {
    key: 'admin_bots',
    title: 'Bots',
    lead: 'Library bots: profiles, status, and behavior.',
    icon: 'bi-robot',
    to: '/hilos/admin_bots',
  },
  {
    key: 'admin_moderator',
    title: 'Moderation',
    lead: 'Moderator prompt pieces and rules.',
    icon: 'bi-shield-check',
    to: '/hilos/admin_moderator',
  },
]
</script>

<template>
  <HilosDashboardPage>
    <template #top>
      <div class="mb-4">
        <div class="mb-3">
          <h2 class="h6 text-uppercase text-body-secondary mb-1">
            Chat administration
          </h2>
          <p class="mb-0 text-body-secondary">
            Application-specific admin areas for the chat demo.
          </p>
        </div>

        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
          <div v-for="item in chatItems" :key="item.key" class="col">
            <HilosLink
              :to="item.to"
              class="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
              :data-id="`dashboard-card-${item.key}`"
            >
              <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                  <span
                    class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1"
                  >
                    <i :class="['bi', item.icon]" aria-hidden="true"></i>
                  </span>
                  <span class="d-flex flex-column gap-1">
                    <span class="h6 mb-0">{{ item.title }}</span>
                    <span class="small text-body-secondary">{{
                      item.lead
                    }}</span>
                  </span>
                </div>
              </div>
            </HilosLink>
          </div>
        </div>
      </div>
    </template>
  </HilosDashboardPage>
</template>
