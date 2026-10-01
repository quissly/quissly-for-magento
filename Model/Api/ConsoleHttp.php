<?php
/**
 * Copyright © Quissly. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Quissly\Search\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * One request to the Quissly console, answered as {status, body} - or null when nothing
 * answered. A class of its own so the callers (SearchSuggestions) are unit-tested with a
 * mock instead of a live console.
 */
class ConsoleHttp
{
    /**
     * @param CurlFactory $curlFactory
     */
    public function __construct(
        private readonly CurlFactory $curlFactory
    ) {
    }

    /**
     * Send one request.
     *
     * @param string $method GET or PUT
     * @param string $url
     * @param array $headers name => value
     * @param string|null $body JSON body (PUT)
     * @param int $timeout seconds
     * @return array{status:int, body:string}|null
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 10
    ): ?array {
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        foreach ($headers as $name => $value) {
            $curl->addHeader($name, $value);
        }
        try {
            if ($method === 'PUT') {
                $curl->addHeader('Content-Type', 'application/json');
                $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
                $curl->post($url, (string)$body);
            } else {
                $curl->get($url);
            }
        } catch (\Throwable $e) {
            return null;
        }
        $status = (int)$curl->getStatus();

        return $status === 0 ? null : ['status' => $status, 'body' => (string)$curl->getBody()];
    }
}
