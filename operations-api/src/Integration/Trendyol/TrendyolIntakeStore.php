<?php

declare(strict_types=1);

namespace Arasya\Operations\Integration\Trendyol;

use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Support\Clock;
use PDO;
use Throwable;

/**
 * Writes one synchronized Trendyol package into the intake inbox. It never creates a production order, an
 * Arasya QR or a production document: only an explicit approval in the Staff workspace does that.
 *
 * Outcomes:
 *   received   a package ordered after the baseline became intake work (`pending`): a new order, or a
 *              ReadyToShip/unknown status kept for review (never releasable while in that status)
 *   deferred   a package ordered after the baseline with payment pending: stored as `pending`, not releasable
 *              until Trendyol moves it to a new-order status
 *   updated    a known package changed (status, lines or delivery)
 *   unchanged  nothing new (same or older marketplace modification)
 *   ignored    historical, or first seen already shipped, returned, cancelled or split: recorded once, never
 *              reclassified
 *
 * After approval the marketplace stays the commercial truth only: a cancellation makes the production order
 * unavailable (like every other source), any other status is shown as commerce status, and a content change is
 * flagged for a person to check. Production stage, items and the printed document are never touched from here.
 */
final readonly class TrendyolIntakeStore
{
    public function __construct(private PDO $pdo, private OrderProjectionWriter $writer, private Clock $clock)
    {
    }

    public function record(TrendyolPackage $package, int $baselineMillis): string
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->pdo->beginTransaction();
        try {
            $ignored = $this->pdo->prepare('SELECT 1 FROM trendyol_ignored_packages WHERE package_id = ?');
            $ignored->execute([$package->packageId]);
            if ($ignored->fetchColumn() !== false) {
                $this->pdo->rollBack();
                return 'ignored';
            }
            $statement = $this->pdo->prepare('SELECT * FROM trendyol_packages WHERE package_id = ? FOR UPDATE');
            $statement->execute([$package->packageId]);
            $current = $statement->fetch(PDO::FETCH_ASSOC);
            $outcome = is_array($current) ? $this->update($current, $package, $now) : $this->insert($package, $baselineMillis, $now);
            $this->pdo->commit();
            return $outcome;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function insert(TrendyolPackage $package, int $baselineMillis, string $now): string
    {
        $decision = TrendyolEligibility::classify($package, $baselineMillis);
        if (!TrendyolEligibility::stored($decision)) {
            $this->pdo->prepare('INSERT INTO trendyol_ignored_packages (package_id, order_number, reason, marketplace_status, order_date_ms, first_seen_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$package->packageId, $package->orderNumber, $decision, $package->status, $package->orderDateMillis, $now]);
            return 'ignored';
        }
        $this->pdo->prepare(
            "INSERT INTO trendyol_packages (package_id, order_number, intake_status, marketplace_status, marketplace_modified_ms, order_date_ms, channel_id,
                delivery_context, lines_hash, version, first_seen_at, last_seen_at)
             VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, 1, ?, ?)",
        )->execute([
            $package->packageId, $package->orderNumber, $package->status, $package->lastModifiedMillis, $package->orderDateMillis, $package->channelId,
            self::json($package->delivery), $package->linesHash(), $now, $now,
        ]);
        $this->insertLines($package, []);
        $this->event($package->packageId, 'received', ['status' => $package->status, 'class' => TrendyolEligibility::statusClass($package->status), 'lines' => count($package->lines)], $now);
        return $decision === TrendyolEligibility::PAYMENT_PENDING ? 'deferred' : 'received';
    }

    /** @param array<string, mixed> $current a locked trendyol_packages row */
    private function update(array $current, TrendyolPackage $package, string $now): string
    {
        $id = $package->packageId;
        $statusChanged = $package->status !== (string) $current['marketplace_status'];
        $linesChanged = !hash_equals((string) $current['lines_hash'], $package->linesHash());
        $deliveryChanged = self::canonical($package->delivery) !== self::canonical(self::decode($current['delivery_context']));
        if ($package->lastModifiedMillis < (int) $current['marketplace_modified_ms'] || !$statusChanged && !$linesChanged && !$deliveryChanged) {
            $this->pdo->prepare('UPDATE trendyol_packages SET last_seen_at = ? WHERE package_id = ?')->execute([$now, $id]);
            return 'unchanged';
        }

        $intake = (string) $current['intake_status'];
        $released = $intake === 'released';
        $sets = ['marketplace_status = ?', 'marketplace_modified_ms = ?', 'last_seen_at = ?', 'version = version + 1'];
        $values = [$package->status, $package->lastModifiedMillis, $now];
        $flagReasons = [];
        if ($statusChanged) {
            $this->event($id, 'marketplace_updated', [
                'from' => (string) $current['marketplace_status'], 'to' => $package->status,
                'fromClass' => TrendyolEligibility::statusClass((string) $current['marketplace_status']), 'toClass' => TrendyolEligibility::statusClass($package->status),
            ], $now);
            // Only a cancellation or a split withdraws pending work; shipped, returned or review statuses stay
            // visible (not releasable) so a person decides, and a later new-order status makes them workable again.
            if ($intake === 'pending' && TrendyolEligibility::withdrawn($package->status)) {
                $sets[] = "intake_status = 'marketplace_cancelled'";
                $this->event($id, 'marketplace_cancelled', ['status' => $package->status], $now);
            }
            if ($released) {
                $this->writer->recordMarketplaceStatus((string) $current['released_order_uuid'], $package->status, TrendyolEligibility::cancelled($package->status));
                if ($package->status === 'UnPacked') {
                    $flagReasons[] = 'split';
                }
            }
        }
        if ($linesChanged) {
            // The package row is the latest marketplace copy. Approved lines stay as approved: after release a
            // change is only flagged for a person to decide, the production order keeps its frozen content.
            if ($released) {
                $flagReasons[] = 'lines';
            } else {
                $this->replaceLines($package);
                $this->event($id, 'marketplace_lines_changed', ['lines' => count($package->lines)], $now);
            }
            $sets[] = 'lines_hash = ?';
            $values[] = $package->linesHash();
        }
        if ($deliveryChanged) {
            $sets[] = 'delivery_context = ?';
            $values[] = self::json($package->delivery);
            if ($released) {
                $flagReasons[] = 'delivery';
            }
        }
        if ($flagReasons !== [] && (int) $current['changed_after_release'] === 0) {
            $sets[] = 'changed_after_release = 1';
            $this->event($id, 'changed_after_release', ['reasons' => $flagReasons], $now);
        }
        $values[] = $id;
        $this->pdo->prepare('UPDATE trendyol_packages SET ' . implode(', ', $sets) . ' WHERE package_id = ?')->execute($values);
        return 'updated';
    }

    /** Replaces the marketplace lines of a pending package; prepared data survives only on unchanged lines. */
    private function replaceLines(TrendyolPackage $package): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM trendyol_package_lines WHERE package_id = ?');
        $statement->execute([$package->packageId]);
        $previous = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $previous[(int) $row['line_id']] = $row;
        }
        $this->pdo->prepare('DELETE FROM trendyol_package_lines WHERE package_id = ?')->execute([$package->packageId]);
        $this->insertLines($package, $previous);
    }

    /** @param array<int, array<string, mixed>> $previous earlier rows by line ID */
    private function insertLines(TrendyolPackage $package, array $previous): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO trendyol_package_lines (package_id, line_id, line_number, product_name, stock_code, barcode, product_size, product_color, quantity, line_status,
                prepared_kind, prepared_width_cm, prepared_height_cm, prepared_meters, prepared_notes, prepared_by_employee_uuid, prepared_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        foreach ($package->lines as $line) {
            $old = $previous[$line['lineId']] ?? null;
            $same = $old !== null
                && (string) $old['product_name'] === $line['productName'] && $old['stock_code'] === $line['stockCode'] && $old['barcode'] === $line['barcode']
                && $old['product_size'] === $line['productSize'] && $old['product_color'] === $line['productColor'] && (int) $old['quantity'] === $line['quantity'];
            $keep = static fn (string $column): mixed => $same ? $old[$column] : null;
            $insert->execute([
                $package->packageId, $line['lineId'], $line['lineNumber'], $line['productName'], $line['stockCode'], $line['barcode'], $line['productSize'],
                $line['productColor'], $line['quantity'], $line['lineStatus'],
                $keep('prepared_kind'), $keep('prepared_width_cm'), $keep('prepared_height_cm'), $keep('prepared_meters'), $keep('prepared_notes'),
                $keep('prepared_by_employee_uuid'), $keep('prepared_at'),
            ]);
        }
    }

    /** @param array<string, mixed> $details */
    private function event(int $packageId, string $action, array $details, string $now): void
    {
        $this->pdo->prepare("INSERT INTO trendyol_intake_events (package_id, action, actor_label, details, occurred_at) VALUES (?, ?, 'trendyol-sync', ?, ?)")
            ->execute([$packageId, $action, self::json($details), $now]);
    }

    private static function json(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(mixed $json): ?array
    {
        if (!is_string($json)) {
            return null;
        }
        $value = json_decode($json, true);
        return is_array($value) ? $value : null;
    }

    /** JSON columns may return object keys in another order; compare by content. */
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }
}
