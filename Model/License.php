<?php
/**
 * A provisioned license row (one per order line item that maps to an edition).
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Model;

use Cresset\BougieLicensing\Model\ResourceModel\License as LicenseResource;
use Magento\Framework\Model\AbstractModel;

class License extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(LicenseResource::class);
    }

    public function getLicenseId(): string
    {
        return (string)$this->getData('license_id');
    }

    public function getLicenseKey(): ?string
    {
        $key = $this->getData('license_key');
        return $key === null || $key === '' ? null : (string)$key;
    }

    public function getEdition(): string
    {
        return (string)$this->getData('edition');
    }

    public function getStatus(): string
    {
        return (string)$this->getData('status');
    }

    public function getBoundUntil(): ?string
    {
        $v = $this->getData('bound_until');
        return $v === null || $v === '' ? null : (string)$v;
    }

    /**
     * @return string[]
     */
    public function getPackages(): array
    {
        $raw = (string)$this->getData('packages');
        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function isActive(): bool
    {
        return $this->getStatus() === 'active';
    }
}
