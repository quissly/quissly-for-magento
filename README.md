# Quissly for Magento

Quissly's AI search, chat and product discovery for Magento Open Source and Adobe Commerce. When a
shopper searches, Quissly decides which products match and in what order; **your theme still shows
them**, exactly as it shows any search today. If Quissly ever cannot answer, Magento's own search
answers instead, so search never breaks.

**Who installs this:** your developer or sysadmin. Magento has no zip upload for modules, so the
install is done on the command line; everything after that is in the admin.

## Requirements

- **Magento Open Source or Adobe Commerce 2.4.7 or newer** (tested on 2.4.8), **PHP 8.2 or 8.3**.
- Magento's cron running (a standard Magento requirement): the catalog sync, shopping activity and
  updates run through it.
- Outbound HTTPS to `api.quissly.com` and `console.quissly.com`, and to GitHub (`github.com`,
  `api.github.com`, `raw.githubusercontent.com`) for automatic updates.
- An accurate server clock (NTP on): every request to Quissly is signed with the time, and a clock
  more than a minute off is refused.
- Nothing from Quissly in advance - Quissly Setup creates the account.

## Install

Download **[quissly-for-magento.zip](https://github.com/quissly/quissly-for-magento/releases/latest/download/quissly-for-magento.zip)**
(always the latest version) and unzip it into `app/code`, so the module ends up in
`app/code/Quissly/Search`. Then run Magento's standard setup:

```bash
curl -LO https://github.com/quissly/quissly-for-magento/releases/latest/download/quissly-for-magento.zip
unzip quissly-for-magento.zip -d app/code/

bin/magento module:enable Quissly_Search
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy   # production mode only
bin/magento cache:clean
```

There is no Composer step: the module needs nothing your Magento does not already have.

**Prefer Composer?** Every release is a tagged version of this repository:

```bash
composer config repositories.quissly vcs https://github.com/quissly/quissly-for-magento
composer require quissly/module-search
```

A Composer install is updated with `composer update quissly/module-search`; the automatic updates
leave it alone.

## Quissly Setup

Open the **Quissly** menu in the admin. Until setup is finished, every Quissly page opens
**Quissly Setup**:

1. **Your details** - the email for your Quissly account (pre-filled with yours; Quissly's account
   and billing emails go there, it cannot be changed later, and it must be on a real, public
   domain), your store name and a short "What do you sell?" (drafted from your catalog - check it).
   **Save & continue** creates your Quissly account and connects the store: a key pair is made and
   stored encrypted by Magento, and there is nothing to copy or paste.
2. **Choose a plan** - Search or Chat, monthly or annual, paid by **Manual invoice** (the default)
   or **Credit card**. The free search plan starts at once. Once chosen, the plan is changed in
   **Billing**.
3. **Go live** - Quissly builds your services (about a minute), then your products are sent;
   this starts by itself and the page updates without a refresh. When everything shows done, press
   **Finish Setup**: Quissly now answers your store's search (and, with a chat plan, the chat is
   switched on). **Do this later** finishes setup with search left off, to switch on later under
   *Enable AI Search*.

Search a product on your storefront: the results now come from Quissly. Add `?quissly_debug=1` to
a search address to see which engine answered, and why Quissly stepped aside when it did (the
badge shows only with that parameter).

**Connect works once per store.** Connecting again would create a second account and abandon
everything synced to the first, so it is refused; to move to another Quissly account, ask Quissly.

## The Quissly menu

- **Configuration** (Stores → Configuration → Quissly) - every switch and list below, in folding
  sections.
