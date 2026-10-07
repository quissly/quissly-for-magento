# Quissly for Magento

> **Status: pre-release.** The module is built and live-tested. Download it from the
> [latest release](https://github.com/quissly/quissly-for-magento/releases/latest) (see
> *Install* below); after that it keeps itself up to date (see *Updates*).

Quissly for Magento replaces your store's search results with AI-powered product
discovery from [Quissly](https://quissly.com), while your theme keeps rendering
everything exactly as before. Shoppers type in the same search box; better results come
back. If Quissly is ever unreachable, your store silently falls back to Magento's native
search - search never breaks.

**Who installs this:** your developer or sysadmin (command-line access to the Magento
installation is required - Magento has no zip-upload module install).

## At a glance

1. Unzip the module into `app/code` and run the standard Magento setup commands. Later
   versions install themselves (see *Updates*).
2. Open the **Quissly** menu. Until setup is done, every entry opens **Quissly Setup**:
   three steps, in the Shopify app's design.
   - **Your details** - your email (pre-filled) and store name, then **Connect &
     continue**. Nothing to copy or paste: the module creates the account and stores the
     credentials itself.
   - **Choose a plan** - Quissly's plans. The free search plan starts at once; a paid
     plan opens Quissly's payment page in a new tab.
   - **Go live** - the first catalog sync starts on its own and its progress shows here.
     When it has finished, **Finish Setup** switches Quissly search on (or **Save changes**
     finishes setup and leaves search off, to switch on later in Configuration).
3. Search on your storefront - results are now Quissly-ranked. Configuration, the
   Dashboard and the Quissly Admin Panel open as usual from then on.

## Prerequisites

- Magento Open Source or Adobe Commerce **2.4.7+**, PHP 8.2/8.3.
- Nothing from Quissly in advance. The Connect button creates the account for you; a
  Quissly plan is needed before the catalog sync will be accepted.
- Outbound HTTPS from your server to `api.quissly.com`.
- **An accurate server clock (NTP).** Every request is cryptographically signed with a
  timestamp valid for ±60 seconds - a drifting clock makes Quissly reject requests in a
  way that looks like broken credentials.
- Cron running (standard Magento requirement) - the catalog sync and the automatic
  updates run through it.
- For automatic updates: outbound HTTPS to `api.github.com` and `github.com`, and the
  module's files writable by the user cron runs as (Magento's own recommendation: cron runs
  as the files' owner).

## What the module changes on your system

- One module under `app/code/Quissly/Search`. An automatic update replaces it and keeps the
  previous version in `var/quissly/update/backup` until the next one.
- Configuration entries under the `quissly/*` path (credentials stored encrypted by
  Magento's own encryption).
- Three small database tables for the sync queue, sync state and the stock snapshot.
- Footer script tags for the storefront features that are switched on - the search overlay
  once AI Search is live, and Quick, voice, image and QChat when you enable them. Nothing
  is emitted for a feature that is off.
- One first-party cookie, `quissly_uid` (a random id, one year, HttpOnly), set on a guest's
  first search so Quissly's analytics count a returning visitor once. It is not set while
  your cookie notice (*Cookie Restriction Mode*) has not been accepted - see Security notes.
- **Search results pages are no longer kept in the full-page cache while AI Search is
  live**, so every search reaches Quissly and is counted, with who searched. Every other
  page caches exactly as before, and with AI Search off search pages cache as before too.
- **No theme files and no template overrides.** On Quissly-served search results the module
  makes one visible change: a result shows, and opens on, the variant that matched -
  search "red tee" and you get the red one's picture and the red one preselected.
  Everything else renders exactly as before.

## Install

Download `quissly-for-magento.zip` from the
[latest release](https://github.com/quissly/quissly-for-magento/releases/latest). There is
no package repository to configure and no account to create. Magento has no zip-upload
screen, so the install itself is done from the command line.

Unzip the archive into your Magento installation's `app/code` directory - the module
must end up at `app/code/Quissly/Search` - then run the standard setup commands:

```bash
curl -LO https://github.com/quissly/quissly-for-magento/releases/latest/download/quissly-for-magento.zip
unzip quissly-for-magento.zip -d app/code/

bin/magento module:enable Quissly_Search
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy   # production mode only
bin/magento cache:clean
```

There is no Composer step. The module needs nothing your Magento installation does not
already ship, so there is nothing to resolve or download.

**Prefer Composer?** Every release is a tagged version of the public repository:

```bash
composer config repositories.quissly vcs https://github.com/quissly/quissly-for-magento
composer require quissly/module-search
```

A Composer install is updated with `composer update quissly/module-search`; the automatic
updates below leave it alone.

### Updates

The module checks for a newer release once a day (cron) and installs it by itself:

1. It downloads the release zip from GitHub and checks it: **signed by Quissly** (a zip
   without Quissly's signature is never installed, whoever published it), a complete
   Quissly module, of the version announced.
2. It puts the store in **maintenance mode** (unless it already was), moves the current
   module to `var/quissly/update/backup` and puts the new one in its place.
3. It runs `setup:upgrade` and `cache:flush` - in production mode also
   `setup:di:compile` and `setup:static-content:deploy` (about a minute of maintenance
   page on a small store, longer on a large one), then switches maintenance mode off.

If any step fails, the previous version is put back and set up the same way, so the store
comes back as it was; that version is not tried again, the next release is. Your settings,
credentials and sync state live in the database and are never touched by an update. Each
result is written to `var/log/quissly.log`.

- `bin/magento quissly:update` checks and installs now (it also retries a version that
  failed); `bin/magento quissly:update --status` shows what the last check found.
- **Not updated automatically:** a Composer install (see above), and a store whose files
  are a git checkout - whoever deploys it updates it. Unzip the new release over
  `app/code/Quissly/Search` and run the setup commands, as for the install.

Then in the admin: **Stores → Configuration → Quissly**

1. Enter the email address for your Quissly account. It is pre-filled with the address of
   the admin user you are signed in as, which is usually the right one - it becomes the
   owner of the Quissly account and the identity that opens the Quissly panel.
   Your Quissly account is named after your **Store Name** (Stores → Configuration →
   General → Store Information); set it before you connect, because it cannot be renamed
   from Magento afterwards.
2. Click **Connect to Quissly** (on **Quissly Setup** it is **Connect & continue**, and
   steps 3-5 below then happen on that page: the first sync starts by itself and **Go
   live** switches search on). That is the whole of it. The module generates a keypair,
   creates your Quissly account, registers the key and stores the credentials. There is
   nothing to copy, paste, or register anywhere else.
   A **Setting up your store** progress bar runs for two to three minutes while Quissly
   builds the account and the search service behind it; the page then reloads showing
   your project id. Reloading during the bar is safe - it carries on where it was. The
   Dashboard's sync button stays locked, with a countdown, until the bar has finished.
3. Open **Quissly → Dashboard** and start the first catalog sync.
4. Wait for it to finish. **Search and chat cannot be switched on until it does** - the
   toggles stay greyed out and tell you how many products have been sent so far. That is
   deliberate: searching an index that does not yet hold your catalogue returns nothing,
   and nothing in Magento would report a fault.
5. **Switch on *Enable AI Search*.** This is the step that changes your storefront, and
   it is not automatic: the module ships with it **off**, and finishing the sync only
   unlocks the toggle rather than flipping it. Until you do, your store keeps using
   Magento's own search exactly as before - which is the safe default, not a fault.
   Save, and your next search is Quissly-ranked.

**Connect only works once.** Creating a second account would abandon the first along with
everything already synced to it, so the button refuses if credentials already exist. If
you need to move to a different Quissly account, ask Quissly rather than reconnecting.

**What Quissly receives for each product.** Title, description, up to ten images, URL, prices,
stock, categories, and the product attributes you choose under **Stores → Configuration →
Quissly → Catalog data** (pre-filled with the ones shoppers search by: brand, color, size,
material and so on; internal attributes such as cost are never offered). Those values are also
written into the text Quissly searches, so "linen" matches even when the description never
says it. Saving a changed list re-sends the whole catalog. Bundles are sent as one product at
their lowest configured price with their options listed; grouped products are not sent.

**Multiple websites?** Configuration (credentials included) supports Magento's standard
scope switcher: configure once at Default for a single-site store, or switch to each
website and give it its own Quissly credentials.

> **If two websites share products, they need separate Quissly services.** Products are
> identified by their Magento id, which is the same number on every website - so two
> websites pointing at one Quissly service overwrite each other's copy of every shared
> product. The last sync wins, and it carries that website's **title, description, URL and
> stock**. An English and a German storefront sharing a catalogue end up with one language
> for both, and shoppers can be handed links to the other website's domain. Websites with
> genuinely separate catalogues never collide, because they never share an id.
>
> The same applies to **currency**: prices are sent as plain numbers with no currency code,
> so two websites in different currencies sharing a service put mixed-currency values in
> one index, and sorting by price then compares them directly.
>
> The Quissly Dashboard warns when it sees two websites resolving to the same service.

## Verify it works - including the safety net

1. Search for a product on your storefront: results should reflect Quissly ranking.
2. Confirm which engine answered: add `?quissly_debug=1` to the search URL. A badge below
   the results reads **Results by Quissly**. If it reads **Results by Magento search**, a
   second line says why Quissly skipped the search (for example `sort:price` - the shopper
   sorted by price - or `gate-closed` - the first catalog sync has not finished). Shoppers
   never see it - it renders only when that parameter is present.
3. Now prove the safety net. Point the module at an unreachable host:
   `bin/magento config:set quissly/connection/api_base_url https://127.0.0.1:9 &&
   bin/magento cache:clean config`, then search again. **Your store must still return
   normal results**, and the badge must read **Results by Magento search**. That silent
   fallback is the module's core safety property - verify it once so you trust it.
4. Remove the override: `bin/magento config:set quissly/connection/api_base_url ""` and
   clear the config cache. The badge should read **Results by Quissly** again.

## How search behaves (not defects)

- **A search with zero results shows your theme's normal "no results" page.** That's a
  real answer from Quissly, not a failure.
- **Filtered searches (layered navigation) use Magento's native search.** When a shopper
  applies filters, the module deliberately steps aside so filters stay accurate.
- **Sorting.** Quissly ranking applies to the default (Relevance) order. If a shopper picks
  another sort - Price, Name, Position - the module steps aside for that request and
  Magento's own search answers it, so the sort stays truthful. The sort options themselves
  are your theme's; the module neither adds nor removes any.
- **Results open on the variant that matched.** Searching "red tee" shows the red tee's
  picture and opens the product with Red preselected (the link carries
  `?quissly_variant=…` and `#attribute=option`). Voice and image searches do the same, and
  so do the product links Quissly hands to chat. On Hyvä themes the link still preselects
  the variant, but the result tile shows the parent's image.
- **New or edited products appear in search after the sync catches up** (typically
  within minutes; deletions can take a couple of minutes longer to disappear).

## Optional features

Beyond search, the module ships optional features, and they are yours to switch on.
Quick Recommendations, voice, image and QChat are **off by default**; the Immersive
Search Overlay is on by default but only ever appears once AI Search is live. On a
theme with no search box the overlay also supplies the search button itself, beside
your cart, account or language switcher (or wherever **Search button mount point**
points it), falling back to a floating button on small screens, or wherever the
inline button would not fit.

Each is a single toggle in your admin, and nothing needs reinstalling or re-syncing after
you flip one. Voice, image and QChat need nothing requested. **Quick Recommendations
additionally needs Quissly to enable suggestions for your account** - until it is enabled
the dropdown never appears and the Dashboard shows `blocked · not_enabled`; ask support once
your first sync has finished.

| Feature | What it does | Turn on in admin |
|---|---|---|
| **Voice search** | Mic button in the search box; shoppers speak, results open | Quissly → Features → *Enable voice search* |
| **Image search** | Shoppers upload, snap or paste a photo (Ctrl+V in the search box) and get visually similar products; no matches shows your theme's normal empty page | *Enable image search* |
| **Quick Recommendations** | Product suggestions while typing, replacing Magento's own dropdown. Two styles: a list, or scrollable cards | *Quick Recommendations* + *Quick Recommendations style* |
| **QChat assistant** | Chat bubble that answers questions and recommends products; its *Add to Cart* puts the product in the store's own cart (products with options open their product page) | *Enable QChat* - the agent id is fetched for you |
| **Search bar suggestions** | The search overlay types example searches into its empty bar, letter by letter and in random order, so shoppers see what they can ask; they stop the moment a shopper types. On by default: after the first sync, five suggestions are generated from your catalog (each checked to find products). Edit them as pills (× removes one, *Add* adds one), up to 20, one list per store language (pick it in *Language*), or *Reset to generated* | *Search bar suggestions* group: *Show typing suggestions*, *Suggestions* (with *Language* when the store has more than one) |

**If a feature is switched on but does nothing:** the control hides itself rather than
sitting there dead, so an empty space where a mic or camera button should be means
Quissly answered that the feature is unavailable for your account - contact support with
your store's domain. Normal search is never affected while you sort it out.

## Where problems show up

**Quissly → Dashboard** shows, per website: whether Quissly search is **Live** or **Not live
yet**, whether the first catalog sync is complete (with counts), connection health and the
last authentication error if any, and both halves of every optional feature's switch - yours
and Quissly's. The Configuration page's *Keys & Connection* block carries the
ACTIVE / INACTIVE / DEGRADED badge with the reason spelled out.

**Quissly → Billing** shows your Quissly plans - one for search, one for chat - with what
you have used this month, and lets you change plan, buy extra requests, cancel (at the end of
the paid period) or keep a cancelled plan, undo a scheduled downgrade and update your card.
Every charge is shown before you confirm it. Your invoices are listed with their PDFs.
Payments go through Paddle on Quissly's pages; your card is never entered in Magento.

**Quissly → Quissly Admin Panel** opens Quissly's own console inside your Magento admin,
already signed in as the account Connect created - your catalogue as Quissly sees it, chat
set-up (languages, colours, greeting), and what shoppers searched for. If it refuses to
load, the page says which host answered and offers to open the panel in a new tab.

The module also writes an operational log to
`var/log/quissly.log` - status codes and counts only, never shopper queries and never
credentials, so it's safe to share with support.

## Rolling back

- **Turn it off temporarily:** disable "Enable search" in configuration - native search
  resumes immediately. Sync keeps running in the background (harmless).
- **Remove completely:** disable the module, delete its directory, then run the standard
  setup cycle:

  ```bash
  bin/magento module:disable Quissly_Search
  rm -rf app/code/Quissly/Search
  bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush
  ```

  Removing a module this way leaves the three Quissly tables and the `quissly/*`
  configuration rows behind - Magento only runs a module's own uninstall routine for
  modules installed through Composer. They are inert once the module is gone; ask Quissly
  for the cleanup statements if you want them removed.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Search and chat toggles are greyed out | The first catalog sync has not finished. Hover the toggle - it says how many products have been sent. Finish it from the Quissly Dashboard. |
| Admin banner: "Quissly is not accepting catalog updates" | Your account has no active plan or quota, credentials were rejected, or this server's clock has drifted. The banner names which. Products changed meanwhile stay queued - nothing is lost. |
| Dashboard health shows an authentication error | The store's Quissly credentials no longer match the account on Quissly's side - most often the store was connected, then its configuration was reset or copied to another store. Connecting again with the same address is refused ("Email already registered"); contact Quissly to re-attach the store. |
| Dashboard health mentions clock drift | Server clock is more than 60s from Quissly's; every signature is rejected. Check NTP. |
| Search results are native, dashboard's *First catalog sync* row says "Not complete" | Run/finish the first full sync from the dashboard. |
| Not sure whether a search came from Quissly or Magento | Add `?quissly_debug=1` to any search URL. A small badge below the results says which engine answered, and when Magento answered, why Quissly skipped the search (also written to `var/log/quissly.log`). Shoppers never see it. |
| Products missing from results | Check they're enabled, visible in search, in stock, and assigned to this website; then check sync progress. |
| Everything configured, storefront unchanged | Caches: `bin/magento cache:clean`; in production mode also redeploy static content. |

## Security notes

- The signing private key and API token are stored encrypted with Magento's built-in
  encryption and are never written to logs or pages.
- The public storefront endpoints (autocomplete etc.) never expose credentials - all
  signing happens server-side.
- The log file contains no personal data, no shopper queries, and no secrets.
- **Who searched (for your privacy policy).** Each search tells Quissly who is searching,
  as the Quissly Shopify app does: `customer:<id>` for a signed-in customer, otherwise
  `guest:<random id>` from the `quissly_uid` cookie, plus the device type and operating
  system (from the browser). No name, email or IP address is sent. With Magento's *Cookie
  Restriction Mode* on (Stores → Configuration → General → Web → Default Cookie Settings),
  a guest who has not allowed cookies gets no cookie and is sent as anonymous; searching
  works the same either way.

## Support

Contact Quissly support with: your Magento version, the module version, the dashboard's
status/health readout, and the relevant lines from `var/log/quissly.log`.
