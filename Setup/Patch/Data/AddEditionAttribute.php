<?php
/**
 * Add the `bougie_edition` product attribute: the edition (SKU) name/slug a
 * product provisions a license against when purchased. Blank = not licensed.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddEditionAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'bougie_edition';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $eavSetup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
            'type' => 'varchar',
            'label' => 'Bougie edition (SKU)',
            'input' => 'text',
            'required' => false,
            'sort_order' => 30,
            'global' => ScopedAttributeInterface::SCOPE_STORE,
            'group' => 'Bougie Licensing',
            'note' => 'Edition name or slug to issue a license against when this product is purchased. '
                . 'Leave blank for products that are not licensed.',
            'used_in_product_listing' => true,
            'user_defined' => true,
            'visible' => true,
            'searchable' => false,
            'filterable' => false,
            'comparable' => false,
            'apply_to' => '',
        ]);
        return $this;
    }

    /**
     * @return array<int, string>
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getAliases(): array
    {
        return [];
    }
}
