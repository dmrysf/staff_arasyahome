<?php

declare(strict_types=1);

namespace Arasya\Operations\Intelligence;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Integration\SourceOrderPayloadMapper;
use Arasya\Operations\Order\GlobalOrderId;
use Arasya\Operations\Support\Clock;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/**
 * Brings the pricing basis of orders that Arasya already holds, but that were last sent before the shop stated it, into
 * `order_item_meter_facts` (Production Intelligence V2 history). The input is a shop export (YD SOFT
 * `wp yd-arasya meter-basis export`): order and line ids with their `meterBasis`, nothing else.
 *
 * Facts only. The import never creates, changes or re-sends an order: no order row, item, version, hash, QR, document,
 * Confirmare or activity is written, so nothing printed can become stale. It only adds a fact where none is stored:
 * a fact the shop stated with a real order event is newer evidence and is never overwritten. Lines that are not on the
 * canonical order are counted and skipped, never guessed. The whole file is validated before anything is written; each
 * order is written in its own short transaction. Repeating an import writes nothing.
 * Imported rows carry `source_event_id = import:<sha256 of the file>` and get today's `updated_at`, so the automatic
 * ledger run (`meter-ledger.php --changed`) or the backfill books them; their sale date stays the order's own date.
 *
 * Orders Arasya does not hold are "Arasya Dışı / Mağaza Kaynaklı" (owner decision 2026-11-13): their lines are stored in
 * `shop_sourced_meter_lines` as the shop stated them (status, creation, payment, quantity, refunded quantity, basis), never
 * as an order. Each import replaces such an order's lines with the shop's latest statement; an identical statement writes
 * nothing. Whether they count as sold meters is decided when reports read them (ShopSourcedSales).
 */
