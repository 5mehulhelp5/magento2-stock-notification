<?php
declare(strict_types=1);

namespace Tonyapps\StockNotification\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Url;
use Magento\InventoryLowQuantityNotification\Model\ResourceModel\LowQuantityCollection;
use Magento\InventoryLowQuantityNotification\Model\ResourceModel\LowQuantityCollectionFactory;
use Magento\ProductAlert\Helper\Data as ProductAlertHelper;
use Magento\ProductAlert\Model\ProductSalability;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tonyapps\StockNotification\Cron\LowStockDigest;
use Tonyapps\StockNotification\Helper\Data as Helper;
use Tonyapps\StockNotification\Model\AdminNotifier;
use Tonyapps\StockNotification\Model\EmailPreview;
use Tonyapps\StockNotification\Model\GuestAlert;
use Tonyapps\StockNotification\Model\GuestAlertSender;
use Tonyapps\StockNotification\Model\ResourceModel\GuestAlert as GuestAlertResource;
use Tonyapps\StockNotification\Model\ResourceModel\GuestAlert\Collection;
use Tonyapps\StockNotification\Model\ResourceModel\GuestAlert\CollectionFactory;

class EmailPreviewTest extends TestCase
{
    private const PREFIX = 'tonyapps_stocknotification_email_';

    private GuestAlert $alert;
    private GuestAlertResource&MockObject $alertResource;
    private TransportBuilder&MockObject $transportBuilder;
    private Emulation&MockObject $emulation;
    private AdapterInterface&MockObject $connection;
    private ?array $sentVars = null;
    private int $sends = 0;

    protected function setUp(): void
    {
        $this->alert = $this->getMockBuilder(GuestAlert::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $this->alert->setIdFieldName('entity_id');
        $this->alert->setData([
            'entity_id' => 3, 'email' => 'shopper@example.com', 'product_id' => 9, 'store_id' => 2,
            'alert_type' => GuestAlert::TYPE_STOCK, 'status' => GuestAlert::STATUS_PENDING, 'unsubscribe_token' => 'tok',
        ]);
        $this->alertResource = $this->createMock(GuestAlertResource::class);
        $this->emulation = $this->createMock(Emulation::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->transportBuilder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setFromByScope', 'addTo'] as $method) {
            $this->transportBuilder->method($method)->willReturnSelf();
        }
        $this->transportBuilder->method('setTemplateVars')->willReturnCallback(function (array $vars) {
            $this->sentVars = $vars;
            return $this->transportBuilder;
        });
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function () {
            $this->sends++;
        });
        $this->transportBuilder->method('getTransport')->willReturn($transport);
    }

    public function testGuestStockAlertVariablesAreTheSendersAndNothingIsSaved(): void
    {
        $this->guestSender()->send();
        self::assertSame(1, $this->sends, 'the sender sent the alert');
        $sent = $this->sentVars;
        $this->resetSends();
        $this->alertResource->expects(self::never())->method('save');
        $this->alertResource->expects(self::never())->method('purge');
        $this->emulation->expects(self::once())->method('startEnvironmentEmulation')->with(2);
        $this->emulation->expects(self::once())->method('stopEnvironmentEmulation');

        $previewed = $this->preview()->getVariables(self::PREFIX . 'guest_stock_template', '3');

        self::assertSame($sent, $previewed);
        self::assertStringContainsString('https://shop/stocknotification/guest/unsubscribe/token/tok', $previewed['productsHtml']);
        self::assertSame(0, $this->sends);
    }

    public function testGuestConfirmationVariablesAreTheSenders(): void
    {
        $this->alert->setData('status', GuestAlert::STATUS_UNCONFIRMED);
        $this->guestSender()->sendConfirmation($this->alert, $this->product());
        $sent = $this->sentVars;
        $this->resetSends();

        $previewed = $this->preview()->getVariables(self::PREFIX . 'guest_confirm_template', '3');

        self::assertSame($sent, $previewed);
        self::assertSame('https://shop/stocknotification/guest/confirm/token/tok', $previewed['confirm_url']);
        self::assertSame(0, $this->sends);
    }

    public function testNewSubscriptionVariablesForAGuestAndACustomerAreTheNotifiers(): void
    {
        $notifier = $this->adminNotifier();
        $notifier->notifyNewSubscription('shopper@example.com', 'Mug', GuestAlert::TYPE_STOCK, true);
        $guest = $this->sentVars;
        $notifier->notifyNewSubscription('c@example.com', 'Mug', GuestAlert::TYPE_PRICE, false);
        $customer = $this->sentVars;
        $this->resetSends();
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchRow')->willReturn(['customer_id' => 4, 'product_id' => 9]);

        $template = self::PREFIX . 'admin_subscription_template';
        self::assertSame($guest, $this->preview()->getVariables($template, 'guest-3'));
        self::assertSame($customer, $this->preview()->getVariables($template, 'price-12'));
        self::assertSame(0, $this->sends);
    }

    public function testLowStockVariablesComeFromTheDigestsQuery(): void
    {
        (new LowStockDigest($this->helper(), $this->lowStockCollectionFactory(), $this->adminNotifier()))->execute();
        $sent = $this->sentVars;
        self::assertSame(1, $sent['rowCount']);
        $this->resetSends();

        $preview = $this->preview();
        $template = self::PREFIX . 'admin_low_stock_template';
        self::assertSame(['current' => 'Current low stock'], $preview->getRecords($template, 20));
        self::assertSame($sent, $preview->getVariables($template, 'current'));
        self::assertSame(0, $this->sends);
    }

