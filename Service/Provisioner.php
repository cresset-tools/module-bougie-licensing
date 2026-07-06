<?php
/**
 * Provisions (and revokes) Bougie license keys for order line items whose
 * product maps to an edition. Idempotent: one license per order item, keyed both
 * locally (a unique row) and remotely (the API Idempotency-Key). Never blocks an
 * order — API failures are logged and skipped.
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
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Provision every not-yet-provisioned licensed line item of an order.
     */
    public function provisionOrder(OrderInterface $order): void
    {
        $storeId = $order->getStoreId();
        if (!$this->config->isEnabled($storeId) || !$this->config->isConfigured($storeId)) {
            return;
        }
        foreach ($order->getAllVisibleItems() as $item) {
            $this->provisionItem($order, $item);
        }
    }

    private function provisionItem(OrderInterface $order, OrderItemInterface $item): void
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
        $license = $this->licenseFactory->create();
        $license->addData([
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
        ]);
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
}
