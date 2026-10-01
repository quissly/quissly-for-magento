<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Controller\Adminhtml\Keys;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Quissly\Search\Model\Api\SignerException;
use Quissly\Search\Model\Crypto\KeyManager;

/**
 * Generates the RSA keypair for a scope and returns the public key for
 * registration. Guard: refuses to overwrite an existing key unless the caller
 * explicitly confirms - regenerating invalidates the registered key.
 */
class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Quissly_Search::config';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param KeyManager $keyManager
     * @param \Quissly\Search\Model\Config\Settings $settings
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly KeyManager $keyManager,
        private readonly \Quissly\Search\Model\Config\Settings $settings
    ) {
        parent::__construct($context);
    }

    /**
     * Generate (or confirm-regenerate) the keypair for the requested scope.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $websiteId = $this->websiteId();
        $force = $this->getRequest()->getParam('force') === '1';

        if (!$force && $this->settings->publicKeyPem($websiteId ?? 0) !== null) {
            return $result->setData([
                'ok' => false,
                'confirm_required' => true,
                'message' => (string)__(
                    'A keypair already exists. Generating a new one invalidates the currently '
                    . 'registered key - Quissly requests will fail until you register the new '
                    . 'public key. Click again to confirm.'
                ),
            ]);
        }

        try {
            $publicKey = $this->keyManager->generateAndStore($websiteId);
        } catch (SignerException $e) {
            return $result->setData(['ok' => false, 'message' => $e->getMessage()]);
        }

        return $result->setData([
            'ok' => true,
            'public_key' => $publicKey,
            'message' => (string)__(
                'Keypair generated. Register this public key in your Quissly console, then run '
                . 'Test Connection.'
            ),
        ]);
    }

    /**
     * Website id from the request; null means default scope.
     *
     * @return int|null
     */
    private function websiteId(): ?int
    {
        $raw = $this->getRequest()->getParam('website');
        if ($raw === null || $raw === '' || (int)$raw === 0) {
            return null;
        }
        return (int)$raw;
    }
}
