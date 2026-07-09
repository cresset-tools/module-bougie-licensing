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
     * The customer's licenses grouped by underlying key: a repeat buyer
     * accumulates purchases onto one key, so several rows share a `license_id`.
     * Each group is one card — one credential, the union of entitled packages,
     * and one `items` entry per covered edition carrying ITS own expiry (the
     * bound lives per entitlement, not per key: a perpetual tool and an annual
     * subscription coexist on one card with different end dates). Newest group
     * first. Rows with no key id (a failed issue) stand alone.
     *
     * @return array<int, array{
     *     active: bool,
     *     editions: string[],
     *     packages: string[],
     *     key: ?string,
     *     items: array<int, array{edition: string, packages: string[], boundUntil: ?string, subscription: bool}>
     * }>
     */
    public function getLicenseGroups(): array
    {
        $byKey = [];
        foreach ($this->getLicenses() as $license) {
            $id = $license->getLicenseId();
            $groupKey = $id !== '' ? 'k:' . $id : 'r:' . $license->getId();
            $byKey[$groupKey][] = $license;
        }

        $groups = [];
        foreach ($byKey as $rows) {
            // Prefer the still-active rows for what the key currently holds; fall
            // back to all rows so a fully-revoked key still names its edition.
            $active = array_values(array_filter($rows, static fn(License $l) => $l->isActive()));
            $source = $active !== [] ? $active : $rows;
            $editions = [];
            $packages = [];
            $key = null;
            $items = [];
            foreach ($source as $license) {
                $editions[] = $license->getEdition();
                $packages = array_merge($packages, $license->getPackages());
                if ($key === null) {
                    $key = $this->getLicenseKey($license);
                }
                // One item per edition; rows are newest-first, so the most
                // recent purchase/renewal of an edition describes it.
                $edition = $license->getEdition();
                if ($edition !== '' && !isset($items[$edition])) {
                    $items[$edition] = [
                        'edition' => $edition,
                        'packages' => $license->getPackages(),
                        'boundUntil' => $license->getBoundUntil(),
                        'subscription' => (string)$license->getData('subscription_id') !== '',
                    ];
                }
            }
            $packages = array_values(array_unique($packages));
            sort($packages);
            $groups[] = [
                'active' => $active !== [],
                'editions' => array_values(array_unique(array_filter($editions))),
                'packages' => $packages,
                'key' => $key,
                'items' => array_values($items),
            ];
        }
        return $groups;
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
