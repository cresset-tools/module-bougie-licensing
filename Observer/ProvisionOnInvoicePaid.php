<?php
/**
 * Provision licenses when an order's invoice is paid — the canonical "money
 * received" signal for both virtual/downloadable licensed products and physical
 * ones.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Observer;

use Cresset\BougieLicensing\Service\Provisioner;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ProvisionOnInvoicePaid implements ObserverInterface
{
    public function __construct(
        private readonly Provisioner $provisioner,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $invoice = $observer->getEvent()->getData('invoice');
        if ($invoice === null) {
            return;
        }
        $order = $invoice->getOrder();
        if ($order === null) {
            return;
        }
        try {
            $this->provisioner->provisionOrder($order);
        } catch (\Throwable $e) {
            // Provisioning must never break the payment/invoice flow.
            $this->logger->error('Bougie: provisioning failed on invoice pay: ' . $e->getMessage());
        }
    }
}
