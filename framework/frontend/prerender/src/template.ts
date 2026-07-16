// Inject a server-rendered body and title into the client build's index.html.
// Vue and React render a body fragment and call this to produce the full
// `<route>.html` document; Angular templates itself and does not use this
// helper. The mount-point and `<title>` markers match what the client build
// emits into index.html.

/** The client build's app mount point, replaced by the rendered body. */
const MOUNT_POINT = '<div id="app"></div>'

/**
 * Wrap `body` in the app mount point and set the document title in `template`.
 *
 * @param template The client build's `index.html`.
 * @param body The server-rendered markup for the mount point.
 * @param title The document title for this page.
 */
export function injectIntoTemplate(
  template: string,
  body: string,
  title: string,
): string {
  return template
    .replace(MOUNT_POINT, `<div id="app">${body}</div>`)
    .replace(/<title>[^<]*<\/title>/, `<title>${title}</title>`)
}
