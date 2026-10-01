<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Config;

use Laminas\Uri\UriFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Quissly\Search\Model\Cart\ChatIds;
use Quissly\Search\Model\Config\Source\QuickStyle;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Typed, WEBSITE-SCOPED configuration reader.
 *
 * Every read resolves against a website scope with fallback to the default
 * scope via Magento's native config inheritance. Callers pass an explicit
 * website id where they have one (admin controllers); passing null resolves
 * the CURRENT store context's website (storefront paths). Never assume one
 * global connection.
 */
class Settings
{
    public const DEFAULT_API_BASE_URL = 'https://api.quissly.com';

    /**
     * The middleware that issues panel sessions.
     *
     * A DIFFERENT host from the search API: api.quissly.com answers qsearch and
     * catalog, while the console answers auth and panel data. Conflating them
     * produces a 404 that looks like a credentials problem.
     */
    public const DEFAULT_CONSOLE_URL = 'https://console.quissly.com';

    /** The admin panel UI itself. */
    public const DEFAULT_PANEL_URL = 'https://admin.quissly.com';

    /**
     * The QChat widget bundle. Overridable (hidden field) so a test store can
     * pin a different build; the override is constrained to the Quissly CDN,
     * which is also the only script host etc/csp_whitelist.xml allows.
     */
    public const DEFAULT_QCHAT_SCRIPT_URL = 'https://cdn.quissly.com/scripts/universal_magento.js';

    private const QCHAT_SCRIPT_HOST = 'cdn.quissly.com';

    private const PATH_ENVIRONMENT = 'quissly/connection/environment';
    private const PATH_API_TOKEN = 'quissly/connection/api_token';
    private const PATH_API_BASE_URL = 'quissly/connection/api_base_url';
    private const PATH_PRIVATE_KEY = 'quissly/connection/private_key';
    private const PATH_PUBLIC_KEY = 'quissly/connection/public_key';
    private const PATH_PROJECT_ID = 'quissly/connection/project_id';
    private const PATH_SEARCH_NAMESPACE = 'quissly/connection/search_namespace';

    /** The QSearch service id - where its widget_config (search bar suggestions) lives. */
    public const PATH_SEARCH_SERVICE_ID = 'quissly/connection/search_service_id';
    private const PATH_STORE_ID = 'quissly/connection/store_id';
    private const PATH_ACCOUNT_EMAIL = 'quissly/connection/account_email';
    private const PATH_CONSOLE_URL = 'quissly/connection/console_url';
    private const PATH_PANEL_URL = 'quissly/connection/panel_url';
    private const PATH_ENABLE_SEARCH = 'quissly/features/enable_search';
    private const PATH_ENABLE_QCHAT = 'quissly/features/enable_qchat';
    private const PATH_QCHAT_AGENT_ID = 'quissly/features/qchat_agent_id';
    private const PATH_ENABLE_OVERLAY = 'quissly/features/enable_overlay';
    private const PATH_ENABLE_VOICE = 'quissly/features/enable_voice';
    private const PATH_ENABLE_IMAGE = 'quissly/features/enable_image';
    private const PATH_ENABLE_QUICK = 'quissly/features/enable_quick';
    private const PATH_QUICK_STYLE = 'quissly/features/quick_style';
    private const PATH_QCHAT_SCRIPT_URL = 'quissly/features/qchat_script_url';
    private const PATH_OVERLAY_MOUNT_SELECTOR = 'quissly/features/overlay_mount_selector';
    private const PATH_METADATA_ATTRIBUTES = 'quissly/catalog/metadata_attributes';

    /**
     * Magento's own Store Name - the merchant's trading name, already printed on
     * their invoices and order emails.
     */
    private const PATH_STORE_NAME = 'general/store_information/name';

    /**
     * Magento's default website label. Almost nobody renames it, so sending it
     * would give every merchant's Quissly account the same name.
     */
    private const DEFAULT_WEBSITE_NAME = 'Main Website';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Environment for the website ("staging", never "stage" - the backend rejects "stage").
     *
     * @param int|null $websiteId
     * @return string
     */
    public function environment(?int $websiteId = null): string
    {
        return (string)($this->read(self::PATH_ENVIRONMENT, $websiteId) ?? 'prod');
    }

