<?php
/**
 * Revoke the licenses of refunded/returned line items when a credit memo is
 * created.
 */

declare(strict_types=1);

namespace Bougie\Licensing\Observer;

use Bougie\Licensing\Service\Provisioner;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class RevokeOnCreditmemo implements ObserverInterface
{
    public function __construct(
        private readonly Provisioner $provisioner,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getEvent()->getData('creditmemo');
        if ($creditmemo === null) {
            return;
        }
        $storeId = $creditmemo->getStoreId();
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItemId = (int)$item->getOrderItemId();
            if ($orderItemId <= 0) {
                continue;
            }
            try {
                $this->provisioner->revokeOrderItem($orderItemId, $storeId);
            } catch (\Throwable $e) {
                $this->logger->error('Bougie: revoke failed on credit memo: ' . $e->getMessage());
            }
        }
    }
}
