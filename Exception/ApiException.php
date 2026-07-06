<?php
/**
 * A failure talking to the Bougie management API.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Exception;

use Magento\Framework\Exception\LocalizedException;

class ApiException extends LocalizedException
{
}