- **Dashboard** - per website: whether Quissly is live, the first catalog sync, products sent,
  connection health and both halves of every feature switch (yours and Quissly's).
- **Quissly Admin Panel** - Quissly's own console, signed in for you.
- **Billing** - your plans and this month's usage, invoices with their PDFs, change plan, buy
  extra requests, automatic top-up, cancel or keep a plan, update your card and request a refund.
  Every charge is shown before you confirm it; payments go through Paddle, never through Magento.

## Configuration

| Setting | Default | What it does |
|---|---|---|
| **Enable AI Search** | off until **Finish Setup** | Quissly answers storefront search. Locked until the first catalog sync is done. |
| **Immersive Search Overlay** | on | Clicking your search box opens Quissly's search bar over the page. A theme without a search box gets a search button either way. |
| **Search button mount point** | empty | Where that search button goes (a CSS selector); empty = beside your cart, account or language switcher. |
| **Enable Voice Search** | off | A microphone button in the search box. |
| **Enable Image Search** | off | Shoppers upload, snap or paste (Ctrl+V) a photo and get visually similar products. |
| **Quick Recommendations** | off | Product suggestions while typing, as a list or as cards. Quissly also has to enable it for your account - ask once your first sync is done. |
| **Enable QChat Widget** | off (on with a chat plan) | Quissly's chat; its *Add to Cart* puts products in your real Magento cart. The agent is found for you. |
| **Shopping activity for Quissly analytics** | on | Sends product views, searches, adds to cart and wishlist, and paid orders to Quissly's analytics (see *Privacy*). |
| **Search Examples** | generated | The example searches typed into the empty search bar, one list per store language, up to 20. Quissly writes them from your catalog after the first sync; edit, remove or reset them. |
| **Search Suggestions** | on, Automatic | Buttons under the empty search bar. *Automatic* shows what Quissly generated; *Manual* shows your own, up to 10 per language. |
| **Show recent searches** | on | The shopper's last 5 searches beside the suggestions, each with a bin to remove it. Kept only in the shopper's own browser, never sent anywhere. |
| **Most Loved Products** | off | 8 product cards in the overlay, picked by you (plus 4 backups that step in when a pick is sold out or hidden). Click a field to browse your catalog, drag to reorder. |
| **Catalog data** | brand, color, size, material... | Which product attributes are sent to Quissly (internal ones such as cost are never offered). Saving a change sends the whole catalog again. |
| **Update check frequency** | 30 minutes | How often the module looks for a new version (5 minutes for testing, up to a day, or **Never**). |

A switched-on feature that Quissly has not enabled for your account hides itself instead of sitting
there dead - the Dashboard shows which half is missing. Normal search is never affected.

A feature your plan does not include - the chat on a Search plan, or search on a Chat plan - is shown
off and greyed out with a link to Billing, and stays off on the storefront until you add it. Your
setting is kept for then.

## Updates

New versions install themselves. At the frequency above, the module checks Quissly's releases on
GitHub; a newer one is downloaded and **checked against Quissly's signature** (a file without it is
never installed). The store then goes into maintenance mode, the current module is moved to
`var/quissly/update/backup`, the new one is put in place and Magento's setup runs (`setup:upgrade`
and `cache:flush`, in production mode also `setup:di:compile` and `setup:static-content:deploy` -
about a minute of maintenance page on a small store). If any step fails, the previous version is put
back the same way. Settings, credentials and sync state live in the database and are kept.

- **Quissly > Dashboard > Updates** shows the installed version, whether automatic updates are on,
  the last check and the latest release, the last update - and, when one is not arriving, why. It also
  says which Magento and PHP versions the module works with, and flags a store running anything else.
- `bin/magento quissly:update` checks and installs now; `bin/magento quissly:update --status` shows
  what the last check found. Each update is written to `var/log/quissly.log`.
- A server that GitHub's API limits (common on shared hosting) still finds new versions.
- The module's files must be writable by the user cron runs as (Magento recommends cron runs as the
  files' owner).
- Not updated automatically: a Composer install, and a store whose files are a git checkout -
  unzip the new release over `app/code/Quissly/Search` and run the setup commands there.

## How search behaves

