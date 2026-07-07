<?php
/**
 * A classified order: the winning provider's code paired with its
 * {@see \Cresset\BougieLicensing\Api\Data\SubscriptionContextInterface}. Returned
 * by {@see ProviderPool::resolve()}.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Model\Subscription;

use Cresset\BougieLicensing\Api\Data\SubscriptionContextInterface;

class ResolvedSubscription
{
    public function __construct(
        private readonly string $providerCode,
        private readonly SubscriptionContextInterface $context
    ) {
    }

    public function getProviderCode(): string
    {
        return $this->providerCode;
    }

    public function getSubscriptionId(): string
    {
        return $this->context->getSubscriptionId();
    }

    public function isRenewal(): bool
    {
        return $this->context->isRenewal();
    }
}