    /**
     * Decrypted bearer token, or null when unset.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function apiToken(?int $websiteId = null): ?string
    {
        return $this->decrypted(self::PATH_API_TOKEN, $websiteId);
    }

    /**
     * Decrypted RSA private key PEM, or null when not generated yet.
     * The decrypted value must stay in memory only - never logged, never
     * echoed, never persisted anywhere else.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function privateKeyPem(?int $websiteId = null): ?string
    {
        return $this->decrypted(self::PATH_PRIVATE_KEY, $websiteId);
    }

    /**
     * Public key PEM (not secret), or null when not generated yet.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function publicKeyPem(?int $websiteId = null): ?string
    {
        $value = $this->read(self::PATH_PUBLIC_KEY, $websiteId);
        return $value === null || $value === '' ? null : (string)$value;
    }

    /**
     * Whether credentials are complete enough to sign a request.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isConfigured(?int $websiteId = null): bool
    {
        return $this->apiToken($websiteId) !== null && $this->privateKeyPem($websiteId) !== null;
    }

    /**
     * The API host this website talks to.
     *
     * Defaults to Quissly's production API. An explicit value is only used when
     * Quissly directs a merchant at another deployment (dev/staging/regional);
     * a trailing slash is trimmed so callers can concatenate paths safely.
     *
     * Refuses anything we would not send a bearer token to. The admin field
     * has a backend model that rejects on save (config:set runs it too), but
     * a direct SQL write, an imported config.php or a restored database dump
     * all reach the value without passing through it - so the check exists
     * here as well. Verified both ways: refused on save, and ignored in favour
     * of the default when planted straight into core_config_data.
     *
     * @param int|null $websiteId
     * @return string
     */
    public function apiBaseUrl(?int $websiteId = null): string
    {
        $value = rtrim(trim((string)$this->read(self::PATH_API_BASE_URL, $websiteId)), '/');
        if ($value === '' || !$this->isCredentialSafeUrl($value)) {
            return self::DEFAULT_API_BASE_URL;
        }
        return $value;
    }

