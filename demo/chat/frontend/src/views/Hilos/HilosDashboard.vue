<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <div class="d-flex flex-column gap-1">
            <h5 class="mb-0">Hilos</h5>
            <p class="mb-0 text-body-secondary">
              Administrative sections with quick access to key project areas.
            </p>
          </div>
        </div>
        <div class="card-body">
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
    title: 'Core administration',
    description: 'Daily administrative tools and shared project configuration.',
    items: [
      {
        to: '/hilos/users',
        title: 'Users',
        description: 'Accounts and roles assignment (stub)',
        icon: 'bi-people',
      },
      {
        to: '/hilos/roles',
        title: 'Roles',
        description: 'Role catalog (stub)',
        icon: 'bi-person-badge',
      },
      {
        to: '/hilos/settings',
        title: 'Settings',
        description: 'Project settings',
        icon: 'bi-sliders',
      },
      {
        to: '/hilos/i18n',
        title: 'Internationalization',
        description: 'Languages, countries, locales, translations',
        icon: 'bi-translate',
      },
    ],
  },
  {
    title: 'Product and integrations',
    description: 'Messaging, identity, and commerce configuration (stub).',
    items: [
      {
        to: '/hilos/communications',
        title: 'Communications',
        description: 'Email, SMS, push, Telegram, Slack (stub)',
        icon: 'bi-envelope-paper',
      },
      {
        to: '/hilos/security',
        title: 'Security Center',
        description: '2FA, OAuth login providers (stub)',
        icon: 'bi-shield-lock',
      },
      {
        to: '/hilos/billing',
        title: 'Billing',
        description: 'Payment providers, payments, refunds (stub)',
        icon: 'bi-credit-card',
      },
    ],
  },
  {
    title: 'Platform operations',
    description: 'Runtime, maintenance, backup, and observability sections.',
    items: [
      {
        to: '/hilos/operations',
        title: 'Operations',
        description: 'Sitemap, static HTML, maintenance tasks (stub)',
        icon: 'bi-diagram-3',
      },
      {
        to: '/hilos/daemon',
        title: 'Daemon',
        description: 'Process metrics, workers, cron, HTTP (stub)',
        icon: 'bi-cpu',
      },
      {
        to: '/hilos/logs',
        title: 'Logs',
        description: 'Rotation stats, keys, viewer (stub)',
        icon: 'bi-journal-text',
      },
      {
        to: '/hilos/change-log',
        title: 'Change Log',
        description: 'Audit triggers, change tracking configuration (stub)',
        icon: 'bi-clock-history',
      },
      {
        to: '/hilos/backup',
        title: 'Backups',
        description: 'Database and file backup jobs (stub)',
        icon: 'bi-safe2',
      },
    ],
  },
  {
    title: 'Automation and intelligence',
    description: 'Validation, analytics, AI tooling, and elevated automation.',
    items: [
      {
        to: '/hilos/guardian',
        title: 'Guardian',
        description: 'Project validation robots',
        icon: 'bi-shield-check',
      },
      {
        to: '/hilos/analytics',
        title: 'Analytics',
        description: 'Visit statistics',
        icon: 'bi-bar-chart',
      },
      {
        to: '/hilos/mcp-skills',
        title: 'MCP и Skills',
        description: 'MCP servers, skills, usage and logs (stub)',
        icon: 'bi-boxes',
      },
      {
        to: '/hilos/sil',
        title: 'System Intelligence Layer',
        description: 'SIL dashboard, requests, and elevated automation (stub)',
        icon: 'bi-lightning-charge',
      },
    ],
  },
]
</script>