- **Order:** results come in Quissly's order for the default (Relevance) sort. A shopper's Price or
  Name sort - and a "Newest" option your theme adds with the code `created_at` - is done by Quissly
  across all the matches. Any other sort (Position, for one) is answered by Magento's own search, so
  the sort stays truthful. On a website with several store views, a Name sort is Quissly's only on
  the store view whose names are synced (the website's first); the others use Magento's own.
- **Filters:** a search refined with layered navigation is answered by Magento's own search, so the
  filters stay exact.
- **No results** shows your theme's normal "no results" page - that is Quissly's real answer.
- **The matched variant:** search "red tee" and the result shows, and opens on, the red one (the
  link carries `?quissly_variant=…`). On Hyvä themes the link preselects the variant but the tile
  shows the parent's image.
- **Changes appear after the sync catches up** - usually within minutes.
- **Search results pages are not kept in the full-page cache while Quissly answers**, so every
  search reaches Quissly. Every other page caches as before.

## Several websites

All settings, credentials included, follow Magento's scope switcher: set them once at Default for a
single website, or give each website its own Quissly connection.

> **Two websites that share products need separate Quissly services.** Products are identified by
> their Magento id, the same on every website, so two websites on one service overwrite each other's
> copy of every shared product (title, description, URL, stock - and prices are sent without a
> currency). The Dashboard warns when two websites point at the same service.

## Privacy

- To count a returning visitor once, a guest gets one first-party cookie on their first search,
  `quissly_uid` (a random id, one year). With *Cookie Restriction Mode* on (Stores → Configuration →
  General → Web → Default Cookie Settings), a guest who has not allowed cookies gets no cookie and
  is sent as anonymous.
- Each search tells Quissly `customer:<id>` for a signed-in customer or `guest:<random id>`, plus
  the device type and operating system. Shopping activity sends product ids, quantities, prices and
  order totals. **No name, email, address or IP address is ever sent.**
- Credentials are encrypted with Magento's own encryption and never reach the browser or the logs.

## Check the safety net (optional, two minutes)

Point the module at an address that does not answer, search, and confirm the store still shows
normal results (the debug badge then reads *Results by Magento search*):

```bash
bin/magento config:set quissly/connection/api_base_url https://127.0.0.1:9 && bin/magento cache:clean config
# search the storefront, then put it back:
bin/magento config:set quissly/connection/api_base_url "" && bin/magento cache:clean config
```

## Switching off and removing

- **Off for now:** switch *Enable AI Search* off - Magento's own search answers at once. Nothing is
  lost.
- **Remove:**

  ```bash
  bin/magento module:disable Quissly_Search
  rm -rf app/code/Quissly/Search
  bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
  ```

  This leaves the module's four tables (`quissly_*`) and its `quissly/*` configuration behind -
  Magento runs a module's own uninstall only for Composer installs. They do nothing once the module
  is gone; ask Quissly for the statements to remove them. Removing the configuration also removes
  the store's Quissly connection, which cannot be made again with the same email.

## Troubleshooting

| What you see | What to do |
|---|---|
| *Enable AI Search* is greyed out | The first catalog sync has not finished - the toggle says how many products are sent; follow it on the Dashboard. |
| "Quissly is not accepting catalog updates" | No active plan, credentials refused, or the server clock is off - the banner says which. Changed products stay queued. |
| Dashboard shows an authentication error | The store's credentials no longer match its Quissly account (for example after copying the configuration to another store). Contact Quissly - connecting again is refused. |
| Storefront unchanged | Caches: `bin/magento cache:clean`, and in production mode redeploy static content. Check with `?quissly_debug=1`. |
| Products missing from results | Check they are enabled, visible in search, in stock and on this website; then the sync progress. |
| An update is not arriving | Quissly > Dashboard > Updates, `bin/magento quissly:update --status` and `var/log/quissly.log` say why (GitHub unreachable, files not writable). |

`var/log/quissly.log` holds status codes and counts only - never shopper searches or credentials -
so it is safe to send to Quissly support.

## Support

Contact Quissly with your Magento version, the module version, what the Dashboard shows and the
relevant lines of `var/log/quissly.log`.
