<?php
/**
 * Provisions (and revokes) Bougie license keys for order line items whose product
 * maps to an edition. Idempotent: one license per order item, keyed both locally
 * (a unique row) and remotely (the API Idempotency-Key). Never blocks an order —
 * API failures are logged and skipped.
 *
 * Subscription-aware: a {@see ProviderPool} classifies each paid order. A one-off
 * order issues a key (as always). A subscription's **initial** order issues a key
 * and links it to the subscription; a subscription's **renewal** order extends
 * that same license (via the idempotent sconce `/renew`) instead of minting a new
 * key. With no subscription provider installed the pool is empty and every order
 * is a one-off — identical to before the seam existed.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Service;

use Cresset\BougieLicensing\Exception\ApiException;
use Cresset\BougieLicensing\Model\Api\Client;
use Cresset\BougieLicensing\Model\Config;
use Cresset\BougieLicensing\Model\License;
use Cresset\BougieLicensing\Model\LicenseFactory;
use Cresset\BougieLicensing\Model\ResourceModel\License as LicenseResource;
use Cresset\BougieLicensing\Model\ResourceModel\License\CollectionFactory;
use Cresset\BougieLicensing\Model\Subscription\Management;
use Cresset\BougieLicensing\Model\Subscription\ProviderPool;
use Cresset\BougieLicensing\Model\Subscription\ResolvedSubscription;
use Cresset\BougieLicensing\Setup\Patch\Data\AddEditionAttribute;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Psr\Log\LoggerInterface;

class Provisioner
{
    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly LicenseFactory $licenseFactory,
        private readonly LicenseResource $licenseResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly EncryptorInterface $encryptor,
        private readonly ProviderPool $providerPool,
        private readonly Management $management,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Provision an order: renew the subscription's license if it's a renewal
     * charge, otherwise issue a key for every not-yet-provisioned licensed item
     * (linking it to the subscription when the order is an initial subscription
     * purchase).
     */
    public function provisionOrder(OrderInterface $order): void
    {
        $storeId = $order->getStoreId();
        if (!$this->config->isEnabled($storeId) || !$this->config->isConfigured($storeId)) {
            return;
        }
        $resolved = $this->providerPool->resolve($order);
        if ($resolved !== null && $resolved->isRenewal() && $this->renewSubscription($order, $resolved)) {
            return;
        }
        // Initial subscription purchase, one-off purchase, or a renewal we couldn't
        // map to a license (recovery: issue a fresh key and link it).
        foreach ($order->getAllVisibleItems() as $item) {
            $this->provisionItem($order, $item, $resolved);
        }
    }

    /**
     * Extend the license behind a renewal order. Returns true when handled (renewed
     * or nothing to do), false when no license could be found or adopted — the
     * caller then falls back to issuing a fresh one.
     */
    private function renewSubscription(OrderInterface $order, ResolvedSubscription $resolved): bool
    {
        $license = $this->management->findBySubscription(
            $resolved->getProviderCode(),
            $resolved->getSubscriptionId()
        );
        if ($license === null) {
            // First renewal we've seen: the initial key was issued before the link
            // existed (e.g. the provider only recognises renewal orders). Adopt it.
            $license = $this->adoptInitialLicense($order, $resolved);
        }
        if ($license === null) {
            $this->logger->warning(sprintf(
                'Bougie: renewal order %s for subscription %s/%s has no license to extend — issuing a fresh one',
                $order->getIncrementId(),
                $resolved->getProviderCode(),
                $resolved->getSubscriptionId()
            ));
            return false;
        }
        // One renewal order = one period; its increment id is the idempotency key,
        // so a retried/duplicated webhook can't extend the bound twice.
        $this->management->renewLicense($license, (string)$order->getIncrementId());
        return true;
    }

    /**
     * Find an active, not-yet-linked license for this order's customer and one of
     * its editions, and link it to the subscription so future renewals resolve to
     * it. Returns the adopted license, or null if none matches.
     */
    private function adoptInitialLicense(OrderInterface $order, ResolvedSubscription $resolved): ?License
    {
        $customerId = $order->getCustomerId() ? (int)$order->getCustomerId() : null;
        $storeId = $order->getStoreId();
        foreach ($order->getAllVisibleItems() as $item) {
            $edition = $this->editionForProduct((int)$item->getProductId(), $storeId);
            if ($edition === null) {
                continue;
            }
            $license = $this->findAdoptable($customerId, $edition);
            if ($license !== null) {
                $this->management->link($license, $resolved->getProviderCode(), $resolved->getSubscriptionId());
                return $license;
            }
        }
        return null;
    }

    private function provisionItem(OrderInterface $order, OrderItemInterface $item, ?ResolvedSubscription $resolved): void
    {
        $storeId = $order->getStoreId();
        $edition = $this->editionForProduct((int)$item->getProductId(), $storeId);
        if ($edition === null) {
            return;
        }
        $itemId = (int)$item->getItemId();
        if ($this->findByOrderItem($itemId) !== null) {
            return; // already provisioned
        }
        $idempotencyKey = $order->getIncrementId() . ':' . $itemId;
        try {
            $resp = $this->client->issueLicense(
                $edition,
                (string)$order->getCustomerEmail(),
                $idempotencyKey,
                $storeId
            );
        } catch (ApiException $e) {
            $this->logger->error(sprintf(
                'Bougie: could not provision order %s item %d (%s): %s',
                $order->getIncrementId(),
                $itemId,
                $edition,
                $e->getMessage()
            ));
            return; // never block the order — a retry (or re-invoice) can re-run
        }

        // The key is shown once by sconce; we store it (encrypted at rest with
        // Magento's crypt key) so the buyer can retrieve it from their account.
        $key = $resp['key'] ?? null;
        $data = [
            'order_id' => (int)$order->getId(),
            'order_item_id' => $itemId,
            'customer_id' => $order->getCustomerId() ? (int)$order->getCustomerId() : null,
            'store_id' => (int)$storeId,
            'edition' => $edition,
            'license_id' => (string)($resp['id'] ?? ''),
            'license_key' => (is_string($key) && $key !== '') ? $this->encryptor->encrypt($key) : null,
            'status' => (string)($resp['status'] ?? 'active'),
            'bound_until' => $resp['bound']['until'] ?? null,
            'packages' => (isset($resp['packages']) && is_array($resp['packages']))
                ? implode(',', $resp['packages'])
                : null,
        ];
        // Link at issue time when this is a subscription order (the provider
        // recognised it), so renewals find it without an adoption step.
        if ($resolved !== null) {
            $data['subscription_provider'] = $resolved->getProviderCode();
            $data['subscription_id'] = $resolved->getSubscriptionId();
            $data['subscription_status'] = 'active';
        }
        $license = $this->licenseFactory->create();
        $license->addData($data);
        $this->licenseResource->save($license);
    }

    /**
     * Revoke the license provisioned for an order item (refund/return).
     */
    public function revokeOrderItem(int $orderItemId, $storeId = null): void
    {
        if (!$this->config->isEnabled($storeId) || !$this->config->isConfigured($storeId)) {
            return;
        }
        $license = $this->findByOrderItem($orderItemId);
        if ($license === null || !$license->isActive() || $license->getLicenseId() === '') {
            return;
        }
        try {
            $this->client->revokeLicense($license->getLicenseId(), $storeId);
        } catch (ApiException $e) {
            $this->logger->error(sprintf(
                'Bougie: could not revoke license %s for order item %d: %s',
                $license->getLicenseId(),
                $orderItemId,
                $e->getMessage()
            ));
            return;
        }
        $license->setData('status', 'revoked');
        $this->licenseResource->save($license);
    }

    private function editionForProduct(int $productId, $storeId): ?string
    {
        try {
            $product = $this->productRepository->getById($productId, false, (int)$storeId);
        } catch (NoSuchEntityException $e) {
            return null;
        }
        $edition = trim((string)$product->getData(AddEditionAttribute::ATTRIBUTE_CODE));
        return $edition === '' ? null : $edition;
    }

    private function findByOrderItem(int $orderItemId): ?License
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_item_id', $orderItemId)->setPageSize(1);
        /** @var License|false $license */
        $license = $collection->getFirstItem();
        return $license && $license->getId() ? $license : null;
    }

    /**
     * The most recent active license for a customer + edition that isn't already
     * linked to a subscription — the candidate to adopt on a first renewal.
     */
    private function findAdoptable(?int $customerId, string $edition): ?License
    {
        if ($customerId === null) {
            return null;
        }
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('edition', $edition)
            ->addFieldToFilter('status', 'active')
            ->addFieldToFilter('subscription_id', ['null' => true])
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1);
        /** @var License|false $license */
        $license = $collection->getFirstItem();
        return $license && $license->getId() ? $license : null;
    }
}
