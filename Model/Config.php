<?php
/**
 * Typed accessor for the module's store configuration.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_ENABLED = 'bougie_licensing/general/enabled';
    private const XML_API_BASE_URL = 'bougie_licensing/general/api_base_url';
    private const XML_ORG = 'bougie_licensing/general/org';
    private const XML_REPO = 'bougie_licensing/general/repo';
    private const XML_SERVICE_TOKEN = 'bougie_licensing/general/service_token';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getApiBaseUrl($storeId = null): string
    {
        $url = (string)$this->scopeConfig->getValue(self::XML_API_BASE_URL, ScopeInterface::SCOPE_STORE, $storeId);
        return rtrim($url, '/');
    }

    public function getOrg($storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_ORG, ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getRepo($storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_REPO, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * The service token, decrypted (it is stored encrypted).
     */
    public function getServiceToken($storeId = null): string
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_SERVICE_TOKEN, ScopeInterface::SCOPE_STORE, $storeId);
        return $raw === '' ? '' : trim($this->encryptor->decrypt($raw));
    }

    /**
     * Base URL for this repo's management endpoints: {base}/api/v1/repos/{org}/{repo}.
     */
    public function getRepoApiBase($storeId = null): string
    {
        return sprintf(
            '%s/api/v1/repos/%s/%s',
            $this->getApiBaseUrl($storeId),
            rawurlencode($this->getOrg($storeId)),
            rawurlencode($this->getRepo($storeId))
        );
    }

    /**
     * Whether all four required settings are present (enabled is checked separately).
     */
    public function isConfigured($storeId = null): bool
    {
        return $this->getApiBaseUrl($storeId) !== ''
            && $this->getOrg($storeId) !== ''
            && $this->getRepo($storeId) !== ''
            && $this->getServiceToken($storeId) !== '';
    }
}
