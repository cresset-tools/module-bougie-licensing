<?php
/**
 * Collection of provisioned licenses.
 */

declare(strict_types=1);

namespace Bougie\Licensing\Model\ResourceModel\License;

use Bougie\Licensing\Model\License;
use Bougie\Licensing\Model\ResourceModel\License as LicenseResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(License::class, LicenseResource::class);
    }
}
