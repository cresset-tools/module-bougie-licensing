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
        try {
            $resp = $this->issueOrAccumulate($order, $item, $edition, $resolved);
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
     * Issue a key for this item, or — for a repeat perpetual purchase by a
     * registered customer — accumulate the edition onto their existing account
     * key so one Composer credential unlocks everything they own. Subscriptions
     * (whose update bound is per-subscription) and guests (no account to
     * accumulate onto) always get their own key. Returns the license JSON to
     * store, in the same shape whether issued or accumulated.
     *
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function issueOrAccumulate(
        OrderInterface $order,
        OrderItemInterface $item,
        string $edition,
        ?ResolvedSubscription $resolved
    ): array {
        $storeId = $order->getStoreId();
        $customerId = $order->getCustomerId() ? (int)$order->getCustomerId() : null;

        if ($resolved === null && $customerId !== null) {
            $accountKey = $this->findAccountKey($customerId, $storeId);
            if ($accountKey !== null) {
                $resp = $this->client->addEdition($accountKey->getLicenseId(), $edition, $storeId);
                if ($resp !== null) {
                    return $resp; // merged onto the customer's account key
                }
                // sconce refused to merge (a bounded/snapshot edition) — fall
                // through and issue this edition its own standalone key.
            }
        }
        // The order id + item id is the idempotency key: a retried/re-invoiced
        // order returns the same key rather than minting a duplicate.
        return $this->client->issueLicense(
            $edition,
            (string)$order->getCustomerEmail(),
            $order->getIncrementId() . ':' . (int)$item->getItemId(),
            $storeId
        );
    }

    /**
     * The customer's "account key" — their most recent active, perpetual,
     * non-subscription license — that a repeat perpetual purchase accumulates
     * onto. Perpetual (`bound_until` null) and non-subscription (`subscription_id`
     * null) only: sconce stores the update bound per key, so a bounded or
     * subscription key can't absorb another edition. `null` if they have none yet.
     */
    private function findAccountKey(int $customerId, $storeId): ?License
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('store_id', (int)$storeId)
            ->addFieldToFilter('status', 'active')
            ->addFieldToFilter('bound_until', ['null' => true])
            ->addFieldToFilter('subscription_id', ['null' => true])
            ->addFieldToFilter('license_id', ['neq' => ''])
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1);
        /** @var License|false $license */
        $license = $collection->getFirstItem();
        return $license && $license->getId() ? $license : null;
    }

    /**
     * Revoke the entitlement provisioned for an order item (refund/return). On a
     * shared key (repeat purchases accumulated onto one key) this must not kill
     * the whole key: it detaches only this item's edition, and revokes the key
     * itself only once its **last** active item is gone. On the common
     * one-item-per-key case this collapses to a plain key revoke.
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
        $licenseId = $license->getLicenseId();
        $edition = $license->getEdition();
        // Other still-active items sharing this key (accumulation). Whether any of
        // them still entitles THIS edition decides if we may detach it.
        $siblings = $this->activeSiblings($license);
        $editionStillHeld = false;
        foreach ($siblings as $sibling) {
            if ($sibling->getEdition() === $edition) {
                $editionStillHeld = true;
                break;
            }
        }
        try {
            if ($siblings === []) {
                // Last item on the key — revoke the whole key.
                $this->client->revokeLicense($licenseId, $storeId);
            } elseif (!$editionStillHeld) {
                // The key lives on for other items, but none of them cover this
                // edition — detach just its content.
                $this->client->removeEdition($licenseId, $edition, $storeId);
            }
            // else: another active item still covers this edition — leave the
            // key's entitlement intact (the buyer keeps a copy they paid for).
        } catch (ApiException $e) {
            $this->logger->error(sprintf(
                'Bougie: could not revoke license %s for order item %d: %s',
                $licenseId,
                $orderItemId,
                $e->getMessage()
            ));
            return;
        }
        $license->setData('status', 'revoked');
        $this->licenseResource->save($license);
    }

    /**
     * Other active license rows sharing this row's key (excluding itself) — the
     * items still holding a shared, accumulated key.
     *
     * @return License[]
     */
    private function activeSiblings(License $license): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('license_id', $license->getLicenseId())
            ->addFieldToFilter('status', 'active')
            ->addFieldToFilter('entity_id', ['neq' => (int)$license->getId()]);
        return array_values($collection->getItems());
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
