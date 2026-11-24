# E-commerce Shop Demo Project

**Complexity: 5/5**

"Hilos Flowers": a flower shop on a real-time Hilos application — stock,
carts and deliveries that move on every open page at once.

## What is this?

### Today

A shop with a public storefront and the smallest complete Hilos project on
React. See `docs/new-project/README.md` to start a project from it.

- **Sign-in** by password, on the framework surface; registration and recovery
  confirm the address with a code sent by mail.
- **Home page**: the shop's categories as tiles in the administrators' order,
  each with its picture, description and how many products it has; tiles and
  numbers change by themselves, and with no category yet the page says the
  shop is being set up.
- **Category page**. Open a category from its home tile to see its description
  and live product catalog. Browse as tiles or a table with the same filters,
  price order and pagination. An unknown category shows a 404 page.
- **Admin dashboard** at `/hilos` with **Settings** (the three example keys
  and the file and upload limits), **Users** (the list and a person's card:
  rename, takeover and rights; no account merge), **Backup** (database
  archives) and **Maintenance** (the verifier circle). The other admin
  sections arrive with the leaves that bring them.
- **Shop administration**: a section of its own on the dashboard. Its
  **Warehouses** page lists every warehouse with how many products and units
  it keeps, and the numbers change by themselves as stock moves. Its
  **Categories** page lists the categories in catalog order, each with its
  picture, its description and how many products it holds, and that number
  changes by itself too. An administrator adds a category there in a dialog:
  a name, a description and a JPG or PNG picture uploaded right in the dialog;
  the new category takes the last place. The edit dialog can also replace the
  picture and offers Keep mine or Take theirs when edits conflict. From Edit,
  an administrator can delete an empty category after confirming. If the
  category has products, Edit explains why deletion is unavailable. Its
  **Products** page lists every product, recently
  changed first, with its category, kind, price, stock and availability; it
  filters by category, kind and availability, and the rows change by themselves.
  A row opens the product's own page: its kind, picture, description, category,
  price and what the warehouses hold of it, all of it live. The product's
  **Overview** has an **Edit** button for changing its name, category, price,
  description and picture. Only administrators see them; the shop's other admin
  pages arrive with their own leaves.
- **Notifications**: the bell in the header for a signed-in person, with
  delivery by email.
- **Public pages** About, Terms, Privacy and License, prerendered into the build.
- **Example catalog**: after a database reset the shop holds Hilos Flowers —
  3 warehouses, 6 categories and 30 products with their pictures and stock.

The backend is the base set: one app agent owning the connections and the
home page, the Hilos index agent with the dashboard, Maintenance, Backup,
Settings, Users and the footer pages, the settings library, the notifications
library, the backup agent, the files library with the uploads and image-copy
agents, and the framework sign-in libraries. It keeps tables of its own: the
warehouses, the categories with every address a category has had, the
products with every address a product has had, the stock — the units of each
product in each warehouse — the guests' carts — the products a browser
session without an account has put in its cart — and the vouchers that hold
the shop's discount codes. Each is held whole by its own library — the
warehouses library, the categories library, the products library, the stock
library, the guest carts library, the vouchers library — one for the whole
cluster, on the node the cluster picks. The carts of the people with an
account — the products a person who signed in has put in their cart — are
the one table held not by a library but by the agent of each person: one
agent per person writes that person's cart and erases it with the account.
Every address of the product page has an agent of its own: it comes up with
the first visitor of that address and goes to sleep after the last one has
left; it reads the product and its stock and writes nothing. The product page of the shop front that it serves arrives
with a change of its own. People, sessions, the tables of signing in and the
registry of files stay the framework's.

### What it is going to show

- **Storefront**: a one-of-a-kind item sold to somebody else leaves every open
  page at once.
- **Product page**: stock, and live counters of who is looking at it right now
  and how many carts hold it.
- **Search** at the top of every page, answering always — an AI chain first,
  words last.
- **Cart** that follows a visitor into the account they sign in to, with
  quantities and a voucher.
- **Checkout** into a parcel locker, paid on a stand-in payment page: no money
  is involved.
- **Orders**: the delivery of each moving step by step on its own, in the
  order and in the list of orders.
- **Shop administration**: warehouses, categories, products and their stock,
  vouchers and orders.
- **Your AI**: the shop's read-only tools over MCP — search, products, stock.

### The catalog

The model that shop administration is being built on. It is a plan, not the
running demo: what already works is listed under "Today" above.

- **Category**: name, description, picture and a place in the order of the
  home page; a new category goes last, and an administrator moves one up. Its
  address on the shop front, `/category/{slug}`, is made from the name. Every
  product is in exactly one category.
- **Product**: name, category, price, description, picture and an address
  `/product/{slug}`; of one of two kinds, chosen when the product is created
  and never changed. A "Unique piece" is one of a kind, sold once, no
  quantity: at most one unit, kept in one warehouse. A new one is not in a
  warehouse yet; an administrator puts it in one and can move it to another,
  and the shop front cannot sell it until it is in one. A "Stock item" has
  stock in warehouses, any number of units in each.
- **Warehouse**: name and address. Buyers never see it; administrators pick it
  when they set stock.
- **Stock**: the units of a product in a warehouse. A stock item has a count
  per warehouse, never below zero; a unique piece has one unit in one
  warehouse, or none yet. A product's total is the sum over its warehouses,
  and that is the number buyers see; a stock item with 5 units or fewer in
  total shows "Only N left" — one threshold for the admin and the shop front.

The rules:

