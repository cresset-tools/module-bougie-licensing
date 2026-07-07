<?php
/**
 * The registry of subscription providers. Provider modules add themselves to the
 * `providers` argument via di.xml; the licensing module asks the pool to classify
 * each paid order. Empty by default — with no provider installed, every order is a
 * plain one-off purchase and behaviour is exactly as before the seam existed.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Model\Subscription;

use Cresset\BougieLicensing\Api\SubscriptionProviderInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

class ProviderPool
{
    /** @var SubscriptionProviderInterface[] */
    private array $providers;

    /**
     * @param SubscriptionProviderInterface[] $providers
     * @throws LocalizedException
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $code => $provider) {
            if (!$provider instanceof SubscriptionProviderInterface) {
                throw new LocalizedException(__(
                    'Bougie subscription provider "%1" must implement %2.',
                    is_string($code) ? $code : (string)$code,
                    SubscriptionProviderInterface::class
                ));
            }
        }
        $this->providers = $providers;
    }

    /**
     * Classify a paid order via the first provider that claims it, or `null` when
     * no provider recognises it (a one-off purchase).
     */
    public function resolve(OrderInterface $order): ?ResolvedSubscription
    {
        foreach ($this->providers as $provider) {
            $context = $provider->classify($order);
            if ($context !== null) {
                return new ResolvedSubscription($provider->getCode(), $context);
            }
        }
        return null;
    }
}
