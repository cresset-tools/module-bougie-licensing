<?php
/**
 * Plain value object a provider returns from `classify()`. Create it via the
 * generated `SubscriptionContextFactory`, e.g.
 * `$this->contextFactory->create(['subscriptionId' => $id, 'isRenewal' => true])`.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Model\Subscription;

use Cresset\BougieLicensing\Api\Data\SubscriptionContextInterface;

class SubscriptionContext implements SubscriptionContextInterface
{
    public function __construct(
        private readonly string $subscriptionId,
        private readonly bool $isRenewal = false
    ) {
    }

    public function getSubscriptionId(): string
    {
        return $this->subscriptionId;
    }

    public function isRenewal(): bool
    {
        return $this->isRenewal;
    }
}