    public function testStoreIsTheGuestAlertsAndNoneForAdminEmails(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls('2', false);
        $this->alertResource->expects(self::never())->method('save');

        $preview = $this->preview();
        self::assertSame(2, $preview->getStoreId(self::PREFIX . 'guest_stock_template', '3'));
        self::assertNull($preview->getStoreId(self::PREFIX . 'guest_confirm_template', '99'));
        self::assertNull($preview->getStoreId(self::PREFIX . 'admin_subscription_template', 'guest-3'));
        self::assertNull($preview->getStoreId(self::PREFIX . 'admin_low_stock_template', 'current'));
    }

    public function testGuestRecordsAreRecentAlertsOfThatType(): void
    {
        $select = $this->select();
        $select->expects(self::once())->method('where')->with('g.alert_type = ?', GuestAlert::TYPE_PRICE)->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturn([[
            'entity_id' => 3, 'email' => 'shopper@example.com', 'sku' => 'MUG', 'alert_type' => 'price',
            'status' => GuestAlert::STATUS_PENDING, 'add_date' => '2026-11-10 08:00:00',
        ]]);

        self::assertSame(
            ['3' => 'shopper@example.com · MUG · 2026-11-10'],
            $this->preview()->getRecords(self::PREFIX . 'guest_price_template', 20)
        );
    }

    private function resetSends(): void
    {
        $this->sentVars = null;
        $this->sends = 0;
    }

    private function select(): Select&MockObject
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'joinLeft', 'order', 'limit', 'where'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        return $select;
    }

    private function preview(): EmailPreview
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('c@example.com');
        $customers = $this->createMock(CustomerRepositoryInterface::class);
        $customers->method('getById')->with(4)->willReturn($customer);

        return new EmailPreview(
            $resource,
            $this->collectionFactory(),
            $this->productRepository(),
            $customers,
            $this->guestSender(),
            $this->adminNotifier(),
            new LowStockDigest($this->helper(), $this->lowStockCollectionFactory(), $this->adminNotifier()),
            $this->emulation
        );
    }

    private function collectionFactory(): CollectionFactory
    {
        $collection = $this->createMock(Collection::class);
        foreach (['addFieldToFilter', 'setOrder', 'setPageSize'] as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$this->alert]));
        $collection->method('getFirstItem')->willReturn($this->alert);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    private function product(): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getFinalPrice')->willReturn(10.0);
        $product->method('getName')->willReturn('Mug');
        $product->method('getProductUrl')->willReturn('https://shop/mug.html');
        return $product;
    }

    private function productRepository(): ProductRepositoryInterface
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturn($this->product());
        return $repository;
    }

    private function helper(): Helper
    {
        $helper = $this->createMock(Helper::class);
        $helper->method('isGuestSubscriptionAllowed')->willReturn(true);
        $helper->method('isNewSubscriptionNotificationEnabled')->willReturn(true);
        $helper->method('isLowStockNotificationEnabled')->willReturn(true);
        $helper->method('getAdminRecipient')->willReturn('admin@example.com');
        $helper->method('getEmailTemplate')->willReturn('template');
        return $helper;
    }

    private function storeManager(): StoreManagerInterface
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(2);
        $store->method('getWebsite')->willReturn($this->createMock(Website::class));
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        return $storeManager;
    }

    private function escaper(): Escaper
    {
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($value) => (string) $value);
        $escaper->method('escapeUrl')->willReturnArgument(0);
        return $escaper;
    }

    private function guestSender(): GuestAlertSender
    {
        $salability = $this->createMock(ProductSalability::class);
        $salability->method('isSalable')->willReturn(true);
        $alertHelper = $this->createMock(ProductAlertHelper::class);
        $alertHelper->method('isStockAlertAllowed')->willReturn(true);
        $priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $priceCurrency->method('convertAndFormat')->willReturn('€9.00');

        return new GuestAlertSender(
            $this->collectionFactory(),
            $this->alertResource,
            $this->productRepository(),
            $salability,
            $alertHelper,
            $this->helper(),
            $this->storeManager(),
            $this->emulation,
            $this->transportBuilder,
            $priceCurrency,
            $this->escaper(),
            $this->createMock(LoggerInterface::class),
            $this->frontendUrl()
        );
    }

    private function adminNotifier(): AdminNotifier
    {
        return new AdminNotifier(
            $this->helper(),
            $this->transportBuilder,
            $this->storeManager(),
            $this->escaper(),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function lowStockCollectionFactory(): LowQuantityCollectionFactory
    {
        $item = new DataObject(['sku' => 'MUG', 'product_name' => 'Mug', 'quantity' => 2, 'source_code' => 'default']);
        $collection = $this->createMock(LowQuantityCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$item]));
        $factory = $this->createMock(LowQuantityCollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    /** Storefront URL builder: https://shop/<route>/token/<token>. */
    private function frontendUrl(): Url
    {
        $url = $this->createMock(Url::class);
        $url->method('setScope')->willReturnSelf();
        $url->method('getUrl')->willReturnCallback(
            static fn (string $route, array $params) => 'https://shop/' . $route . '/token/' . $params['token']
        );
        return $url;
    }
}