    /**
     * Whether it is safe to send credentials to this URL.
     *
     * Every request to this host carries the bearer token and a valid
     * signature, so plaintext is only acceptable when the traffic cannot leave
     * the machine.
     *
     * @param string $url
     * @return bool
     */
    private function isCredentialSafeUrl(string $url): bool
    {
        try {
            $uri = UriFactory::factory($url);
        } catch (\Throwable $e) {
            return false;
        }
        $host = strtolower((string)$uri->getHost());
        if ($host === '') {
            return false;
        }
        $scheme = strtolower((string)$uri->getScheme());
        if ($scheme === 'https') {
            return true;
        }
        // '[::1]' is the bracketed form the URL parser returns; the bare
        // '::1' never matches a parsed host and is kept only for a value
        // arriving from somewhere that does not bracket it.
        return $scheme === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    /**
     * The Quissly project this website belongs to (a PUBLIC identifier).
     *
     * Half of what the panel single-sign-on needs; the other half is the API
     * token, which doubles as the store key.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function projectId(?int $websiteId = null): ?string
    {
        $value = trim((string)$this->read(self::PATH_PROJECT_ID, $websiteId));
        return $value === '' ? null : $value;
    }

    /**
     * The Quissly account the panel session is opened as.
     *
     * Must be the account CREATED BY PROVISIONING (provider = magento).
     * A person's ordinary Quissly login will be refused: the middleware rejects
     * a service login whose stored provider does not match the platform, which
     * stops a store's API key from being used to impersonate staff. An
     * unrecognised address is worse than an error - it silently creates a new
     * empty account and the panel shows no organizations.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function accountEmail(?int $websiteId = null): ?string
    {
        $value = trim((string)$this->read(self::PATH_ACCOUNT_EMAIL, $websiteId));
        return $value === '' ? null : $value;
    }

    /**
     * Quissly's store id for this website.
     *
     * A copy of the project id in every response seen so far, but returned as
     * its own field since 2026-09-01, so it is stored as its own value rather
     * than assumed. Falls back to the project id for stores connected before
     * the field existed.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function storeId(?int $websiteId = null): ?string
    {
        $value = trim((string)$this->read(self::PATH_STORE_ID, $websiteId));

        return $value !== '' ? $value : $this->projectId($websiteId);
    }

    /**
     * The middleware host that issues panel sessions.
     *
     * @param int|null $websiteId
     * @return string
     */
    public function consoleUrl(?int $websiteId = null): string
    {
        $value = rtrim(trim((string)$this->read(self::PATH_CONSOLE_URL, $websiteId)), '/');
        // Guarded like apiBaseUrl: three callers now send the store's API key
        // to this host in a POST body - PanelSession, Provisioner and
        // ServiceDirectory. The field is hidden from the admin, so the paths
        // that reach it are config:set, direct SQL and an imported config.php,
        // none of which run the backend model. That is precisely why the check
        // lives at READ time rather than only on save.
        if ($value === '' || !$this->isCredentialSafeUrl($value)) {
            return self::DEFAULT_CONSOLE_URL;
        }
        return $value;
    }

    /**
     * Where the overlay's search button should be inserted on a theme that has
     * no search box of its own: a CSS selector for an existing header control.
     * Empty means auto-detect (cart, account, language switcher), else float.
     *
     * @param int|null $websiteId
     * @return string
     */
    public function overlayMountSelector(?int $websiteId = null): string
    {
        return trim((string)$this->read(self::PATH_OVERLAY_MOUNT_SELECTOR, $websiteId));
    }

    /**
     * The product attributes sent to Quissly as metadata, as attribute codes.
     *
     * The stored form is the multiselect's comma-joined list; codes that do not
     * exist in the store are harmless (the mapper skips what a product lacks),
     * which is what lets a rich default list ship for every store.
     *
     * @param int|null $websiteId
     * @return string[]
     */
    public function metadataAttributes(?int $websiteId = null): array
    {
        return $this->parseAttributeList((string)$this->read(self::PATH_METADATA_ATTRIBUTES, $websiteId));
    }

    /**
     * Comma-separated attribute codes -> clean, unique, ordered list.
     *
     * @param string $stored
     * @return string[]
     */
    public function parseAttributeList(string $stored): array
    {
        $codes = [];
        foreach (explode(',', $stored) as $code) {
            $code = strtolower(trim($code));
            if ($code !== '' && preg_match('/^[a-z0-9_]+$/', $code) && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * The QChat widget bundle URL for this website.
     *
     * This value becomes a <script src> on every storefront page, so an
     * arbitrary host here would be arbitrary code execution on the shopfront.
     * The override may therefore choose a different FILE but never a different
     * host, and anything else falls back to the shipped default. The field is
     * hidden from the admin, so the routes that reach it are config:set, direct
     * SQL and an imported config.php - none of which run a backend model, which
     * is why the check lives at READ time.
     *
     * @param int|null $websiteId
     * @return string
     */
    public function qchatScriptUrl(?int $websiteId = null): string
    {
        $value = trim((string)$this->read(self::PATH_QCHAT_SCRIPT_URL, $websiteId));
        if ($value === '' || !$this->isQuisslyCdnScript($value)) {
            return self::DEFAULT_QCHAT_SCRIPT_URL;
        }
        return $value;
    }

    /**
     * Whether a URL is an https script on the Quissly CDN.
     *
     * @param string $url
     * @return bool
     */
    private function isQuisslyCdnScript(string $url): bool
    {
        try {
            $uri = UriFactory::factory($url);
        } catch (\Throwable $e) {
            return false;
        }
        return strtolower((string)$uri->getScheme()) === 'https'
            && strtolower((string)$uri->getHost()) === self::QCHAT_SCRIPT_HOST;
    }

    /**
     * The admin panel embedded in the Magento admin.
     *
     * @param int|null $websiteId
     * @return string
     */
    public function panelUrl(?int $websiteId = null): string
    {
        $value = rtrim(trim((string)$this->read(self::PATH_PANEL_URL, $websiteId)), '/');
        // Guarded for the same reason as consoleUrl, by a different route: the
        // panel session's tokens ride in the iframe URL built from this host,
        // so a plaintext value would put them on the wire in the clear.
        if ($value === '' || !$this->isCredentialSafeUrl($value)) {
            return self::DEFAULT_PANEL_URL;
        }
        return $value;
    }

    /**
     * Whether AI search is toggled on for the website.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isSearchEnabled(?int $websiteId = null): bool
    {
        return (bool)$this->scopeConfig->isSetFlag(
            self::PATH_ENABLE_SEARCH,
            ScopeInterface::SCOPE_WEBSITE,
            $this->resolveWebsiteId($websiteId)
        );
    }

    /**
     * Whether the QChat widget is toggled on for the website (default OFF).
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isQchatEnabled(?int $websiteId = null): bool
    {
        return (bool)$this->scopeConfig->isSetFlag(
            self::PATH_ENABLE_QCHAT,
            ScopeInterface::SCOPE_WEBSITE,
            $this->resolveWebsiteId($websiteId)
        );
    }

    /**
     * Whether the immersive search overlay is on for the website (default OFF).
     *
     * Presentation only - it changes how the search box looks and feels, never
     * which products are returned - the brain, not the skin.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isOverlayEnabled(?int $websiteId = null): bool
    {
        return (bool)$this->scopeConfig->isSetFlag(
            self::PATH_ENABLE_OVERLAY,
            ScopeInterface::SCOPE_WEBSITE,
            $this->resolveWebsiteId($websiteId)
        );
    }

    /**
     * Whether voice search is toggled on for the website (default OFF).
     *
     * Live since 2026-08-21 (the tenant's transcription_prompt is set).
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isVoiceEnabled(?int $websiteId = null): bool
    {
        return (bool)$this->scopeConfig->isSetFlag(
            self::PATH_ENABLE_VOICE,
            ScopeInterface::SCOPE_WEBSITE,
            $this->resolveWebsiteId($websiteId)
        );
    }

    /**
     * Whether image search is toggled on for the website (default OFF).
     *
     * Built dark: the backend gate is a 503 until Quissly enables qimage.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isImageEnabled(?int $websiteId = null): bool
    {
        return (bool)$this->scopeConfig->isSetFlag(
            self::PATH_ENABLE_IMAGE,
            ScopeInterface::SCOPE_WEBSITE,
            $this->resolveWebsiteId($websiteId)
        );
    }

    /**
     * Whether Quick suggestions are switched on for this website.
     *
     * Default OFF. Quissly must also have Quick enabled for the tenant -
     * without that the endpoint answers 404 and the dropdown stays empty.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isQuickEnabled(?int $websiteId = null): bool
    {
        return (bool)$this->scopeConfig->isSetFlag(
            self::PATH_ENABLE_QUICK,
            ScopeInterface::SCOPE_WEBSITE,
            $this->resolveWebsiteId($websiteId)
        );
    }

    /**
     * Which Quick presentation the merchant chose.
     *
     * Presentation only: both styles show the same products in the same order.
     * An unrecognised stored value falls back to the compact list rather than
     * rendering nothing.
     *
     * @param int|null $websiteId
     * @return string QuickStyle::ROWS|QuickStyle::CAROUSEL
     */
    public function quickStyle(?int $websiteId = null): string
    {
        $value = (string)$this->scopeConfig->getValue(
            self::PATH_QUICK_STYLE,
            ScopeInterface::SCOPE_WEBSITE,
            $this->resolveWebsiteId($websiteId)
        );

        return $value === QuickStyle::CAROUSEL ? QuickStyle::CAROUSEL : QuickStyle::ROWS;
    }

    /**
     * The website's QChat agent id - the middleware Service id, a PUBLIC
     * identifier (never the X-Service-UUID, never the bearer).
     * Null when unset/blank.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function qchatAgentId(?int $websiteId = null): ?string
    {
        $value = trim((string)$this->read(self::PATH_QCHAT_AGENT_ID, $websiteId));
        return $value === '' ? null : $value;
    }

    /**
     * The qsearch service's quissly_service_link, or null when unset.
     *
     * It is the namespace of the product ids the chat widget uses.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function searchNamespace(?int $websiteId = null): ?string
    {
        $value = strtolower(trim((string)$this->read(self::PATH_SEARCH_NAMESPACE, $websiteId)));
        return preg_match(ChatIds::UUID_PATTERN, $value) ? $value : null;
    }

    /**
     * The QSearch service's id, or null when not known yet.
     *
     * Model/Search/SearchSuggestions looks it up once and stores it here.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function searchServiceId(?int $websiteId = null): ?string
    {
        $value = strtolower(trim((string)$this->read(self::PATH_SEARCH_SERVICE_ID, $websiteId)));
        return preg_match(ChatIds::UUID_PATTERN, $value) ? $value : null;
    }

    /**
     * Config storage path constants for writers (KeyManager).
     *
     * @return array<string, string>
     */
    public function credentialPaths(): array
    {
        return [
            'private_key' => self::PATH_PRIVATE_KEY,
            'public_key' => self::PATH_PUBLIC_KEY,
        ];
    }

    /**
     * Read a config value at website scope with default fallback.
     *
     * @param string $path
     * @param int|null $websiteId
     * @return mixed
     */
    private function read(string $path, ?int $websiteId)
    {
        return $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_WEBSITE,
            $this->resolveWebsiteId($websiteId)
        );
    }

    /**
     * Read and decrypt a secret config value; null when unset or empty.
     *
     * @param string $path
     * @param int|null $websiteId
     * @return string|null
     */
    private function decrypted(string $path, ?int $websiteId): ?string
    {
        $stored = $this->read($path, $websiteId);
        if ($stored === null || $stored === '') {
            return null;
        }
        $plain = $this->encryptor->decrypt((string)$stored);
        return $plain === '' ? null : $plain;
    }

    /**
     * The name this store should be known by on Quissly's side.
     *
     * Provisioning sends this once, at Connect, and it becomes the name of the
     * merchant's Quissly organization AND project. It used to send the Magento
     * WEBSITE name, which is internal plumbing defaulting to "Main Website" - so
     * every merchant who never renamed it (nearly all of them) ended up with an
     * organization and a project both called "Main Website", distinguishable
     * only by domain.
     *
     * Preference order:
     *   1. Store Name - the merchant's actual trading name, usually already
     *      filled in because Magento prints it on invoices and order emails.
     *   2. The website name, but only when it is not Magento's untouched default.
     *   3. null - Quissly then names the account from the domain instead, and
     *      "acme" tells two merchants apart where "Main Website" cannot.
     *
     * Nothing new is asked of the merchant in any of those cases.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    public function storeDisplayName(?int $websiteId = null): ?string
    {
        $configured = trim((string)$this->read(self::PATH_STORE_NAME, $websiteId));
        if ($configured !== '') {
            return $configured;
        }

        try {
            $websiteName = trim((string)$this->storeManager
                ->getWebsite($this->resolveWebsiteId($websiteId))
                ->getName());
        } catch (\Throwable $e) {
            return null;
        }

        if ($websiteName === '' || strcasecmp($websiteName, self::DEFAULT_WEBSITE_NAME) === 0) {
            return null;
        }

        return $websiteName;
    }

    /**
     * Resolve an explicit website id, else the current store context's website.
     *
     * @param int|null $websiteId
     * @return int
     */
    private function resolveWebsiteId(?int $websiteId): int
    {
        if ($websiteId !== null) {
            return $websiteId;
        }
        return (int)$this->storeManager->getStore()->getWebsiteId();
    }
}
