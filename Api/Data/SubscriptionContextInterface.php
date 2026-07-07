<?php
/**
 * How a subscription provider classifies one paid order: the stable subscription
 * id it belongs to, and whether the order is a renewal charge or the initial
 * purchase. Returned by {@see \Cresset\BougieLicensing\Api\SubscriptionProviderInterface::classify()}.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Api\Data;

interface SubscriptionContextInterface
{
    /**
     * The provider's stable identifier for the subscription this order belongs to
     * — the value that stays constant across every renewal, so successive renewal
     * orders resolve to the same license.
     */
    public function getSubscriptionId(): string;

    /**
     * True when this order is a **renewal** charge (extend the existing license);
     * false when it is the **initial** purchase (issue a new license and link it).
     */
    public function isRenewal(): bool;
}
