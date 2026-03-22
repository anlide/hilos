/** Stub users and roles for Hilos access admin (demo only). */

export const hilosUsersStub = [
  { userId: '1', email: 'alice@example.com', name: 'Alice', roleLabel: 'Admin' },
  { userId: '2', email: 'bob@example.com', name: 'Bob', roleLabel: 'Moderator' },
  { userId: '3', email: 'carol@example.com', name: 'Carol', roleLabel: 'User' },
] as const

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
