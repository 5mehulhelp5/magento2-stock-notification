<?php
declare(strict_types=1);

namespace Tonyapps\StockNotification\Test\Unit\View;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use PHPUnit\Framework\TestCase;
use Tonyapps\StockNotification\Model\Analytics;

class LayoutTest extends TestCase
{
    /**
     * Full page cache would otherwise store the page depersonalized, so "My Product Alerts" always renders empty.
     */
    public function testCustomerAlertsPageIsNotCached(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../view/frontend/layout/stocknotification_alerts_index.xml');
        $block = $xml->xpath('//block[@name="tonyapps.stocknotification.alerts"]')[0];

        self::assertSame('false', (string) $block['cacheable']);
    }

    /**
     * Layout object arguments must implement ArgumentInterface or the admin dashboard block fails to build.
     */
    public function testDashboardViewModelIsALayoutArgument(): void
    {
        self::assertTrue(is_subclass_of(Analytics::class, ArgumentInterface::class));
    }

    /** Hyvä swaps the template of the block the Luma layout defines; both names and the template file must exist. */
    public function testHyvaLayoutSwapsTheProductPageTemplate(): void
    {
        $layouts = __DIR__ . '/../../../view/frontend/layout/';
        $luma = simplexml_load_file($layouts . 'catalog_product_view.xml');
        $ref = simplexml_load_file($layouts . 'hyva_catalog_product_view.xml')->xpath('//referenceBlock')[0];

        self::assertNotEmpty($luma->xpath(sprintf('//block[@name="%s"]', $ref['name'])));
        [, $file] = explode('::', (string) $ref['template']);
        self::assertFileExists(__DIR__ . '/../../../view/frontend/templates/' . $file);
    }

    /**
     * The account page unsubscribes through this module's controller with a plain POST form, not core's
     * productalert/unsubscribe/price (a no-op in 2.4.8) or Luma's data-post widget (no Luma JS on Hyvä).
     */
    public function testAlertsPageUnsubscribesWithAPlainForm(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/frontend/templates/customer/alerts.phtml');

        self::assertStringContainsString('<form method="post"', $template);
        self::assertStringContainsString("getBlockHtml('formkey')", $template);
        self::assertStringNotContainsString('data-post=', $template);
    }
}
