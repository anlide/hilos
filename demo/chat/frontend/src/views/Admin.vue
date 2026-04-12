<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <div class="d-flex flex-column gap-1">
            <h5 class="mb-0">Admin</h5>
            <p class="mb-0 text-body-secondary">
              Chat demo administration and the Hilos platform hub.
            </p>
          </div>
        </div>
        <div class="card-body">
          <client-only>
            <div
              v-for="(section, sectionIndex) in dashboardSections"
              :key="section.title"
              :class="sectionIndex === 0 ? '' : 'border-top pt-4 mt-4'"
            >
              <div class="mb-3">
                <h6 class="mb-1 text-uppercase text-body-secondary">{{ section.title }}</h6>
                <p class="mb-0 text-body-secondary">{{ section.description }}</p>
              </div>

              <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
                <div
                  v-for="item in section.items"
                  :key="item.to"
                  class="col"
                >
                  <div class="card h-100 shadow-sm border-0">
                    <div class="card-body">
                      <div class="d-flex align-items-start gap-3">
                        <div
                          class="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-2 lh-1"
                        >
                          <i
                            :class="['bi', item.icon, 'text-body-emphasis']"
                            aria-hidden="true"
                          ></i>
                        </div>
                        <div class="d-flex flex-column gap-2">
                          <h5 class="mb-0">
                            <router-link
                              :to="item.to"
                              class="link-body-emphasis link-offset-2 link-underline link-underline-opacity-0 link-underline-opacity-75-hover"
                            >
                              {{ item.title }}
                            </router-link>
                          </h5>
                          <p class="mb-0 text-body-secondary">
                            {{ item.description }}
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
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
type DashboardItem = {
  to: string
  title: string
  description: string
  icon: string
}

type DashboardSection = {
  title: string
  description: string
  items: DashboardItem[]
}

const dashboardSections: DashboardSection[] = [
  {
    title: 'Chat administration',
    description: 'Users, moderation prompts, and bots for this demo chat.',
    items: [
      {
        to: '/admin/users',
        title: 'Users',
        description: 'View and manage chat user accounts.',
        icon: 'bi-people',
      },
      {
        to: '/admin/moderator',
        title: 'Moderator prompt pieces',
        description: 'Moderator rules, prompts, and policy fragments.',
        icon: 'bi-chat-left-quote',
      },
      {
        to: '/admin/bots',
        title: 'Bots',
        description: 'Configure and manage chat bots.',
        icon: 'bi-robot',
      },
    ],
  },
  {
    title: 'Hilos platform',
    description: 'Framework-level administration, settings, and operations.',
    items: [
      {
        to: '/hilos',
        title: 'Hilos',
        description: 'Open the Hilos dashboard: users, i18n, logs, billing, and more.',
        icon: 'bi-grid-3x3',
      },
    ],
  },
]
</script>
