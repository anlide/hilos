// Hilos web-push service worker (HIL-199).
//
// Framework-standard and framework-agnostic: a flat, dependency-free worker that
// any Hilos SDK app enabling web push serves from its web root so the browser
// registers it with scope "/". Vite copies files under `public/` to the dist
// root and serves them at "/" in dev too, so the SDK's registration helper can
// register a stable "/sw.js". Kept identical across SDK apps — copy this file
// verbatim when a React/Angular app adds the push toggle.
//
// The backend push agent (`Hilos\Push\Delivery\PushDeliveryChannelAgent`) sends a
// JSON payload shaped `{ title, body, data: { ...appData, notificationId, type } }`
// (title/body already localized backend-side). This worker renders that into a
// system notification and routes a click back into the app.

self.addEventListener('push', (event) => {
  let payload = {}
  try {
    payload = event.data ? event.data.json() : {}
  } catch (error) {
    // A malformed or empty payload still shows a generic notice rather than
    // silently dropping the push.
    payload = {}
  }

  const title = payload.title || 'Notification'
  const data = payload.data || {}
  const options = {
    body: payload.body || '',
    data,
    icon: payload.icon || data.icon,
    tag: data.notificationId ? String(data.notificationId) : undefined,
  }

  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  const data = event.notification.data || {}
  const targetUrl = data.url || '/'

  event.waitUntil(
    self.clients
      .matchAll({ type: 'window', includeUncontrolled: true })
      .then((clients) => {
        // Prefer focusing an already-open app tab; navigate it if the payload
        // named a destination. Fall back to opening a fresh window.
        for (const client of clients) {
          if ('focus' in client) {
            if (data.url && 'navigate' in client) {
              return client.focus().then(() => client.navigate(targetUrl))
            }
            return client.focus()
          }
        }
        if (self.clients.openWindow) {
          return self.clients.openWindow(targetUrl)
        }
        return undefined
      }),
  )
})
