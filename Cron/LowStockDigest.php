<?php
declare(strict_types=1);

namespace Tonyapps\StockNotification\Cron;

use Magento\InventoryLowQuantityNotification\Model\ResourceModel\LowQuantityCollectionFactory;
use Tonyapps\StockNotification\Helper\Data as Helper;
use Tonyapps\StockNotification\Model\AdminNotifier;

/**
 * Daily digest of everything below its notify-quantity threshold, reusing the Low Stock report's own collection.
 */
class LowStockDigest
{
    public function __construct(
        private readonly Helper $helper,
        private readonly LowQuantityCollectionFactory $collectionFactory,
        private readonly AdminNotifier $notifier
    ) {
    }

    public function execute(): void
    {
        if (!$this->helper->isLowStockNotificationEnabled()) {
            return;
        }

        $this->notifier->notifyLowStock($this->rows());
    }

    /**
     * Everything below its notify-quantity threshold now. Shared with the Email Designer preview (Model\EmailPreview).
     *
     * @return array<int, array{sku: string, name: string, qty: float, source: string}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->collectionFactory->create() as $sourceItem) {
            $rows[] = [
                'sku' => (string) $sourceItem->getSku(),
                'name' => (string) ($sourceItem->getData('product_name') ?: $sourceItem->getSku()),
                'qty' => (float) $sourceItem->getQuantity(),
                'source' => (string) $sourceItem->getSourceCode(),
            ];
        }
        return $rows;
    }
}
