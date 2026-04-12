/**
 * Stub data for Hilos demo sections that are not yet backed by the app API.
 * User list/detail use live WebSocket table data (same `users` table as chat admin).
 */

export const hilosRolesStub = [
  { roleId: 'admin', name: 'Admin', description: 'Full access to Hilos and project settings.' },
  { roleId: 'moderator', name: 'Moderator', description: 'Content moderation and limited admin.' },
  { roleId: 'user', name: 'User', description: 'Standard application user.' },
] as const

export const hilosOperationsStub = [
  {
    id: 'regen-sitemap-robots',
    title: 'Regenerate sitemap.xml and robots.txt',
    description: 'Rebuild SEO files from the current route and content catalog (stub).',
  },
  {
    id: 'rebuild-static-html',
    title: 'Rebuild static HTML files',
    description: 'Regenerate prerendered pages and asset manifests (stub).',
  },
] as const
