<?php
/**
 * HTTP client for the Bougie (sconce) management API (/api/v1). Authenticates
 * with the configured service token as an Authorization: Bearer credential.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Model\Api;

use Cresset\BougieLicensing\Exception\ApiException;
use Cresset\BougieLicensing\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

class Client
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Issue a license key against an edition. Idempotent on $idempotencyKey (the
     * order id): a repeat returns the existing license (200, no `key`) rather
     * than a duplicate; a fresh issue is 201 and includes the one-time `key`.
     *
     * @return array<string, mixed> license JSON (id, status, edition, packages,
     *     bound, install, created, and `key` only on first creation)
     * @throws ApiException
     */
    public function issueLicense(string $edition, ?string $buyer, string $idempotencyKey, $storeId = null): array
    {
        $curl = $this->newCurl($storeId, ['Idempotency-Key' => $idempotencyKey]);
        $payload = ['edition' => $edition];
        if ($buyer !== null && $buyer !== '') {
            $payload['buyer'] = $buyer;
        }
        $curl->post($this->url('/license-keys', $storeId), $this->json->serialize($payload));
        return $this->handle($curl, [200, 201], 'issue license');
    }

    /**
     * Inspect a license: entitlements, current bound, install info.
     *
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function inspectLicense(string $licenseId, $storeId = null): array
    {
        $curl = $this->newCurl($storeId);
        $curl->get($this->url('/license-keys/' . rawurlencode($licenseId), $storeId));
        return $this->handle($curl, [200], 'inspect license');
    }

    /**
     * Extend a time-bounded license by its edition's period (subscription renewal).
     * Idempotent on $idempotencyKey (a stable per-renewal id): a retried renewal
     * webhook returns the current bound instead of extending the license again.
     *
     * @return array<string, mixed> the refreshed license JSON
     * @throws ApiException
     */
    public function renewLicense(string $licenseId, ?string $idempotencyKey = null, $storeId = null): array
    {
        $headers = ($idempotencyKey !== null && $idempotencyKey !== '')
            ? ['Idempotency-Key' => $idempotencyKey]
            : [];
        $curl = $this->newCurl($storeId, $headers);
        $curl->post($this->url('/license-keys/' . rawurlencode($licenseId) . '/renew', $storeId), '');
        return $this->handle($curl, [200], 'renew license');
    }

    /**
     * Revoke a license key. Idempotent-ish: a 404 (already gone) is treated as
     * success so a repeated refund doesn't error.
     *
     * @throws ApiException
     */
    public function revokeLicense(string $licenseId, $storeId = null): void
    {
        $curl = $this->newCurl($storeId);
        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
        $curl->get($this->url('/license-keys/' . rawurlencode($licenseId), $storeId));
        $status = $curl->getStatus();
        if ($status !== 204 && $status !== 404) {
            $this->fail($curl, 'revoke license');
        }
    }

    /**
     * Attach an edition's content to an existing key, so a repeat buyer
     * accumulates their purchases onto one key (one Composer credential unlocks
     * everything they own). Idempotent.
     *
     * @return array<string, mixed>|null the updated license JSON (id, key,
     *     packages union, bound), or `null` when sconce refuses to merge this
     *     edition onto the key (HTTP 409 — a time/version-bounded or snapshot
     *     edition) and the caller should issue a standalone key instead
     * @throws ApiException
     */
    public function addEdition(string $licenseId, string $edition, $storeId = null): ?array
    {
        $curl = $this->newCurl($storeId);
        $curl->post(
            $this->url('/license-keys/' . rawurlencode($licenseId) . '/editions', $storeId),
            $this->json->serialize(['edition' => $edition])
        );
        if ($curl->getStatus() === 409) {
            return null;
        }
        return $this->handle($curl, [200], 'add edition to license');
    }

    /**
     * Detach an edition's content from a key (a refund of one line item on a
     * shared key). Idempotent-ish: a 404 (key already gone) is treated as success
     * so a repeated refund doesn't error. Never revokes the key.
     *
     * @throws ApiException
     */
    public function removeEdition(string $licenseId, string $edition, $storeId = null): void
    {
        $curl = $this->newCurl($storeId);
        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
        $curl->get($this->url(
            '/license-keys/' . rawurlencode($licenseId) . '/editions/' . rawurlencode($edition),
            $storeId
        ));
        $status = $curl->getStatus();
        if ($status !== 204 && $status !== 404) {
            $this->fail($curl, 'remove edition from license');
        }
    }

    /**
     * List the repo's editions (for mapping a product to a SKU).
     *
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function listEditions($storeId = null): array
    {
        $curl = $this->newCurl($storeId);
        $curl->get($this->url('/editions', $storeId));
        $body = $this->handle($curl, [200], 'list editions');
        return is_array($body['editions'] ?? null) ? $body['editions'] : [];
    }

    /**
     * @param array<string, string> $extraHeaders
     */
    private function newCurl($storeId, array $extraHeaders = []): Curl
    {
        if (!$this->config->isConfigured($storeId)) {
            throw new ApiException(__('Bougie Licensing is not fully configured (base URL, org, repo, token).'));
        }
        /** @var Curl $curl */
        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);
        $curl->addHeader('Authorization', 'Bearer ' . $this->config->getServiceToken($storeId));
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('Content-Type', 'application/json');
        foreach ($extraHeaders as $name => $value) {
            $curl->addHeader($name, $value);
        }
        return $curl;
    }

    private function url(string $path, $storeId): string
    {
        return $this->config->getRepoApiBase($storeId) . $path;
    }

    /**
     * Decode a successful response, or throw with the API's error message.
     *
     * @param int[] $okStatuses
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function handle(Curl $curl, array $okStatuses, string $what): array
    {
        if (!in_array($curl->getStatus(), $okStatuses, true)) {
            $this->fail($curl, $what);
        }
        $body = trim((string)$curl->getBody());
        if ($body === '') {
            return [];
        }
        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(__('Bougie API returned an unparseable response while trying to %1.', $what));
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @throws ApiException
     */
    private function fail(Curl $curl, string $what): void
    {
        $status = $curl->getStatus();
        $message = 'unexpected response';
        try {
            $decoded = $this->json->unserialize((string)$curl->getBody());
            if (is_array($decoded) && isset($decoded['error'])) {
                $message = (string)$decoded['error'];
            }
        } catch (\InvalidArgumentException $e) {
            // keep the generic message
        }
        $this->logger->error(sprintf('Bougie API failed to %s: HTTP %d %s', $what, $status, $message));
        throw new ApiException(
            __('Bougie API failed to %1 (HTTP %2): %3', $what, $status, $message)
        );
    }
}
