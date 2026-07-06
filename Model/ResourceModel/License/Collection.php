<?php
/**
 * Collection of provisioned licenses.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Model\ResourceModel\License;

use Cresset\BougieLicensing\Model\License;
use Cresset\BougieLicensing\Model\ResourceModel\License as LicenseResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(License::class, LicenseResource::class);
    }
}
