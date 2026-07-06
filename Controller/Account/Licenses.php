<?php
/**
 * Customer account "My Licenses" page. Extends AbstractAccount so guests are
 * redirected to login.
 */

declare(strict_types=1);

namespace Cresset\BougieLicensing\Controller\Account;

use Magento\Customer\Controller\AbstractAccount;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Licenses extends AbstractAccount implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Page
    {
        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set(__('My Licenses'));
        return $page;
    }
}