- **The kind is chosen once.**
- **Only the empty is deleted**: a warehouse with no unit of any product in
  it, a category with no product in it. The judge of empty is the database's
  own reference: a stock row that names the warehouse, a product that names
  the category, refuses the delete. Of a write and a delete that land in the
  same moment, the database refuses one. There is no deleting a product.
- **An address follows the name**: renaming a category or a product moves its
  address. Its library keeps the former addresses of that record and never
  gives them to another one, so an old link never leads to something else —
  the shop front sends it on to the current address.

One entity, one library — the framework's rule,
[docs/agents/architecture/entity-libraries.md](../../docs/agents/architecture/entity-libraries.md).
Each library is one per cluster, and the cluster picks its node.

| Library | Owns | A delete is refused while |
|---|---|---|
| warehouses | warehouses | a stock row still names it — the database refuses |
| categories | categories, their order and their former addresses | a product is still in it — the database refuses |
| products | products, their kind and their former addresses | — |
| stock | the units per product and warehouse; product totals and each warehouse's counts of products and units come from here | — |

Stock is a library of its own because it is the number that changes most —
orders will take from it in later phases — and the products library does not
become the writer of those changes.

The shop's own admin pages live under `/hilos/app/` and are entered from the
"Shop administration" section of the dashboard at `/hilos`; administrators
only.

- `/hilos/app/warehouses` — Warehouses;
- `/hilos/app/categories` — Categories, in the order of the home page;
- `/hilos/app/products` — Products, recently changed first;
- `/hilos/app/product/{productId}` — a product's page; a stock item also has
  its Stock tab at `/hilos/app/product/{productId}/stock`, and a unique piece
  has no tabs.

Every list and every number on these pages changes by itself when another
window changes it.

### The shop front

What a buyer sees, built on the catalog above. It is a plan, not the running
demo: what already works is listed under "Today" above. The shop front is
open to a guest and to a signed-in person alike.

- **Home**, `/`: the categories as tiles in the administrators' order —
  picture, name, description and "N products"; a tile opens its category.
  With no category yet the page says "The shop is being set up".
