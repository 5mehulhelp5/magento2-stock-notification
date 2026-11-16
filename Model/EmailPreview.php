<?php
declare(strict_types=1);

namespace Tonyapps\StockNotification\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\App\Emulation;
use Tonyapps\StockNotification\Cron\LowStockDigest;
use Tonyapps\StockNotification\Model\ResourceModel\GuestAlert\CollectionFactory;

/**
 * Real-data preview for Tonyapps_EmailDesigner (registered in etc/di.xml, duck-typed): the variables come from the
 * senders' own methods (GuestAlertSender::alertVars()/confirmationVars(), AdminNotifier::newSubscriptionVars()/
 * lowStockVars(), LowStockDigest::rows()). Read-only: nothing is sent, saved or purged.
 */
class EmailPreview
{
    private const PREFIX = 'tonyapps_stocknotification_email_';
    private const LOW_STOCK_RECORD = 'current';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly GuestAlertSender $guestSender,
        private readonly AdminNotifier $adminNotifier,
        private readonly LowStockDigest $lowStockDigest,
        private readonly Emulation $emulation
    ) {
    }

    /** @return array<string, string> */
    public function getRecords(string $templateId, int $limit): array
    {
        return match ($this->email($templateId)) {
            'guest_stock' => $this->guestRecords($limit, GuestAlert::TYPE_STOCK),
            'guest_price' => $this->guestRecords($limit, GuestAlert::TYPE_PRICE),
            'guest_confirm' => $this->guestRecords($limit),
            'admin_subscription' => $this->subscriptionRecords($limit),
            'admin_low_stock' => [self::LOW_STOCK_RECORD => (string) __('Current low stock')],
        };
    }

    /** The guest alert's store; null for the admin emails, which go out from the default store view. */
    public function getStoreId(string $templateId, string $recordId): ?int
    {
        if (!in_array($this->email($templateId), ['guest_stock', 'guest_price', 'guest_confirm'], true)) {
            return null;
        }
        $connection = $this->resource->getConnection();
        $storeId = $connection->fetchOne($connection->select()
            ->from($this->resource->getTableName('tonyapps_stocknotification_guest'), ['store_id'])
            ->where('entity_id = ?', (int) $recordId));
        return $storeId === false ? null : (int) $storeId;
    }

    /** @return array<string, mixed> */
    public function getVariables(string $templateId, string $recordId): array
    {
        $email = $this->email($templateId);
        if ($email === 'admin_low_stock') {
            $rows = $this->lowStockDigest->rows();
            if (!$rows) {
                throw new LocalizedException(__('Nothing is below its notify quantity now, so no digest would be sent.'));
            }
            return $this->adminNotifier->lowStockVars($rows);
        }
        if ($email === 'admin_subscription' && !str_starts_with($recordId, 'guest-')) {
            // A customer's core alert, as Plugin\NotifyAdminOnCoreAlert reports it.
            [$type, $id] = explode('-', $recordId, 2) + [1 => ''];
            $table = $type === GuestAlert::TYPE_PRICE ? 'product_alert_price' : 'product_alert_stock';
            $connection = $this->resource->getConnection();
            $row = $connection->fetchRow($connection->select()
                ->from($this->resource->getTableName($table), ['customer_id', 'product_id'])
                ->where($table === 'product_alert_price' ? 'alert_price_id = ?' : 'alert_stock_id = ?', (int) $id));
            if (!$row) {
                throw new LocalizedException(__('This subscription no longer exists.'));
            }
            return $this->adminNotifier->newSubscriptionVars(
                (string) $this->customerRepository->getById((int) $row['customer_id'])->getEmail(),
                (string) $this->productRepository->getById((int) $row['product_id'])->getName(),
                $type === GuestAlert::TYPE_PRICE ? GuestAlert::TYPE_PRICE : GuestAlert::TYPE_STOCK,
                false
            );
        }

        $alert = $this->collectionFactory->create()
            ->addFieldToFilter('entity_id', (int) str_replace('guest-', '', $recordId))
            ->setPageSize(1)
            ->getFirstItem();
        if (!$alert->getId()) {
            throw new LocalizedException(__('This subscription no longer exists.'));
        }
        $storeId = (int) $alert->getStoreId();
        if ($email === 'admin_subscription') {
            // As Controller\Guest\Confirm reports a confirmed guest alert.
            $product = $this->productRepository->getById((int) $alert->getProductId(), false, $storeId);
            return $this->adminNotifier->newSubscriptionVars(
                (string) $alert->getEmail(),
                (string) $product->getName(),
                (string) $alert->getAlertType(),
                true
            );
        }

        $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        try {
            if ($email === 'guest_confirm') {
                return $this->guestSender->confirmationVars(
                    $alert,
                    $this->productRepository->getById((int) $alert->getProductId(), false, $storeId)
                );
            }
            // One alert as if it had just triggered; the real email lists every product that triggered for the address.
            return $this->guestSender->alertVars(
                [[$alert, $this->productRepository->getById((int) $alert->getProductId(), false, $storeId, true)]],
                $storeId
            );
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    private function email(string $templateId): string
    {
        $email = (string) preg_replace('/^' . self::PREFIX . '(.+)_template$/', '$1', $templateId);
        if (!in_array($email, ['guest_stock', 'guest_price', 'guest_confirm', 'admin_subscription', 'admin_low_stock'], true)) {
            throw new LocalizedException(__('No real-data preview for this email.'));
        }
        return $email;
    }

    /** @return array<string, string> guest alerts, newest first */
    private function guestRecords(int $limit, ?string $type = null): array
    {
        $records = [];
        foreach ($this->guestRows($limit, $type) as $row) {
            $records[(string) $row['entity_id']] = implode(' · ', array_filter([
                $row['email'],
                $row['sku'],
                $type === null ? $row['alert_type'] : '',
                (int) $row['status'] === GuestAlert::STATUS_UNCONFIRMED ? (string) __('unconfirmed') : '',
                substr((string) $row['add_date'], 0, 10),
            ]));
        }
        return $records;
    }

    /** @return array<int, array<string, mixed>> */
    private function guestRows(int $limit, ?string $type = null, bool $confirmedOnly = false): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['g' => $this->resource->getTableName('tonyapps_stocknotification_guest')], ['entity_id', 'email', 'alert_type', 'status', 'add_date'])
            ->joinLeft(['p' => $this->resource->getTableName('catalog_product_entity')], 'p.entity_id = g.product_id', ['sku'])
            ->order('g.entity_id DESC')
            ->limit($limit);
        if ($type !== null) {
            $select->where('g.alert_type = ?', $type);
        }
        if ($confirmedOnly) {
            // The admin hears about a guest alert only once it is confirmed.
            $select->where('g.status <> ?', GuestAlert::STATUS_UNCONFIRMED);
        }
        return $connection->fetchAll($select);
    }

    /**
     * @return array<string, string> confirmed guest alerts ("guest-<id>") and customers' core alerts ("stock-<id>",
     *     "price-<id>"), newest first
     */
    private function subscriptionRecords(int $limit): array
    {
        $rows = [];
        foreach ($this->guestRows($limit, null, true) as $row) {
            $rows[] = ['guest-' . $row['entity_id'], $row['add_date'], [__('Guest'), $row['email'], $row['sku'], $row['alert_type']]];
        }
        $connection = $this->resource->getConnection();
        foreach ([GuestAlert::TYPE_STOCK => 'alert_stock_id', GuestAlert::TYPE_PRICE => 'alert_price_id'] as $type => $key) {
            $select = $connection->select()
                ->from(['a' => $this->resource->getTableName('product_alert_' . $type)], ['id' => $key, 'add_date'])
                ->joinLeft(['c' => $this->resource->getTableName('customer_entity')], 'c.entity_id = a.customer_id', ['email'])
                ->joinLeft(['p' => $this->resource->getTableName('catalog_product_entity')], 'p.entity_id = a.product_id', ['sku'])
                ->order('a.' . $key . ' DESC')
                ->limit($limit);
            foreach ($connection->fetchAll($select) as $row) {
                $rows[] = [$type . '-' . $row['id'], $row['add_date'], [__('Customer'), $row['email'], $row['sku'], $type]];
            }
        }
        usort($rows, static fn (array $a, array $b) => strcmp((string) $b[1], (string) $a[1]));

        $records = [];
        foreach (array_slice($rows, 0, $limit) as [$id, $date, $parts]) {
            $parts[] = substr((string) $date, 0, 10);
            $records[$id] = implode(' · ', array_filter(array_map('strval', $parts)));
        }
        return $records;
    }
}
