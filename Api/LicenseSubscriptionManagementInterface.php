<?php
/**
 * The service a provider module calls for subscription lifecycle events that do
 * **not** arrive as a paid Magento order — cancellations, and renewals driven by
 * the provider's own webhook/cron rather than by creating an order. (Order-driven
 * renewals are handled automatically by the invoice-paid provisioning path via
 * {@see SubscriptionProviderInterface}; a provider only needs this for the rest.)
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Api;

interface LicenseSubscriptionManagementInterface
{
    /**
     * A subscription was cancelled. The license **lapses** at the paid-through
     * date it already reached — it is not revoked, so the buyer keeps updates
     * through the period they paid for; it simply stops being renewed. No-op if no
     * license is linked to this subscription.
     */
    public function cancel(string $providerCode, string $subscriptionId): void;

    /**
     * A subscription renewed without a Magento order (an event/cron-driven
     * provider). Extend the linked license's bound by its edition's period.
     * Idempotent on `$reference` (a stable per-renewal id — the provider's payment
     * or period id), so an at-least-once webhook can't extend twice. No-op if no
     * license is linked.
     */
    public function renew(string $providerCode, string $subscriptionId, string $reference): void;

    /**
     * A previously cancelled subscription was resumed. Clear the lapse mark so the
     * linked license renews again on its next charge. No-op if no license is linked.
     */
    public function restart(string $providerCode, string $subscriptionId): void;
}