- **A category**, `/category/{slug}`: breadcrumbs, name, description and its
  products in one live table shown two ways, "Table" and "Tiles" —
  [the framework's table views](../../docs/agents/frontend/table-subscription.md#the-table-and-tiles-views):
  a picture, "Product", "Kind", "Price" and "Availability"; a filter by name,
  by kind and by availability, an order by price ("Order" in Tiles), and
  pages. By default it shows every product whatever its availability: a stock
  item at zero stays with "Out of stock", and the availability filter is how
  a buyer hides what is not there.
- **A product**, `/product/{slug}`: breadcrumbs through its category,
  picture, name, kind, availability, price and description, and how many
  others are looking at it now.

Availability reads "One of a kind" for a unique piece, and "In stock: N",
"Only N left" or "Out of stock" for a stock item. An unknown category or
product is a 404.

Every open page follows the catalog by itself, without a reload:

- **Home**: a category added, renamed, moved or removed, and the number of
  products in each;
- **A category**: a row's price and availability; a new product of the
  category comes in through the table's live row;
- **A product**: its price, availability and total, description and picture,
  and the number of people looking at it.

A renamed category or product still opens at its old address: the page
answers that its address has moved — a framework answer, like a 404 — and the
browser shows it at the new address without a new entry in the history. A
page that is open during the rename moves by itself.

The page of a product is served by an agent of that one product, found by the
page's address —
[a page of one instance](../../docs/agents/signals/routing.md#per-instance-page-subscriptions).
It comes up with the first visitor and goes to sleep a while after the last
one leaves; it reads the product and its stock from their libraries and
writes nothing to them. It also counts the people looking at the page —
people, not tabs: two tabs of one person are one viewer, and a guest counts
by their session, wherever in the cluster they are connected. The product
page shows the count without the viewer — "N others are looking at it now"
or "Nobody else is looking at it now"; the administrators' product page shows
the whole count on its "On the shop front" line, and its "See in the shop"
opens the product here. After a rename the old address's agent answers that
the address has moved, and the count gathers again at the new address.

What the shop front does not show:

- **A unique piece that is "Not in a warehouse yet"**: it is not in its
  category, not counted in "N products", its page is a 404, and its
  administrators' page has no "See in the shop".
- **A sold unique piece**: its row leaves the category on every open page
  through the table's live row (selling itself arrives with payment).

What later phases bring is not on the shop front until then — no stand-ins
and no dimmed buttons:

- **With the cart**: "Add" and "In cart" in a category, "Add to cart" and
  "In your cart — go to cart" on a product, "In N carts" next to who is
  looking, and the cart with its count in the header;
- **With payment**: "Sold" for a unique piece;
- **With search**: the search field in the header and the search page.

### The cart

The cart that is being built on the shop front above. It is a plan, not the
running demo: what already works is listed under "Today" above. A guest has a
cart just as a signed-in person does: looking and putting things in the cart
need no sign-in.

- **A guest's cart** belongs to the browser session: every tab of that
  browser shares it, and it ends with the session — when the framework clears
  an ended session away, its cart goes with it.
- **An account's cart** is one per person: the same cart in every tab and on
  every device they are signed in on.

A cart line is a product and a quantity; a unique piece is always one unit
and has no quantity (the kinds are the catalog's above).

Each cart has one writer. Guests' carts are kept by the guest carts library —
one per cluster, and the cluster picks its node, like the catalog's libraries
above. An account's cart is kept by that person's agent, the one the
framework keeps for each person —
[the framework's rule for one person's rows](../../docs/agents/architecture/instance-owners.md#core-rule).
The rules are the same for both owners: adding puts one unit in; an exact
quantity is never above the stock — above it, the server refuses in its own
words; a unique piece that is "Not in a warehouse yet" cannot be added (the
shop front above does not show it either).

Every open tab knows its cart: when it opens, and after each change made from
any tab or device, the cart's owner tells it the whole cart. At sign-in and
sign-out a tab is told the cart of whoever it belongs to now — the account's
cart, shared by all of the person's tabs and devices, or the cart of the
browser session.

When a guest signs in, their cart joins the account's cart — the quantities
of a stock item add up, a unique piece already there is not doubled, and the
guest's cart is gone. A sum above the stock is kept as it is: the cart holds
no stock (below), and the cart page shows honestly what can be had. Each
owner writes only its own: the guest carts library hands the guest's cart to
the person's agent, the agent adds it to the account's cart, and only after
the agent's answer does the library drop its own. Signing out leaves the
browser a guest again, with an empty cart; the account's cart stays with the
account until the next sign-in.

Erasing an account erases its cart. When two accounts are merged, the cart of
the account merged away joins the cart of the account it is merged into, by
the same rule as at sign-in.

Putting a product in a cart sets nothing aside — the stock stays as it was,
and another buyer can still take the last unit; with payment, whoever pays
first gets it. A cart line follows the catalog by itself: a price or
availability changed in shop administration changes it in every open cart,
and a line that can no longer be had grays out, is not counted in the total,
and "Remove" takes it out.

What a buyer sees:

- **The header**: the cart with the number of units in it, for a guest too;
  it changes by itself when something is added in another tab or on another
  device, and leads to the cart page.
- **A product**: "Add to cart" puts one unit in, without a window — "Adds
  one. Change the quantity in the cart."; a unique piece already in the cart
  shows "In your cart — go to cart" instead; what cannot be had is grayed
  out. Next to who is looking, "In N carts" or "In nobody's cart yet" — how
  many buyers, guests and accounts alike, have it in their cart now; the
  administrators' product page shows the same number on its "On the shop
  front" line.
- **A category**: "Add" in a row, in "Table" and in "Tiles", puts one unit
  in; a unique piece already in the cart says "In cart" and leads to the
  cart; for a sold unique piece and a stock item at zero it is grayed out.
- **The cart page**, `/cart`, open to everyone: a line per product — picture,
  name leading to the product, price each and the line's total — then "Items"
  and "Total". "−" and "+" change a quantity by one, without a window; the
  number opens a "Quantity" window for an exact one; "Remove" takes a line
  out, without a window; a unique piece shows "1 · one of a kind" instead of
  a quantity. An empty cart says "Your cart is empty" and "Add something from
  the shop — it stays here", with "for this browser session" for a guest, and
  offers "Go shopping".

What later phases bring is not on the cart page until then — the cart does
not pass itself off as a purchase:

- **With payment**: "Checkout", the delivery line, and "Sold — someone paid
  first" on a line whose unique piece somebody else bought.

### Vouchers

The vouchers that are being built on the cart above. It is a plan, not the
running demo: what already works is listed under "Today" above.

- **A voucher** is a code and a discount. The code is letters and digits and
  is unique whatever the case: "spring10" and "SPRING10" are one code, and a
  buyer may type either. The discount is a whole percent, from 1 to 100.
- **On or off**: a switched-off code is refused in the cart. A voucher is
  never deleted — a switched-off one stays in the list, because orders will
  refer to it.

Vouchers are kept by the vouchers library — one per cluster, and the cluster
picks its node, like the catalog's libraries above; a voucher is created,
changed, and switched on and off only through it. The voucher applied to a
cart is remembered by that cart's own owner — the guest carts library for a
guest's cart, the person's agent for an account's cart (the owners are the
cart's above) — and the rules are the same for both.

A cart holds one voucher at most. A code is applied in any case; a new one
replaces the one already applied; an unknown or switched-off code is refused,
in the server's own words. Removing the voucher takes it off the cart.

The cart holds the voucher itself, not the text that was typed, and follows
it by itself in every open cart, wherever in the cluster that cart is kept: a
discount changed in shop administration changes the cart; a renamed code
shows under its new name; a code switched off after it was applied stays in
the cart, grayed out, and gives no discount — switched on again, the discount
comes back by itself. A voucher applied, replaced or removed in one tab shows
at once in every tab and on every device that has the same cart.

When a guest signs in, the guest's voucher, if there is one, replaces the
account's — the last one applied wins; without one, the account keeps its
own. Signing out leaves the browser a guest without a voucher; the account's
voucher stays with the account. When two accounts are merged, the same rule
holds: the voucher of the account merged away, if it has one, replaces the
voucher of the account it is merged into. A voucher goes with its cart:
erasing an account erases its voucher, and a guest's goes when the framework
clears the ended session away.

The discount is a percent of the lines counted in the total — a line that can
no longer be had is not one of them (the cart's above). It is rounded to two
decimals by one rule — [the shop's money](#money) — the same on the screen
and at the moment of purchase: "Total" on the screen is always "Items" less
the discount.

What people see:

- **The cart page**: under "Items", a line "Voucher" with the code, a "✕" and
  the discount taken off, and "Total" lower by it; the line of a switched-off
  voucher grays out and is not counted. "✕" removes the voucher, without a
  window. "Apply voucher" — "Apply another voucher" once one is applied —
  opens a window with "Voucher code", "Codes are not case-sensitive." and "One
  voucher per order. A new one replaces the one already applied."; the window
  closes on the server's answer, and a refused code stays in the field with
  the server's words above it.
- **Shop administration**: `/hilos/app/vouchers` — "Vouchers", entered from
  its card in the "Shop administration" section, "Discount codes buyers enter
  in the cart."; administrators only. A live table of "Code", "Discount" and
  "Active", searched by code ("Search codes"), filtered by "Active", in the
  order of "Code"; with no voucher yet it says "No vouchers yet" and "Add a
  code to give buyers a discount in the cart.". "Add voucher" and a row's
  pencil open one window — "Code" ("Buyers type it in the cart. Letters and
  digits; case does not matter."), "Discount, %" and "Active" ("A switched-off
  code is refused in the cart."); a code already taken in any case, or a
  discount out of bounds, is refused in the server's words and the window
  stays open. "Active" in a row switches a code off and on, without a window.
  There is no deleting.

Orders bring the rest: "Used" in the Vouchers table — how many paid orders
went through with the code — and the voucher in an order, with its percent.
Until then neither is on any page.

## Money

Money is whole kopecks (a hundredth of a hryvnia) in the database, on the
wire and in memory. A price of ₴250.50 is the number 25050 everywhere except
on a screen and in a field a person types in.

Only one module per side counts and prints it: `backend/Money/` and
`frontend/src/money/money.ts`. They count a price, a line's total, "Items", a
voucher's discount and "Total", and the text of an amount comes only from
there. No other file adds, multiplies, rounds or formats money.

An amount reads as ₴250, ₴2,525, ₴252.50, and a deduction as −₴250: kopecks
show only when there are some, and then always as two digits.

A discount is a percent of "Items", rounded to the kopeck, half up. This is
the one place in the shop where anything is rounded: a line's total, "Items"
and "Total" are exact whole-number arithmetic. "Total" is "Items" minus the
discount, and the same rule gives the same number on the screen and at the
moment of purchase. A rounded figure is never stored before the purchase: the
discount and "Total" are counted again each time they are shown.

A price typed into a field goes to the server as typed, and the backend
module reads it: digits, spaces between thousands, a point or a comma, and at
most two digits of kopecks — "410", "2 100", "2 100.50", "99,90". Anything
else is refused in the server's own words. A comma is a decimal sign, not a
thousands mark, so "2,100" is refused rather than read as 2.10.

Two guards keep the rest of the shop out of money. On the backend it is the
unit test `tests/Unit/MoneyGuardTest.php`; on the frontend, a lint rule in
`frontend/eslint.config.mjs`. The backend guard judges every `.php` file under
`backend/` except `backend/Money/`; the frontend guard judges `frontend/src/`
except `frontend/src/money/`. Tests, SQL seeds, this README and comments are
not judged. Both find:

- the sign "₴" in text;
- a number printed with a format: `number_format`, `NumberFormatter` or a
  float `printf` format on the backend; `toFixed`, `toPrecision` or
  `Intl.NumberFormat` on the frontend;
- rounding: `round`, `floor`, `ceil`, `intdiv` and the like;
- the literal 100 as an operand of `*`, `/` or `%`;
- a money value in arithmetic: `+`, `-`, `*`, `/`, `%`, `**`, their
  assignment forms, `++`, `--` and unary minus;
- a money value printed raw: concatenated or put inside a string or template,
  turned into text by `String()`, `toString()` or `toLocaleString()`, or, on
  the frontend, put as a JSX child (`<td>{row.price}</td>`).

Comparisons, assignment, passing a value as an argument and keeping it under
an array key are allowed.

The guards know money by its name: a name with price, discount, kopecks or
money in it. Name a money value for what it is. A share in percent carries
the word percent (`discountPercent`) and is not money. A green guard is not
proof: money under another name, such as `total`, is not seen. An exception
is an address with its reason written in the guard itself; there are none
today.

Both sides are checked by one table of examples, `tests/money-cases.json`.

## Change Log

The demo declares `CHANGE_LOG` and journals exactly `hilos_user`, `hilos_identity`,
`hilos_second_factor`, `hilos_setting`, `warehouse`, `category`, `product`,
`product_warehouse_stock` and `voucher`, the shop's own tables.
The journal is the primary database name plus `-change-log`, on the same MariaDB server with the same host, port, user,
password and charset. No additional environment keys are needed. The primary
migration track creates its six tables; connection 1 runs no separate migrator.
Backup includes the pair and the journal's personal-data verdicts.

Before updating an existing local volume, provision the derived database once
(or repeat the same command safely). Run from this demo's directory:

```bash
docker compose -f docker/docker-compose.local.yml exec ecommerce-shop-mysql-local \
  /usr/local/bin/provision-change-log.sh
```

This only creates a missing database and grants access; it does not reset or
remove existing data. Fresh local and test databases run the same provisioning
from the MariaDB init directory. Local keeps its volume; test keeps its tmpfs.
The framework's shared `test:db:reset`, invoked through
`test:stand:reset` by `composer run test:db-reset`, recreates both test databases because the demo
declares `CHANGE_LOG`. To regenerate triggers
on the test stand after migrations, use `composer run test:change-log:generate`;
repeating it leaves matching files untouched. Commit the complete generated set
before starting a daemon. Startup prepares monthly partitions and installs the
files under its existing schema rollout claim; a repeated start keeps matches.

For dev with an external MariaDB, its operator provisions the derived database
before startup using the actual primary name, application user and host:

```sql
CREATE DATABASE IF NOT EXISTS `<primary>-change-log`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `<escaped-primary>-change-log`.* TO '<app-user>'@'<app-host>';
```

Escape `_` and `%` in the database identifier used by `GRANT`, where they are
patterns even inside backticks. The provisioning script validates names and the
64-character limit and performs that escaping. It never clears a database.

The backend registers all eight Change Log pages and their table bindings.
The React views mount Overview at `/hilos/change-log`, Feed at
`/hilos/change-log/feed`, Tracked tables at `/hilos/change-log/tables`,
and Noisy columns at `/hilos/change-log/noisy`. A table's Overview is at
`/hilos/change-log/table/{tableId}`, with Columns at `/columns`,
Triggers at `/triggers`, and History at `/history/{record?}` beneath that address.

## First run

All tooling runs in project-defined docker containers — never on the host
(`docs/agents/frontend/build-and-docker.md`).

```bash
composer run setup-env          # create .env from .env.example (tests/.env is tracked)
composer run install-deps       # composer install in the PHP container
# once per checkout, from the repo root: install the framework SDK npm workspace.
# @hilos/* are linked via file: symlinks, so their deps (e.g. zod) resolve against
# framework/frontend/node_modules — without this the dev server fails with
# "Failed to resolve import zod":
#   docker compose -f framework/docker/docker-compose.frontend.yml \
#     run --rm hilos-frontend-cli npm install
composer run frontend:install   # npm install in the frontend container
composer run daemon-start       # MySQL + Mailpit + daemon + Vite dev server
```

The daemon applies migrations on startup. Endpoints (defaults):

| Endpoint | URL |
|---|---|
| Frontend dev server (HMR) | http://localhost:5177 |
| Built frontend behind nginx (`daemon-start-build`) | https://localhost:8136 |
| Mailpit (the codes a registration and a recovery send) | http://localhost:8140 |
| Daemon status API | http://localhost:8130/status |
| Daemon WebSocket | ws://localhost:8132 |
| MySQL (from host) | localhost:33069 |

An administrator is made from the command line: `composer run cli -- admin:create
<sessionToken>` makes a browser session an administrator, minting its account when
it has none, and `admin:grant` / `admin:revoke` move the flag on an existing one
(`docs/agents/cli/commands.md`).

These host-side ports are compose *interpolation* values, so `.env` cannot
change them — compose reads them from the shell environment or from
`docker/.env` before any container exists (`HTTP_STATUS_HOST_PORT=18130 composer
run daemon-start`). A port written into `.env` is silently ignored: that file is
the container `env_file`.

## Stack commands

| Command | What it does |
|---|---|
| `composer run daemon-start` | start the local stack (MySQL, Mailpit, daemon, Vite dev server) |
| `composer run daemon-start-build` | the same plus the prod-parity nginx over the built artifact |
| `composer run daemon-stop` | stop the local stack |
| `composer run daemon-restart` | restart the daemon container |
| `composer run daemon-status` | daemon status via the CLI |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`tsc`) |
| `composer run frontend:lint` | lint the frontend (`eslint . --max-warnings 0`) |
| `composer run frontend:format-check` | check frontend formatting (`prettier --check .`) |
| `composer run frontend:build` | production build into `frontend/dist` |
| `composer run frontend:logs` | follow the dev-server logs |

## Database commands

| Command | What it does |
|---|---|
| `composer run db:migration:up` | apply pending migrations |
| `composer run db:migration:down` | roll back the last migration |
| `composer run db:migration:status` | migration status |
| `composer run db:schema:status` | compare schema against entities |
| `composer run db:seed:apply -- 001` | replace the Hilos Flowers catalog and clear product lines from guest and account carts. Stop the daemon before running or rerunning this seed. |
| `composer run db:seed:apply -- 002` | add the missing example vouchers; the daemon stopped (beside a running one the command refuses) |

Rerunning seed `001` keeps saved vouchers in both carts and leaves uploaded files on disk.
Existing history retains its original catalog IDs, which the seed can reuse for the replacement
catalog. Use an empty local database for a clean shop.

## PHP tests

| Command | What it does |
|---|---|
| `composer run test:install-deps` | install the locked PHP dependencies in the test CLI container |
| `composer run test:unit` | run the unit suite (topology registry guard, money guard) |
| `composer run test:phpunit` | run the whole PHPUnit suite |

## End-to-end tests

e2e runs against the **built** frontend artifact served by the prod-parity
nginx (TLS, `/ws` upgrade proxy) with a booted daemon behind it
(`docs/agents/frontend/testing-strategy.md`). Agent flow:
`composer run test:e2e-full -- <spec>` or `-- --grep "…"` — the full clean cycle
pointed at a subset.

| Command | What it does |
|---|---|
| `composer run test:check` | install + typecheck, unit-test, lint and format-check the frontend app; lint and format-check the e2e suite (test toolchain) |
| `composer run test:e2e-build` | install + build the frontend for the test stack |
| `composer run test:e2e-install` | install the Playwright deps |
| `composer run test:e2e-check` | typecheck the e2e test code (in the runner) |
| `composer run test:e2e-up` | start the e2e stack: MySQL (reset) + daemon + nginx |
| `composer run test:e2e` | run the e2e suite (`-- --grep "..."` filters) |
| `composer run test:e2e-down` | tear the e2e stack down |
| `composer run test:e2e-full` | build → install → check → up → test → down; `-- <spec>` or `-- --grep "…"` points the same clean cycle at a subset |

## Cluster stand

`docker/docker-compose.cluster.yml` raises this demo as a Hilos cluster of one
master and two slaves. `m1` is the whole master set and declares no capacity,
so it takes no placed work and stays the leader for as long as it lives:
placement and failover are proved where the leader is stable. The slaves are of
unequal room on purpose — `s1` declares `worker,ram=10,slots=64`, `s2`
`worker,ram=4,slots=64` — because scenario 18 fills them by the room they declare,
not by their number; the slots are what lets them take placed work at all.
All three share one MariaDB 11.4 server, `ecommerce-shop-cluster-mysql`,
with the primary database and its derived journal. It is the only database
address the nodes and cli know. The local aggregate uses default subnet
`10.222.0.0/16`, publishes nothing on the host and has a compose project of its own,
`hilos-ecommerce-shop-cluster`, because the e2e steps take their whole project
down when they start.

Nothing here drives the stand: the framework's shared cluster harness
(`framework/docker/cluster/`) reads the nodes out of the compose file and runs
the scenarios it names — 3 placement, 4 slave-kill failover, 6 hot-join,
9 daemon-crash self-heal, 12 rt replication, 14 rt claim refused,
16 recreated node leaves no phantom fleet, 18 capacity is consumed,
19 worker death on a live node, 37 costless agents consume slots, and
39 person agents across nodes (skipped as flaky since 2026-10-17),
43 waiting work retries when a node returns
(`../../docs/agents/testing/cluster-stands.md`, "The cluster stands — six demos, six shapes"). The framework's probe fleet, claimer and ballast
are in this demo's `AGENTS`, and they start only here: on one node, on the
Playwright stand and in production the rows are carried and nothing is run.

Scenario 4 kills the slave that holds both real fleet work and one person's
owner. The whole fleet and that same owner move to the surviving slave; exact
numeric person and second-factor moments survive. `second-factor:unlock` then
clears the lock through the new owner, preserving the reset-wait sentinel and
advancing `updated_at` within the command's observed interval. Cleanup removes
the owned fixture and restarts the original stand without deleting volumes.

The browser acceptance run uses `docker/docker-compose.cluster-browser.yml`.
It adds a second master so the author and observer can enter through different
masters. Live checks the placements of the warehouse, category, product and
stock libraries and observes changes to a warehouse address, a category name
and stock across the two connections. The harness stops the slave holding
`stock_library`. On the same seeded database, after-loss checks that both
masters retain quorum, finds the library on the surviving slave, and verifies a
second stock edit against the totals in the observer's open window.

| Command | What it does |
|---|---|
| `composer run test:cluster:up` | build/recreate the default database, three nodes and CLI, retaining data and protected mode |
| `composer run test:cluster:restart` | ordinary down/up with current code, retaining both databases and protected mode |
| `composer run test:cluster:status` | the containers and one line of each node's view: phase, leader, placements |
| `composer run test:cluster:inspect -- m1` | inspect one node as JSON |
| `composer run test:cluster:logs -- m1` | follow one node's container logs; Ctrl-C stops following |
| `composer run test:cluster:client -- m1 test:cluster:inspect` | run an existing CLI command through the selected node |
| `composer run test:cluster:scenarios` | the stand's scenario matrix on a fresh stand; `-- 14 18` runs only the ones named |
| `composer run test:cluster:e2e` | run live and after-loss together on a fresh browser stand and require the current run's after-loss confirmation |
| `composer run test:cluster:down-volumes` | take the stand down and wipe its primary and journal databases |
| `composer run test:cluster:down` | stop the stand, retaining database data and protected-mode stores |

For a direct browser run, build the shop's `frontend/dist` with
`composer run test:e2e-build` if needed, then run `composer run test:cluster:e2e`
from this demo. Save the reports under `tests/e2e/playwright-report/cluster-*`
and the node journals under `data/logs-cluster/` before the next run. After
either outcome, remove the browser stand and its test data from this directory:

```bash
../../framework/docker/cluster/run docker/docker-compose.cluster-browser.yml down --volumes
```

The two configurations share an instance suffix, Compose project and network.
Finish and tear down one before starting the other. In the full suite,
`ecommerce-shop-cluster-e2e` waits for `ecommerce-shop-e2e` to finish writing
the frontend build, dependencies and reports. Both cluster steps share the
`ecommerce-shop-cluster` group and each collects evidence and tears down its
own stand on success or failure.

Scenario 43 stops both slaves after the ordinary fleet starts. The lone master
declares no capacity, so one uniquely indexed ballast stays `unplaced` with
`nodeId=null` and a `no_declared_capacity` refusal for that master. A second ask
keeps one record. Returning one slave starts the waiting ballast without another
ask; the leader logs waiting and resumed once each. Cleanup restarts the whole
stand without deleting database volumes, restores both slaves and the ordinary
fleet, and removes the extra ballast demand. It runs before 37 and 18; prove
that cleanup against both capacity scenarios on one fresh stand from the root:

```bash
composer -d demo/ecommerce-shop run test:cluster:scenarios -- 43 37 18
```

The companion queue-inheritance proof is scenario 44 on binance-btc-tracker's
three-master stand. It fills both slaves' slots with extra fleet indices,
checks the waiting record's complete snapshot on every surviving master before
killing the leader, then recovers it through re-election and quorum loss.
Giving one slave room starts that target without another ask. Both scenarios
restore the whole original stand without deleting database volumes; 44 runs
after the ordinary leadership scenarios and before later binance scenarios.

Run scenario 39 alone on a fresh stand, from `demo/ecommerce-shop`:

```bash
composer run test:cluster:scenarios -- 39
```

In the full matrix, 39 runs after 9. It raises one person's agent on `s1`,
waits for its ordinary **240-second idle window**, then wakes the same index
on `s2` while the people and sessions libraries remain on `s1`. The idle
check requires both the worker log's stop reason and the placement's removal;
it does not shorten the product timeout. Killing `s2` then moves the active
owner back to `s1`.

The checks use real `admin:grant` / `admin:revoke` row writes and
`second-factor:unlock` writes to the person's child set, with expected CLI
replies and an independent database oracle. The first unlock clears a real
lock; after wake-up and failover, unlock proves the set's Update permission
again and advances `updated_at`. Confirmed facts survive idle and host loss,
the reset-wait sentinel stays intact, and a second person's rows stay unchanged.
Cleanup removes only the fixtures, restarts the same stand without deleting
volumes, and waits for the full mesh and fleet before the next scenario.

The harness commands — `kill`, `partition`, `crash-daemon`, `kill-worker`,
`freeze-worker`, `recreate`, `inspect` and the rest — use the shell entry point
from this demo directory. It builds and runs a disposable container, using the
current repository through a mount. The first invocation builds its image
automatically; later builds use Docker's normal cache. It runs as your UID:GID
and mounts the selected local Docker socket with its numeric group. This path
needs ordinary Linux shell utilities, the Docker CLI and an accessible local
Unix Docker daemon; it does not need host Python, PHP, Composer or a Compose
plugin. Docker Desktop, rootless, SSH and multi-machine daemons are outside the
supported target. See the
[shared runner details](../../docs/agents/testing/cluster-harness.md#cluster-harness-runner).

The existing Composer command names above remain convenient entry points.
`composer run test:cluster:down` still uses `../../scripts/down-stands.php`;
direct `down` below is a standalone path.

```bash
../../framework/docker/cluster/run docker/docker-compose.cluster.yml up
../../framework/docker/cluster/run docker/docker-compose.cluster.yml status
../../framework/docker/cluster/run docker/docker-compose.cluster.yml inspect m1
../../framework/docker/cluster/run docker/docker-compose.cluster.yml down
```

**Destructive:** `down --volumes` deletes the stand's database data. Run it
only when that deletion is intended:

```bash
../../framework/docker/cluster/run docker/docker-compose.cluster.yml down --volumes
```

### Compose layout and lifecycle

The root includes one merged pair, `docker/cluster/local.yml` and
`docker/cluster/tooling.yml`. Local services extend the canonical `database.yml`
and `node.yml` there; the canonical services add no extra nodes. Compose 5.6.1,
pinned by the harness, resolves `ports: !reset []` and per-node
`env_file: !override`. Each local node loads tracked `.env.example`,
`common.env` and its fixed `nodes/<id>.env`. The CLI is default-on, with
`APP_ENV=test` and explicit cluster DB/command settings; nodes use `APP_ENV=dev`.
The CLI mounts tracked `tests/.env` read-only at `/app/tests/.env`,
inside `/app/tests` tmpfs so Docker cannot create a missing mount target on the
host bind. Test suites keep their own services; no host env file is copied or
overwritten. The scenario configuration runs the CLI and three nodes without a browser entry.

The browser root also includes `docker/cluster/browser.yml`. On a fresh browser database, MariaDB marks
fixture preparation pending in its data directory. The seed service runs
`test:db:reset` and renames that marker to `ready` on success. All four nodes
wait for the seed service. A restart retries an interrupted seed or keeps a
completed fixture and its journal. An existing database without either marker
is preserved and startup stops. The harness's `down --volumes` clears the
database and its marker for a fresh run.
The nginx entry serves the React build and routes each browser's WebSocket to
the master selected by its `hilos_stand_master` cookie. The `e2e` profile
provides Mailpit and the Playwright runner on the cluster network.

From this demo directory, ordinary startup is one direct Compose command:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --build --force-recreate
```

`test:cluster:up` runs that command without a separate all-profile build or log
cleanup. Ordinary down/up and `test:cluster:restart` preserve primary/journal
rows and protected-mode stores. A saved manual maintenance window remains
`verifying`. Inspect it with
`composer run test:cluster:client -- m1 protected-mode:inspect`; manual
maintenance opens with `maintenance:disable`, a test freeze with
`test:protected-mode:open`, and a backup restore with `protected-mode:open`,
through the recorded initiator and the same client alias.

Every new scenario invocation checks its prerequisites, resets the database
once with `down --volumes`, starts the stand and opens recognized saved protected
mode through its owner before fleet readiness. Unknown identities, malformed
stores/replies, refusals and timeouts fail startup. No global reset is inserted
between scenarios or retries, and there is no `--keep-db` mode: a new matrix
discards accumulated ordinary fixtures.

Names and the `/dev/shm` DB directory take `HILOS_STAND_SUFFIX`; the subnet takes
`HILOS_STAND_NET_ECOMMERCE_SHOP_CLUSTER`, and CPU weight takes
`HILOS_STAND_CPU_SHARES`. See the shared guide's
[stand instances](../../docs/agents/testing/stand-compose.md)
and [cluster lifecycle](../../docs/agents/testing/cluster-harness.md#cluster-compose-model).

### Standalone components

Copy `docker/cluster/standalone.env.example` outside the checkout, edit its
paths/endpoints and prepare the selected runtime file from
`docker/cluster/nodes/m1.env`. From this demo directory:

```bash
docker compose --env-file /srv/hilos/ecommerce.env -p ecommerce-db -f docker/cluster/database.yml config
docker compose --env-file /srv/hilos/ecommerce.env -p ecommerce-m1 -f docker/cluster/node.yml config
# After provisioning the inputs, start only this node on its server:
docker compose --env-file /srv/hilos/ecommerce.env -p ecommerce-m1 -f docker/cluster/node.yml up -d --build
```

`--env-file` supplies Compose interpolation; `HILOS_CLUSTER_NODE_ENV` selects
the separate runtime file loaded after `common.env`. Build/bind paths resolve
from `docker/cluster`. The example binds on `0.0.0.0` and publishes DB `3306`,
peer `18095`, WebSocket `18092` and HTTP `18090`; command and worker IPC ports
stay private. Prepare reachable DB/advertise/seed endpoints, excluding the
node itself from seeds, matching mutual-TLS files and built dependencies.
Each slave needs its own runtime identity and capability values. Supply trusted
proxy ranges and shared storage if the deployed application uses them; this
headless stand supplies neither a proxy entry nor an export-storage mount.
These files provision no remote hosts or storage. Standalone config resolution
has been checked; real multi-host deployment has not been tested, and the
harness remains a local single-host runner.

### Database

One MariaDB 11.4 server holds `hilos-demo-ecommerce-shop-cluster` and
`hilos-demo-ecommerce-shop-cluster-change-log`. The fresh data directory
runs `docker/mysql/init-change-log.sh`, mounted at
`/docker-entrypoint-initdb.d/init-change-log.sh`; it calls
`docker/mysql/provision-change-log.sh`, mounted at
`/usr/local/bin/provision-change-log.sh`. The existing app user receives access
to the derived journal before any Hilos node starts. The nodes then apply the
primary migration track, prepare the journal's partitions and install its
triggers under the existing schema rollout claim. The journal has exactly six
tables and no migration bookkeeping of its own.

Before replacing an older cluster compose file, stop that stand using the old
file, without `--volumes`, so removed services cannot become orphan containers:

```bash
docker compose -f docker/docker-compose.cluster.yml --profile '*' down
```

For an existing ordinary MariaDB data directory, provision the journal as root
before starting Hilos with the new code. From `demo/ecommerce-shop`:

```bash
docker compose -f docker/docker-compose.cluster.yml up -d --wait ecommerce-shop-cluster-mysql
docker compose -f docker/docker-compose.cluster.yml exec ecommerce-shop-cluster-mysql \
  /usr/local/bin/provision-change-log.sh
composer run test:cluster:up
```

Provisioning uses the existing `MYSQL_DATABASE`, `MYSQL_USER` and
`MYSQL_ROOT_PASSWORD`. It is repeatable: it creates a missing journal and
refreshes its grant without DROP or reset. Provisioning again and restarting
on the same data directory preserve both primary and journal rows. The stand
mounts `/dev/shm/hilos-ecommerce-shop-cluster-mysql-data` and the shared
`test-stand.cnf`; `test:cluster:down` preserves that data, while
`test:cluster:down-volumes` and each fresh scenario run clear it. Local and test
volumes are separate and are not part of this transition. `/dev/shm` is host
memory: container replacement retains it, but a host reboot clears it.

Historical migration: the former replica is removed by the old compose file's `down`; its data
stays at `/dev/shm/hilos-ecommerce-shop-cluster-replica-data`. Scenario 31
remains in the shared harness; its planned carrier is the file-gallery-manager
cluster stand
([The cluster stands](../../docs/agents/testing/cluster-stands.md#the-cluster-stands--six-demos-six-shapes));
replication is not a passing check of this stand.

### TLS fixtures

`docker/tls/` holds the certificates of this stand, and they are **stand
fixtures only**: the authority behind them signs nothing else, its key is not
in the repository, and it is not the authority of any other stand on the same
machine. Nothing here is a template for a real cluster — issue your own.

| File | What it is | Used by |
|---|---|---|
| `ca.pem` | the stand authority's certificate, no key | `CLUSTER_TLS_CA_FILE` of every cluster node |
| `m1.pem`, `m2.pem`, `s1.pem`, `s2.pem` | a node certificate (CN = node id) followed by its key | `CLUSTER_TLS_CERT_FILE` of that node |

Both configurations use the same authority. Only the browser configuration
starts m2, so include its certificate when reissuing the fixtures.

Reissuing means a new set in full: the old authority's key is gone, so no
single file can be replaced alone. The commands run in the image of the
stand's cli container, which exists after the first
`composer -d demo/ecommerce-shop run test:cluster:up` (or `docker compose -f
demo/ecommerce-shop/docker/docker-compose.cluster.yml build
ecommerce-shop-cluster-cli`). The framework's own commands print PEM to stdout
and the host shell writes the files, so they come out owned by you rather than
root. Keep the authority file (`cluster-ca.pem`) outside the repository and
delete it when done — from the repository root:

```bash
cli() {  # one framework CLI command in a throwaway container, no network needed
  docker run --rm --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/demo/ecommerce-shop":/app:ro -v "$PWD/composer.json":/hilos/composer.json:ro \
    -v "$PWD/composer.lock":/hilos/composer.lock:ro -v "$PWD/framework":/hilos/framework:ro \
    -v /tmp/cluster-ca:/ca:ro -w /app -e APP_ENV=dev \
    hilos-ecommerce-shop-cluster-ecommerce-shop-cluster-cli:latest php backend/Bootstrap/cli.php "$@"
}
mkdir -p /tmp/cluster-ca && t=demo/ecommerce-shop/docker/tls
cli cluster:tls:ca > /tmp/cluster-ca/cluster-ca.pem
cli cluster:tls:trust /ca/cluster-ca.pem > $t/ca.pem
for n in m1 m2 s1 s2; do cli cluster:tls:issue $n /ca/cluster-ca.pem > $t/$n.pem; done
rm -r /tmp/cluster-ca
```

The node certificates are valid for ten years (the authority's lifetime); a
node warns in its log from 30 days before the end.

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
