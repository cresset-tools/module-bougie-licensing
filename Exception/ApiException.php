<?php
/**
 * A failure talking to the Bougie management API.
 */

declare(strict_types=1);

namespace Bougie\Licensing\Exception;

use Magento\Framework\Exception\LocalizedException;

class ApiException extends LocalizedException
{
}
