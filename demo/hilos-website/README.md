# Hilos Website Demo Project

**Complexity: 1/5**

The official Hilos site at hilos-dev.org, itself an application on Hilos: what
Hilos is, the WAWTech video and the catalog of the demos.

## What is this?

### Today

There is no code yet: the directory holds only `composer.json`,
`docker/Dockerfile`, `LICENSE`, `.gitignore` and this README. A thin shell on
Vue comes first.

### What it is going to show

- **Home** at `/`: a short account of what Hilos is and what the demos show,
  and the WAWTech video about Hilos.
- **Demo catalog**: two groups — the filled demos (chat, ecommerce-shop) on
  top, and the scaffolds apart below them: the other demos that have a
  frontend. An entry carries the name, a short description, the frontend, a
  link to the application and a link to the sources. A demo without a public
  address says so on its own entry — a note, not a third group. A demo
  directory without code is not shown.
- **Catalog in code**: the HTML sits right in the code of the home page and
  changes by editing the code and rebuilding. There is no CMS, no editor, no
  editing of the catalog from the admin and no second language.
- **The video file** is not in git: it lies in `media/` of the installation's
  data and is served at `/media/`.
- **Owner-only admin**: sign-in only at `/hilos`, with no sign-in button in the
  header; there is no registration — the single account is issued by a
  command; the admin carries the Analytics section — the visits of the site.
- **Public pages** About, Terms, Privacy and License; Privacy says that the
  site records visits.
- **Open to search engines**: the home page is prerendered into the build — the
  catalog is in the page source without JavaScript.

## Thin on purpose

The site carries only the owner's sign-in and Analytics. A change that switches
a framework feature on in every demo leaves this one out, and the site has no
cluster stand. The thinner the site, the cheaper its move.

## Its own repository

Planned: the site moves to its own repository, `hilos-dev/website`, and
installs Hilos as a package (HIL-2153).

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
