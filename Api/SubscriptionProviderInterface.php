<?php
/**
 * The extension point a commerce subscription engine plugs into. A provider
 * module (e.g. Mollie, Amasty) implements this and registers itself into
 * {@see \Cresset\BougieLicensing\Model\Subscription\ProviderPool} via di.xml; the
 * licensing module then knows how to renew a license instead of issuing a new one
 * when a subscription charges again — without knowing anything about the specific
 * payment/subscription engine.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Api;

use Cresset\BougieLicensing\Api\Data\SubscriptionContextInterface;
use Magento\Sales\Api\Data\OrderInterface;

interface SubscriptionProviderInterface
{
    /**
     * A short, stable code identifying this provider (e.g. `mollie`, `amasty`).
     * Stored alongside the license so a renewal can only ever match a license
     * this same provider issued.
     */
    public function getCode(): string;

    /**
     * Classify a paid order. Return a context when the order belongs to **this**
     * provider's subscription system, or `null` when it doesn't (a one-off
     * purchase, or another provider's order). The first provider that returns
     * non-null wins.
     */
    public function classify(OrderInterface $order): ?SubscriptionContextInterface;
}
