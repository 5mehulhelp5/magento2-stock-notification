<?php
declare(strict_types=1);

namespace Tonyapps\StockNotification\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Url;
use Magento\ProductAlert\Helper\Data as ProductAlertHelper;
use Magento\ProductAlert\Model\ProductSalability;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\StockNotification\Helper\Data as Helper;
use Tonyapps\StockNotification\Model\GuestAlert;
use Tonyapps\StockNotification\Model\GuestAlertSender;
use Tonyapps\StockNotification\Model\ResourceModel\GuestAlert as GuestAlertResource;
use Tonyapps\StockNotification\Model\ResourceModel\GuestAlert\Collection;
use Tonyapps\StockNotification\Model\ResourceModel\GuestAlert\CollectionFactory;

class GuestAlertSenderTest extends TestCase
{
    public function testStockEmailUsesTheStoresTemplateAndShowsThePriceInTheStoreCurrency(): void
    {
        $alert = $this->getMockBuilder(GuestAlert::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $alert->setData([
            'email' => 'shopper@example.com',
            'product_id' => 9,
            'store_id' => 2,
            'alert_type' => GuestAlert::TYPE_STOCK,
            'unsubscribe_token' => 'tok',
        ]);
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$alert]));
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $product = $this->createMock(Product::class);
        $product->method('getFinalPrice')->willReturn(10.0);
        $product->method('getName')->willReturn('Mug');
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturn($product);
        $salability = $this->createMock(ProductSalability::class);
        $salability->method('isSalable')->willReturn(true);
        $alertHelper = $this->createMock(ProductAlertHelper::class);
        $alertHelper->method('isStockAlertAllowed')->willReturn(true);
        $helper = $this->createMock(Helper::class);
        $helper->method('isGuestSubscriptionAllowed')->willReturn(true);
        // The template chosen for the alert's store view, e.g. an Email Designer design.
        $helper->method('getEmailTemplate')->with('guest_stock', 2)->willReturn('31');
        $store = $this->createMock(Store::class);
        $store->method('getWebsite')->willReturn($this->createMock(Website::class));
        $store->expects(self::never())->method('getUrl');
        // Alerts can be sent during an admin product save: the link must come from the storefront builder.
        $frontendUrl = $this->createMock(Url::class);
        $frontendUrl->expects(self::once())->method('setScope')->with(2)->willReturnSelf();
        $frontendUrl->expects(self::once())->method('getUrl')
            ->with('stocknotification/guest/unsubscribe', ['token' => 'tok', '_nosid' => true])
            ->willReturn('https://example.test/stocknotification/guest/unsubscribe/token/tok/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->expects(self::once())->method('convertAndFormat')->with(10.0, false, 2, 2)->willReturn('€9.00');
        $priceCurrency->expects(self::never())->method('format');

        $vars = [];
        $transportBuilder = $this->createMock(TransportBuilder::class);
        $transportBuilder->expects(self::once())->method('setTemplateIdentifier')->with('31')->willReturnSelf();
        foreach (['setTemplateOptions', 'setFromByScope', 'addTo'] as $method) {
            $transportBuilder->method($method)->willReturnSelf();
        }
        $transportBuilder->method('setTemplateVars')->willReturnCallback(
            function (array $templateVars) use (&$vars, $transportBuilder) {
                $vars = $templateVars;
                return $transportBuilder;
            }
        );
        $transportBuilder->method('getTransport')->willReturn($this->createMock(TransportInterface::class));
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);
        $escaper->method('escapeUrl')->willReturnArgument(0);

        (new GuestAlertSender(
            $collectionFactory,
            $this->createMock(GuestAlertResource::class),
            $repository,
            $salability,
            $alertHelper,
            $helper,
            $storeManager,
            $this->createMock(Emulation::class),
            $transportBuilder,
            $priceCurrency,
            $escaper,
            $this->createMock(LoggerInterface::class),
            $frontendUrl
        ))->send();

        self::assertStringContainsString('€9.00', $vars['productsHtml']);
        self::assertStringContainsString('https://example.test/stocknotification/guest/unsubscribe/token/tok/', $vars['productsHtml']);
    }

    public function testConfirmationLinkComesFromTheStorefrontBuilderOfTheAlertsStore(): void
    {
        $alert = $this->getMockBuilder(GuestAlert::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $alert->setData(['store_id' => 2, 'alert_type' => GuestAlert::TYPE_STOCK, 'unsubscribe_token' => 'tok']);
        $product = $this->createMock(Product::class);
        $product->method('getName')->willReturn('Mug');
        $frontendUrl = $this->createMock(Url::class);
        $frontendUrl->expects(self::once())->method('setScope')->with(2)->willReturnSelf();
        $frontendUrl->expects(self::once())->method('getUrl')
            ->with('stocknotification/guest/confirm', ['token' => 'tok', '_nosid' => true])
            ->willReturn('https://example.test/stocknotification/guest/confirm/token/tok/');

        $vars = (new GuestAlertSender(
            $this->createMock(CollectionFactory::class),
            $this->createMock(GuestAlertResource::class),
            $this->createMock(ProductRepositoryInterface::class),
            $this->createMock(ProductSalability::class),
            $this->createMock(ProductAlertHelper::class),
            $this->createMock(Helper::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(Emulation::class),
            $this->createMock(TransportBuilder::class),
            $this->createMock(PriceCurrencyInterface::class),
            $this->createMock(Escaper::class),
            $this->createMock(LoggerInterface::class),
            $frontendUrl
        ))->confirmationVars($alert, $product);

        self::assertSame('https://example.test/stocknotification/guest/confirm/token/tok/', $vars['confirm_url']);
    }
}
