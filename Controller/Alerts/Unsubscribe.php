<?php
declare(strict_types=1);

namespace Tonyapps\StockNotification\Controller\Alerts;

use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\ProductAlert\Model\ResourceModel\Price\CollectionFactory as PriceCollectionFactory;
use Magento\ProductAlert\Model\ResourceModel\Stock\CollectionFactory as StockCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Tonyapps\StockNotification\Model\GuestAlert;

/**
 * Unsubscribe button on "My Product Alerts". Core's productalert/unsubscribe/price looks the alert up by store_id
 * without ever setting it, so it deletes nothing while reporting success (Magento 2.4.8). This removes the signed-in
 * customer's alerts of one type for one product in the current website, in every store view.
 * POST, so Magento checks the form key; AccountInterface sends guests to the login page.
 */
class Unsubscribe implements HttpPostActionInterface, AccountInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CustomerSession $customerSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockCollectionFactory $stockCollectionFactory,
        private readonly PriceCollectionFactory $priceCollectionFactory,
        private readonly ManagerInterface $messageManager,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    public function execute(): ResultInterface
    {
        $type = (string) $this->request->getParam('type');
        $productId = (int) $this->request->getParam('product');
        $customerId = (int) $this->customerSession->getCustomerId();

        if ($customerId && $productId && in_array($type, [GuestAlert::TYPE_STOCK, GuestAlert::TYPE_PRICE], true)) {
            $factory = $type === GuestAlert::TYPE_STOCK ? $this->stockCollectionFactory : $this->priceCollectionFactory;
            $alerts = $factory->create()
                ->addFieldToFilter('customer_id', $customerId)
                ->addFieldToFilter('product_id', $productId)
                ->addFieldToFilter('website_id', (int) $this->storeManager->getStore()->getWebsiteId());
            foreach ($alerts as $alert) {
                $alert->delete();
            }
            $this->messageManager->addSuccessMessage(__('You deleted the alert subscription.'));
        }

        return $this->redirectFactory->create()->setPath('stocknotification/alerts/index');
    }
}
