<?php
/**
 * Block backing the "My Licenses" account page: the signed-in customer's
 * provisioned licenses plus the data needed to render Composer install
 * instructions.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Block\Account;

use Cresset\BougieLicensing\Model\Config;
use Cresset\BougieLicensing\Model\License;
use Cresset\BougieLicensing\Model\ResourceModel\License\CollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Licenses extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly CollectionFactory $collectionFactory,
        private readonly Config $config,
        private readonly EncryptorInterface $encryptor,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The decrypted license key for display, or null if none is stored (a key is
     * kept encrypted at rest with the Magento crypt key).
     */
    public function getLicenseKey(License $license): ?string
    {
        $stored = $license->getLicenseKey();
        if ($stored === null) {
            return null;
        }
        $plain = $this->encryptor->decrypt($stored);
        return $plain === '' ? null : $plain;
    }

    /**
     * The current customer's licenses, newest first.
     *
     * @return License[]
     */
    public function getLicenses(): array
    {
        $customerId = (int)$this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            return [];
        }
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->setOrder('created_at', 'DESC');
        return array_values($collection->getItems());
    }

    /**
     * The Composer repository URL buyers add: {base}/{org}/{repo}.
     */
    public function getRepositoryUrl(): string
    {
        return sprintf(
            '%s/%s/%s',
            $this->config->getApiBaseUrl(),
            $this->config->getOrg(),
            $this->config->getRepo()
        );
    }

    /**
     * The host Composer keys http-basic auth on.
     */
    public function getRepositoryHost(): string
    {
        $base = $this->config->getApiBaseUrl();
        $host = (string)parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);
        if ($host === '') {
            return '';
        }
        return $port ? $host . ':' . $port : $host;
    }
}
