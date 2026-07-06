<?php
/**
 * Resource model for the bougie_license table.
 */

declare(strict_types=1);

namespace Bougie\Licensing\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class License extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('bougie_license', 'entity_id');
    }
}
