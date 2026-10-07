<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Laminas\Uri\UriFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Quissly\Search\Model\Api\Provisioner;
use Quissly\Search\Model\Api\ServiceDirectory;
use Quissly\Search\Model\Config\Settings;
use Quissly\Search\Model\Connect\ConnectHold;
use Quissly\Search\Model\Connect\Onboarding;
use Quissly\Search\Model\Connect\SetupInput;
use Quissly\Search\Model\Crypto\KeyManager;

/**
 * One-click connection to Quissly: no credential is ever typed.
 *
 * Generates a keypair, sends the store's own domain and admin email along with
 * the public key, and stores everything that comes back. Replaces the entire
 * paste-a-token-and-register-a-key ritual.
 *
 * REFUSES when credentials already exist, because account creation is not
 * idempotent: a second call makes a second tenant and strands the first along
 * with every product already synced to it. That guard is the most important
 * line in the class.
 */
class Index extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::config';

    /**
     * @param Action\Context $context
     * @param Provisioner $provisioner
     * @param ServiceDirectory $serviceDirectory
     * @param KeyManager $keyManager
     * @param Settings $settings
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     * @param EncryptorInterface $encryptor
     * @param StoreManagerInterface $storeManager
     * @param TypeListInterface $cacheTypeList
     * @param JsonFactory $jsonFactory
     * @param AuthSession $authSession
     * @param ConnectHold $hold
     * @param Onboarding $onboarding
     * @param SetupInput $input
     */
    public function __construct(
        Action\Context $context,
        private readonly Provisioner $provisioner,
        private readonly ServiceDirectory $serviceDirectory,
        private readonly KeyManager $keyManager,
        private readonly Settings $settings,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeListInterface $cacheTypeList,
        private readonly JsonFactory $jsonFactory,
        private readonly AuthSession $authSession,
        private readonly ConnectHold $hold,
        private readonly Onboarding $onboarding,
        private readonly SetupInput $input
    ) {
        parent::__construct($context);
    }

    /**
     * Create the account and store what comes back.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $websiteId = $this->websiteId();

        if ($this->settings->apiToken($websiteId) !== null) {
            return $result->setData([
                'ok' => false,
                'message' => (string)__(
                    'This website is already connected to Quissly. Connecting again would '
                    . 'create a second account and abandon the products already synced to '
                    . 'this one. Ask Quissly if you need to move to a different account.'
                ),
            ]);
        }

        // Asked before anything is stored: a store with no credentials anywhere
        // is new, and carries on in Quissly Setup once this succeeds.
        $inSetup = !$this->onboarding->isComplete();

        // What Quissly's backend accepts (the Shopify app's email check), so a bad address is
        // refused here rather than half-way through creating the account.
        $email = trim((string)$this->getRequest()->getParam('email'));
        $problem = $this->input->emailProblem($email);
        if ($problem !== '') {
            $messages = [
                'required' => __('Enter the email address for your Quissly account.'),
                'too_long' => __('Email address is too long.'),
                'public' => __('Use an address on a real, public domain.'),
            ];
            return $result->setData([
                'ok' => false,
                'message' => (string)($messages[$problem] ?? __('Enter a valid email address, like name@example.com.')),
            ]);
        }
        if (!$this->input->isNameValid((string)$this->getRequest()->getParam('store_name'))) {
            return $result->setData([
                'ok' => false,
                'message' => (string)__('Use letters, numbers, spaces and hyphens in the store name.'),
            ]);
        }

        // Held in memory, NOT written yet. Persisting before provisioning
        // succeeds overwrites the private key in place with no copy to
        // restore - a failed attempt then strands the tenant whose products
        // are already synced. That has happened; it is not hypothetical.
        try {
            $keypair = $this->keyManager->generate();
        } catch (\Throwable $e) {
            return $result->setData([
                'ok' => false,
                'message' => (string)__('Could not generate a keypair on this server.'),
            ]);
        }

        // The storefront's own host is what Quissly registers the account
        // against, so it must be the real one rather than anything typed.
        //
        // It must also be the host of the WEBSITE being configured. Credentials
        // are website-scoped, so connecting from website 2's scope and
        // registering website 1's domain would tie that account to a storefront
        // it does not serve. getStore() with no argument answers for the
        // current store - in adminhtml that is the admin store, whose base URL
        // is the backend, not a storefront at all.
        try {
            $store = $this->resolveStore($websiteId);
        } catch (NoSuchEntityException $e) {
            return $result->setData([
                'ok' => false,
                'message' => (string)__('This website has no storefront to connect.'),
            ]);
        }

        $domain = (string)UriFactory::factory((string)$store->getBaseUrl())->getHost();
        if ($domain === '') {
            return $result->setData([
                'ok' => false,
                'message' => (string)__('Set this website\'s base URL before connecting.'),
            ]);
        }

        $response = $this->provisioner->provision(
            $domain,
            $email,
            $keypair['public'],
            // The name typed on Quissly Setup, else the merchant's trading
            // name where they have one, else null so Quissly names the account
            // from the domain. Sending Magento's website label made every
            // account "Main Website".
            $this->storeName($websiteId),
            $websiteId,
            // Who connected. The account is a person's to answer for, and an
            // email alone makes every tenant anonymous in the console.
            $this->adminName('first'),
            $this->adminName('last'),
            // The description on Quissly Setup (drafted from the store, edited by the
            // merchant); Configuration's Connect sends none.
            trim((string)$this->getRequest()->getParam('description')) ?: null
        );

        if (!$response['ok']) {
            return $result->setData([
                'ok' => false,
                'message' => (string)__('Could not connect to Quissly: %1', $response['error']),
            ]);
        }

        // Provisioning succeeded and registered this public key, so the keypair
        // is now the right one to keep. Commit it before the credentials that
        // depend on it.
        $this->keyManager->store($keypair['private'], $keypair['public'], $websiteId);

        $this->store('api_token', $this->encryptor->encrypt($response['api_key']), $websiteId);
        $this->store('project_id', $response['project_id'], $websiteId);
        $this->store('store_id', $response['store_id'], $websiteId);
        $this->store('account_email', $email, $websiteId);

        // Quissly answered as soon as the account existed; the search service
        // behind it is still being built. Start the settling period now so
        // the config page holds its progress bar and the Dashboard refuses a
        // first sync until the service can take one (ConnectHold).
        $this->hold->start($websiteId ?? 0);
        if ($inSetup) {
            $this->onboarding->connected();
        }

        // The credentials above went to storage through the config WRITER, but
        // everything that reads them - Settings, and so ServiceDirectory and the
        // panel session it needs - reads through ScopeConfig, whose in-memory
        // copy was loaded before this request wrote anything. Without this
        // reinit the token and project id still read as null a few lines below,
        // the agent id resolves to null every time, and QChat can never be
        // switched on (the field is hidden, so nobody can paste it by hand).
        $this->reinitableConfig->reinit();

        // The QChat agent id is the qchat service's id. Provisioning creates
        // that service but does not return its id, so it is fetched now - the
        // merchant should never have to find and paste it. A null here just
        // means chat stays off until it can be resolved.
        $agentId = $this->serviceDirectory->qchatAgentId($websiteId);
        if ($agentId !== null) {
            $this->store('qchat_agent_id', $agentId, $websiteId, 'features');
        }
        // Same lookup, for the chat cart bridge (Model/Cart/ChatIdMap), which
        // also retries it lazily if this comes back null.
        $namespace = $this->serviceDirectory->qsearchNamespace($websiteId);
        if ($namespace !== null) {
            $this->store('search_namespace', $namespace, $websiteId);
        }

        // These writes go through the config WRITER, which does not run the
        // StorefrontSetting backend models - so nothing has invalidated the
        // page cache. Connecting changes storefront output (search switches on,
        // the chat widget appears), and without this the merchant sees a
        // success message over a storefront still serving pre-connection pages.
        $this->cacheTypeList->cleanType('config');
        $this->cacheTypeList->cleanType('full_page');
        $this->cacheTypeList->cleanType('block_html');

        return $result->setData([
            'ok' => true,
            'hold_seconds' => ConnectHold::HOLD_SECONDS,
            'message' => (string)__(
                'Connected to Quissly. Start your first catalog sync from the Quissly '
                . 'Dashboard - search switches on once it finishes.'
            ),
        ]);
    }

    /**
     * The storefront whose host this connection is for.
     *
     * @param int|null $websiteId null = default scope
     * @return StoreInterface
     * @throws NoSuchEntityException
     */
    private function resolveStore(?int $websiteId): StoreInterface
    {
        if ($websiteId !== null && $websiteId !== 0) {
            $store = $this->storeManager->getWebsite($websiteId)->getDefaultStore();
            if ($store === null) {
                throw new NoSuchEntityException(__('Website %1 has no default store.', $websiteId));
            }
            return $store;
        }

        // Default scope: the default website's storefront, never the admin
        // store that getStore() would answer with here.
        $store = $this->storeManager->getDefaultStoreView();
        if ($store === null) {
            throw new NoSuchEntityException(__('This installation has no default store view.'));
        }

        return $store;
    }

    /**
     * Write one config value at the scope being edited.
     *
     * @param string $field
     * @param string $value
     * @param int|null $websiteId
     * @param string $group
     * @return void
     */
    private function store(string $field, string $value, ?int $websiteId, string $group = 'connection'): void
    {
        $path = 'quissly/' . $group . '/' . $field;
        if ($websiteId === null || $websiteId === 0) {
            $this->configWriter->save($path, $value);
            return;
        }
        $this->configWriter->save($path, $value, ScopeInterface::SCOPE_WEBSITES, $websiteId);
    }

    /**
     * The store name the account is created with; null lets Quissly use the domain.
     *
     * @param int|null $websiteId
     * @return string|null
     */
    private function storeName(?int $websiteId): ?string
    {
        $typed = trim((string)$this->getRequest()->getParam('store_name'));
        if ($typed !== '') {
            return mb_substr($typed, 0, 100);
        }
        return $this->settings->storeDisplayName($websiteId);
    }

    /**
     * One half of the signed-in admin's name, or null when it is not set.
     *
     * @param string $part 'first' or 'last'
     * @return string|null
     */
    private function adminName(string $part): ?string
    {
        try {
            $user = $this->authSession->getUser();
            if ($user === null) {
                return null;
            }
            $value = trim((string)($part === 'first' ? $user->getFirstName() : $user->getLastName()));
            return $value === '' ? null : $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The website scope being connected; null means the default scope.
     *
     * @return int|null
     */
    private function websiteId(): ?int
    {
        $website = $this->getRequest()->getParam('website');
        return $website === null || $website === '' ? null : (int)$website;
    }
}
