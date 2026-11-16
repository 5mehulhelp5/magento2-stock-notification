<?php
declare(strict_types=1);

namespace Tonyapps\StockNotification\Test\Unit\Controller\Alerts;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\ProductAlert\Model\Price;
use Magento\ProductAlert\Model\ResourceModel\Price\Collection as PriceCollection;
use Magento\ProductAlert\Model\ResourceModel\Price\CollectionFactory as PriceCollectionFactory;
use Magento\ProductAlert\Model\ResourceModel\Stock\CollectionFactory as StockCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\StockNotification\Controller\Alerts\Unsubscribe;

class UnsubscribeTest extends TestCase
{
    /** Every matching alert of the customer goes, whatever store view it was added in. */
    public function testDeletesTheCustomersAlertsOfThatTypeAndProductInTheWebsite(): void
    {
        $alerts = [$this->createMock(Price::class), $this->createMock(Price::class)];
        foreach ($alerts as $alert) {
            $alert->expects(self::once())->method('delete');
        }
        $collection = $this->createMock(PriceCollection::class);
        $collection->expects(self::exactly(3))->method('addFieldToFilter')
            ->willReturnCallback(function (string $field, $value) use ($collection) {
                self::assertSame(['customer_id' => 57, 'product_id' => 1, 'website_id' => 1][$field], $value);
                return $collection;
            });
        $collection->method('getIterator')->willReturn(new \ArrayIterator($alerts));
        $prices = $this->createMock(PriceCollectionFactory::class);
        $prices->method('create')->willReturn($collection);
        $stocks = $this->createMock(StockCollectionFactory::class);
        $stocks->expects(self::never())->method('create');

        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects(self::once())->method('addSuccessMessage');
        $this->controller(['type' => 'price', 'product' => '1'], 57, $stocks, $prices, $messages)->execute();
    }

    public function testAnUnknownTypeChangesNothing(): void
    {
        $prices = $this->createMock(PriceCollectionFactory::class);
        $prices->expects(self::never())->method('create');
        $stocks = $this->createMock(StockCollectionFactory::class);
        $stocks->expects(self::never())->method('create');
        $messages = $this->createMock(ManagerInterface::class);
        $messages->expects(self::never())->method('addSuccessMessage');

        $this->controller(['type' => 'email', 'product' => '1'], 57, $stocks, $prices, $messages)->execute();
    }

    private function controller(array $params, int $customerId, $stocks, $prices, $messages): Unsubscribe
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $key) => $params[$key] ?? null);
        $session = $this->createMock(CustomerSession::class);
        $session->method('getCustomerId')->willReturn($customerId);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->with('stocknotification/alerts/index')->willReturnSelf();
        $redirects = $this->createMock(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);

        return new Unsubscribe($request, $session, $storeManager, $stocks, $prices, $messages, $redirects);
    }
}