final readonly class MeterFactImport
{
    public const FORMAT = 'arasya-meter-basis-export';
    public const FORMAT_VERSION = 2;
    public const SOURCES = ['trendhome', 'outletperdele'];
    private const MAX_ORDERS = 50000;
    private const MAX_ITEMS = 200;
    private const LOCK = 'arasya_meter_facts_import';

    public function __construct(private PDO $pdo, private Clock $clock)
    {
    }

    /**
     * @return array{dryRun: bool, origin: string, orders: int, ordersMissing: int, items: int, itemsMissing: int,
     *               recorded: int, alreadyStated: int, byKind: array<string, int>,
     *               outside: array{orders: int, verifiedSaleOrders: int, lines: int, recorded: int, updated: int, unchanged: int, superseded: int}}
     */
    public function run(string $sourceKey, string $json, bool $dryRun): array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('Invalid meter fact import context');
        }
        $orders = self::parse($sourceKey, $json);
        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() !== 1) {
            throw new \RuntimeException('Another meter facts import is running');
        }
        try {
            return $this->import($sourceKey, $json, $orders, $dryRun);
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
    }

    /** @param array<string, array{status: string, createdAt: string, paidAt: string|null, items: array<string, array<string, mixed>>}> $orders */
    private function import(string $sourceKey, string $json, array $orders, bool $dryRun): array
    {
        $origin = 'import:' . hash('sha256', $json);
        $report = ['dryRun' => $dryRun, 'origin' => $origin, 'orders' => 0, 'ordersMissing' => 0, 'items' => 0, 'itemsMissing' => 0,
            'recorded' => 0, 'alreadyStated' => 0, 'byKind' => [],
            'outside' => ['orders' => 0, 'verifiedSaleOrders' => 0, 'lines' => 0, 'recorded' => 0, 'updated' => 0, 'unchanged' => 0, 'superseded' => 0]];
        $outsideRows = $this->pdo->prepare('SELECT source_item_id, shop_status, sale_verified, ordered_at, paid_at, basis_kind, priced_length, quantity, refunded_quantity,
                product_code_snapshot, variant_snapshot, first_imported_at
            FROM shop_sourced_meter_lines WHERE source_key = ? AND source_order_id = ? ORDER BY source_item_id');
        $outsideHeld = $this->pdo->prepare('SELECT COUNT(*) FROM shop_sourced_meter_lines WHERE global_order_id = ?');
        $find = $this->pdo->prepare('SELECT order_uuid FROM operational_orders WHERE global_order_id = ?');
        $lines = $this->pdo->prepare('SELECT source_item_id FROM operational_order_items WHERE order_uuid = ?');
        $stated = $this->pdo->prepare('SELECT source_item_id FROM order_item_meter_facts WHERE order_uuid = ?');
        $insert = $this->pdo->prepare('INSERT INTO order_item_meter_facts (order_uuid, source_item_id, basis_kind, priced_length, source_event_id, first_observed_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($orders as $orderId => $order) {
            // A numeric id became an integer array key; ids are strings everywhere else.
            $orderId = (string) $orderId;
            $items = $order['items'];
            $report['items'] += count($items);
            $global = (new GlobalOrderId($sourceKey, $orderId))->toString();
            $find->execute([$global]);
            $orderUuid = $find->fetchColumn();
            if ($orderUuid === false) {
                $report['ordersMissing']++;
                $this->outside($sourceKey, $orderId, $global, $order, $origin, $dryRun, $outsideRows, $report['outside']);
                continue;
            }
            $report['orders']++;
            $outsideHeld->execute([$global]);
            // Lines stored while the order was outside Arasya are kept, but never counted again (ShopSourcedSales).
            $report['outside']['superseded'] += (int) $outsideHeld->fetchColumn() > 0 ? 1 : 0;
            $lines->execute([$orderUuid]);
            $known = array_flip(array_map('strval', $lines->fetchAll(PDO::FETCH_COLUMN)));
            $stated->execute([$orderUuid]);
            $present = array_flip(array_map('strval', $stated->fetchAll(PDO::FETCH_COLUMN)));
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            if (!$dryRun) {
                $this->pdo->beginTransaction();
            }
            try {
                foreach ($items as $itemId => $item) {
                    $itemId = (string) $itemId;
                    $basis = $item['basis'];
                    if (!isset($known[$itemId])) {
                        $report['itemsMissing']++;
                        continue;
                    }
                    if (isset($present[$itemId])) {
                        $report['alreadyStated']++;
                        continue;
                    }
                    if (!$dryRun) {
                        try {
                            $insert->execute([$orderUuid, $itemId, $basis['kind'], $basis['length'] === null ? null : number_format($basis['length'], 3, '.', ''), $origin, $now, $now]);
                        } catch (PDOException $error) {
                            // 1062: a real order event stated this line in the meantime; that fact wins.
                            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                                throw $error;
                            }
                            $report['alreadyStated']++;
                            continue;
                        }
                    }
                    $report['recorded']++;
                    $report['byKind'][$basis['kind']] = ($report['byKind'][$basis['kind']] ?? 0) + 1;
                }
                if (!$dryRun) {
                    $this->pdo->commit();
                }
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
        ksort($report['byKind']);
        return $report;
    }

    /**
     * Stores the shop's latest statement of an order that never reached Arasya: identical rows write nothing, a changed
     * statement replaces the order's rows in one transaction (a line's first import time is kept).
     * @param array{status: string, createdAt: string, paidAt: string|null, items: array<string, array<string, mixed>>} $order
     * @param array<string, int> $counts
     */
    private function outside(string $sourceKey, string $orderId, string $global, array $order, string $origin, bool $dryRun, \PDOStatement $select, array &$counts): void
    {
        $verified = ShopSourcedSales::verified($order['status'], $order['paidAt']);
        $counts['orders']++;
        $counts['verifiedSaleOrders'] += $verified ? 1 : 0;
        $counts['lines'] += count($order['items']);
        $wanted = [];
        foreach ($order['items'] as $itemId => $item) {
            $wanted[(string) $itemId] = [
                'shop_status' => $order['status'], 'sale_verified' => $verified ? 1 : 0, 'ordered_at' => $order['createdAt'], 'paid_at' => $order['paidAt'],
                'basis_kind' => $item['basis']['kind'], 'priced_length' => $item['basis']['length'] === null ? null : number_format($item['basis']['length'], 3, '.', ''),
                'quantity' => $item['quantity'], 'refunded_quantity' => $item['refunded'], 'product_code_snapshot' => $item['sku'], 'variant_snapshot' => $item['variant'],
            ];
        }
        ksort($wanted, SORT_STRING);
        $select->execute([$sourceKey, $orderId]);
        $held = [];
        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $held[(string) $row['source_item_id']] = $row;
        }
        $comparable = static fn (array $row): array => [(string) $row['shop_status'], (int) $row['sale_verified'], substr((string) $row['ordered_at'], 0, 19),
            $row['paid_at'] === null ? null : substr((string) $row['paid_at'], 0, 19), (string) $row['basis_kind'], $row['priced_length'] === null ? null : (string) $row['priced_length'],
            (int) $row['quantity'], (int) $row['refunded_quantity'], $row['product_code_snapshot'] === null ? null : (string) $row['product_code_snapshot'],
            $row['variant_snapshot'] === null ? null : (string) $row['variant_snapshot']];
        // Numeric line ids become integer array keys on both sides; compare them as the strings they are.
        if (array_map('strval', array_keys($held)) === array_map('strval', array_keys($wanted))
            && array_map($comparable, array_values($held)) === array_map($comparable, array_values($wanted))) {
            $counts['unchanged']++;
            return;
        }
        $counts[$held === [] ? 'recorded' : 'updated']++;
        if ($dryRun) {
            return;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM shop_sourced_meter_lines WHERE source_key = ? AND source_order_id = ?')->execute([$sourceKey, $orderId]);
            $insert = $this->pdo->prepare('INSERT INTO shop_sourced_meter_lines (source_key, source_order_id, source_item_id, global_order_id, shop_status, sale_verified,
                    ordered_at, paid_at, basis_kind, priced_length, quantity, refunded_quantity, product_code_snapshot, variant_snapshot, origin, first_imported_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($wanted as $itemId => $row) {
                $insert->execute([$sourceKey, $orderId, (string) $itemId, $global, $row['shop_status'], $row['sale_verified'], $row['ordered_at'], $row['paid_at'],
                    $row['basis_kind'], $row['priced_length'], $row['quantity'], $row['refunded_quantity'], $row['product_code_snapshot'], $row['variant_snapshot'],
                    $origin, $held[(string) $itemId]['first_imported_at'] ?? $now, $now]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * The whole export, validated before anything is written.
     * @return array<string, array{status: string, createdAt: string, paidAt: string|null,
     *         items: array<string, array{basis: array{kind: string, length: float|null}, quantity: int, refunded: int, sku: string|null, variant: string|null}>}>
     */
    public static function parse(string $sourceKey, string $json): array
    {
        if (!in_array($sourceKey, self::SOURCES, true)) {
            throw new InvalidArgumentException('Only trendhome and outletperdele state a meter basis');
        }
        try {
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The export is not valid JSON');
        }
        if (!is_array($document) || ($document['format'] ?? null) !== self::FORMAT || ($document['version'] ?? null) !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException('Not a meter basis export of version ' . self::FORMAT_VERSION);
        }
        if (($document['sourceKey'] ?? null) !== $sourceKey) {
            throw new InvalidArgumentException('The export belongs to another source');
        }
        $list = $document['orders'] ?? null;
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_ORDERS) {
            throw new InvalidArgumentException('orders must be a list');
        }
        $orders = [];
        foreach ($list as $index => $order) {
            $field = "orders[{$index}]";
            if (!is_array($order) || array_diff(array_keys($order), ['id', 'status', 'createdAt', 'paidAt', 'items']) !== []
                || !isset($order['id'], $order['status'], $order['createdAt'], $order['items']) || !array_key_exists('paidAt', $order)) {
                throw new InvalidArgumentException("{$field} is invalid");
            }
            $orderId = self::identifier($order['id'], "{$field}.id");
            if (isset($orders[$orderId])) {
                throw new InvalidArgumentException("{$field}.id is repeated");
            }
            if (!is_array($order['items']) || !array_is_list($order['items']) || count($order['items']) > self::MAX_ITEMS) {
                throw new InvalidArgumentException("{$field}.items is invalid");
            }
            if (!is_string($order['status']) || preg_match('/^[a-z0-9_-]{1,40}$/D', $order['status']) !== 1) {
                throw new InvalidArgumentException("{$field}.status is invalid");
            }
            $paidAt = $order['paidAt'] === null ? null : self::instant($order['paidAt'], "{$field}.paidAt");
            $orders[$orderId] = ['status' => $order['status'], 'createdAt' => self::instant($order['createdAt'], "{$field}.createdAt"), 'paidAt' => $paidAt, 'items' => []];
            foreach ($order['items'] as $line => $item) {
                $itemField = "{$field}.items[{$line}]";
                if (!is_array($item) || array_diff(array_keys($item), ['id', 'meterBasis', 'quantity', 'refundedQuantity', 'sku', 'variant']) !== []
                    || !isset($item['id'], $item['meterBasis'], $item['quantity'], $item['refundedQuantity'])) {
                    throw new InvalidArgumentException("{$itemField} is invalid");
                }
                $itemId = self::identifier($item['id'], "{$itemField}.id");
                if (isset($orders[$orderId]['items'][$itemId])) {
                    throw new InvalidArgumentException("{$itemField}.id is repeated");
                }
                $quantity = $item['quantity'];
                $refunded = $item['refundedQuantity'];
                if (!is_int($quantity) || $quantity < 0 || $quantity > 100000 || !is_int($refunded) || $refunded < 0 || $refunded > $quantity) {
                    throw new InvalidArgumentException("{$itemField} quantity is invalid");
                }
                try {
                    // The exact rule an order event's meterBasis is held to (API 2.70.0).
                    $basis = SourceOrderPayloadMapper::meterBasis($item['meterBasis'], "{$itemField}.meterBasis");
                } catch (ApiException) {
                    throw new InvalidArgumentException("{$itemField}.meterBasis is invalid");
                }
                $orders[$orderId]['items'][$itemId] = ['basis' => $basis, 'quantity' => $quantity, 'refunded' => $refunded,
                    'sku' => self::label($item['sku'] ?? null, 120, "{$itemField}.sku"), 'variant' => self::label($item['variant'] ?? null, 160, "{$itemField}.variant")];
            }
        }
        return $orders;
    }

    /** An ISO-8601 UTC instant (`2026-10-05T08:00:00Z`) as a DATETIME(6) value. */
    private static function instant(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})Z$/D', $value, $m) !== 1) {
            throw new InvalidArgumentException("{$field} is invalid");
        }
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$m[1]} {$m[2]}", new \DateTimeZone('UTC'));
        if ($at === false || $at->format('Y-m-d H:i:s') !== "{$m[1]} {$m[2]}") {
            throw new InvalidArgumentException("{$field} is invalid");
        }
        return $at->format('Y-m-d H:i:s.u');
    }

    /** The rule `/orders` holds a line's sku and variant to (trimmed, no control character), kept to the column width. */
    private static function label(mixed $value, int $max, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("{$field} is invalid");
        }
        return mb_substr(trim($value), 0, $max);
    }

    private static function identifier(mixed $value, string $field): string
    {
        $value = is_int($value) && $value > 0 ? (string) $value : $value;
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} is invalid");
        }
        return $value;
    }
}
