<?php
/**
 * Bougie Licensing — Magento 2 module registration.
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Cresset_BougieLicensing',
    __DIR__
);
