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

    /**
     * No strict return type on purpose (core account-controller convention):
     * Magento\Customer\Controller\Plugin\Account::aroundExecute short-circuits
     * with an implicit null for unauthenticated sessions (the login redirect is
     * set on the response), and the generated interceptor inherits this
     * signature — a non-nullable return type turns that legitimate null into a
     * TypeError for every logged-out visit.
     *
     * @return Page
     */
    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set(__('My Licenses'));
        return $page;
    }
}
