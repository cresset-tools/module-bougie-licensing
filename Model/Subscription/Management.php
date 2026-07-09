<?php
/**
 * Subscription lifecycle operations on provisioned licenses: find a license by
 * its subscription, extend it on renewal, link it to a subscription, and lapse it
 * on cancel. Shared by the order-driven provisioning path (the invoice-paid
 * observer) and the public {@see LicenseSubscriptionManagementInterface} that
 * provider modules call for non-order events.
 *
 * Renewal never mints a new key — it extends the existing license's bound via the
 * idempotent sconce `/renew`. Cancel lapses (keeps the paid-through bound) rather
 * than revoking. Nothing here throws into the caller: a subscription hiccup must
 * never break checkout/invoicing.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Model\Subscription;

use Cresset\BougieLicensing\Api\LicenseSubscriptionManagementInterface;
use Cresset\BougieLicensing\Exception\ApiException;
use Cresset\BougieLicensing\Model\Api\Client;
use Cresset\BougieLicensing\Model\Config;
use Cresset\BougieLicensing\Model\License;
use Cresset\BougieLicensing\Model\ResourceModel\License as LicenseResource;
use Cresset\BougieLicensing\Model\ResourceModel\License\CollectionFactory;
use Psr\Log\LoggerInterface;

class Management implements LicenseSubscriptionManagementInterface
{
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        private readonly LicenseResource $licenseResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function cancel(string $providerCode, string $subscriptionId): void
    {
        $license = $this->findBySubscription($providerCode, $subscriptionId);
        if ($license === null || !$this->config->isEnabled($license->getStoreId())) {
            return;
        }
        // Lapse, don't revoke: the buyer keeps updates through the bound they paid
        // for; the subscription simply won't be renewed again.
        $license->setData('subscription_status', self::STATUS_CANCELLED);
        $this->licenseResource->save($license);
        $this->logger->info(sprintf(
            'Bougie: subscription %s/%s cancelled — license %s will lapse at %s',
            $providerCode,
            $subscriptionId,
            $license->getLicenseId(),
            $license->getBoundUntil() ?? 'its current bound'
        ));
    }

    public function renew(string $providerCode, string $subscriptionId, string $reference): void
    {
        $license = $this->findBySubscription($providerCode, $subscriptionId);
        if ($license === null
            || !$this->config->isEnabled($license->getStoreId())
            || !$this->config->isConfigured($license->getStoreId())
        ) {
            return;
        }
        if ($license->getData('subscription_status') === self::STATUS_CANCELLED) {
            return; // a cancelled subscription is not renewed
        }
        $this->renewLicense($license, $reference);
    }

    public function restart(string $providerCode, string $subscriptionId): void
    {
        $license = $this->findBySubscription($providerCode, $subscriptionId);
        if ($license === null || !$this->config->isEnabled($license->getStoreId())) {
            return;
        }
        if ($license->getData('subscription_status') !== self::STATUS_CANCELLED) {
            return; // wasn't lapsing — nothing to undo
        }
        $license->setData('subscription_status', 'active');
        $this->licenseResource->save($license);
        $this->logger->info(sprintf(
            'Bougie: subscription %s/%s restarted — license %s will renew again',
            $providerCode,
            $subscriptionId,
            $license->getLicenseId()
        ));
    }

    /**
     * The most recent license linked to a provider's subscription, or `null`.
     */
    public function findBySubscription(string $providerCode, string $subscriptionId): ?License
    {
        if ($subscriptionId === '') {
            return null;
        }
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('subscription_provider', $providerCode)
            ->addFieldToFilter('subscription_id', $subscriptionId)
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1);
        /** @var License|false $license */
        $license = $collection->getFirstItem();
        return $license && $license->getId() ? $license : null;
    }

    /**
     * Link a freshly issued (or adopted) license to a provider's subscription so
     * future renewals resolve to it. Idempotent.
     */
    public function link(License $license, string $providerCode, string $subscriptionId): void
    {
        if ($subscriptionId === '') {
            return;
        }
        $license->addData([
            'subscription_provider' => $providerCode,
            'subscription_id' => $subscriptionId,
            'subscription_status' => 'active',
        ]);
        $this->licenseResource->save($license);
    }

    /**
     * Extend a license by its edition's period via the idempotent sconce `/renew`,
     * and refresh the stored bound/status from the response. `$idempotencyKey` must
     * be stable per renewal period (so a webhook retry can't extend twice) and
     * unique across periods (so the next period does). Errors are logged, not
     * thrown.
     *
     * Unlike the event-driven {@see renew()}, this does not skip a `cancelled`
     * subscription: it's called when a real renewal **order** was paid (money
     * received), which only happens while the provider still considers the
     * subscription active — so we always honour it.
     */
    public function renewLicense(License $license, string $idempotencyKey): void
    {
        if ($license->getLicenseId() === '') {
            return;
        }
        try {
            // Account keys carry the subscription's bound on the edition's
            // entitlement edge — renew that. A legacy standalone key has no
            // bounded edge (null) and renews at key level instead.
            $resp = $this->client->renewEdition(
                $license->getLicenseId(),
                (string)$license->getEdition(),
                $idempotencyKey,
                $license->getStoreId()
            ) ?? $this->client->renewLicense(
                $license->getLicenseId(),
                $idempotencyKey,
                $license->getStoreId()
            );
        } catch (ApiException $e) {
            $this->logger->error(sprintf(
                'Bougie: could not renew license %s: %s',
                $license->getLicenseId(),
                $e->getMessage()
            ));
            return;
        }
        // The row tracks ITS purchase's expiry: the edge bound when renewed per
        // edition, the key bound on the legacy path.
        $bound = $resp['edition_bound']['until'] ?? ($resp['bound']['until'] ?? null);
        if (is_string($bound) && $bound !== '') {
            $license->setData('bound_until', $bound);
        }
        if (isset($resp['status']) && is_string($resp['status']) && $resp['status'] !== '') {
            $license->setData('status', $resp['status']);
        }
        $this->licenseResource->save($license);
        $this->logger->info(sprintf(
            'Bougie: renewed license %s to %s',
            $license->getLicenseId(),
            $license->getBoundUntil() ?? '(bound unchanged)'
        ));
    }
}
