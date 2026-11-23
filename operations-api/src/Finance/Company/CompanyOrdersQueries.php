<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Company;

use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\FinanceAccess as Access;
use Arasya\Operations\Finance\FinanceInput;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use PDO;

final readonly class CompanyOrdersQueries
{
    private const PAGE_SIZE = 6;
    private const PLACED = "o.finalized_at IS NOT NULL AND o.status IN ('finalized', 'cancelled')";
    private const NON_CANCELLED = "o.finalized_at IS NOT NULL AND o.status = 'finalized'";
    private const STATE = "CASE
        WHEN o.status = 'cancelled' THEN 'cancelled'
        WHEN p.production_completed_at IS NOT NULL THEN 'completed'
        WHEN h.submitted_at IS NOT NULL THEN 'in_production'
        ELSE 'not_submitted'
        END";
    private const PRODUCTION = 'LEFT JOIN b2b_production_handoffs h ON h.b2b_order_uuid = o.order_uuid
        LEFT JOIN operational_orders p ON p.order_uuid = h.operational_order_uuid';

    public function __construct(private PDO $pdo, private AuthorizationService $authorization)
    {
    }

    /** @return array<string, mixed> */
    public function read(EmployeeIdentity $actor, string $companyId, array $filters = []): array
    {
        Access::require($this->authorization, $actor, Access::COMPANIES_VIEW, Access::ACCOUNTS_VIEW);
        FinanceInput::only($filters, ['cursor']);
        if (!FinanceInput::isUuid($companyId)) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }
        $company = $this->pdo->prepare('SELECT 1 FROM b2b_companies WHERE company_uuid = ?');
        $company->execute([$companyId]);
        if ($company->fetchColumn() === false) {
            throw new ApiException(404, 'COMPANY_NOT_FOUND', 'Company was not found.');
        }

        $rows = $this->page($companyId, $filters['cursor'] ?? null);
        $nextCursor = count($rows) > self::PAGE_SIZE ? $rows[self::PAGE_SIZE - 1]['order_uuid'] : null;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);
        $items = $this->items(array_column($rows, 'order_uuid'));
        $orders = [];
        foreach ($rows as $index => $row) {
            $orders[] = [
                'id' => $row['order_uuid'],
                'ordinal' => (int) $row['orders_remaining'] - $index,
                'code' => $row['order_code'],
                'placedAt' => FinanceStore::iso($row['finalized_at']),
                'status' => $row['workflow_state'],
                'currency' => $row['currency_code'],
                'production' => $row['submitted_at'] === null ? null : [
                    'stageId' => $row['production_stage_id'],
                    'stageLabel' => $row['stage_label'],
                    'completedAt' => FinanceStore::iso($row['production_completed_at']),
                ],
                'items' => $items[$row['order_uuid']] ?? [],
            ];
        }
        return [
            'summary' => $this->summary($companyId),
            'orders' => $orders,
            'topProducts' => $this->topProducts($companyId),
            'nextCursor' => $nextCursor,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function page(string $companyId, mixed $cursor): array
    {
        $params = [$companyId];
        $where = '';
        if ($cursor !== null) {
            if (!FinanceInput::isUuid($cursor)) {
                throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            }
            $anchor = $this->pdo->prepare('SELECT o.finalized_at FROM b2b_orders o
                WHERE o.company_uuid = ? AND o.order_uuid = ? AND ' . self::PLACED);
            $anchor->execute([$companyId, $cursor]);
            $placedAt = $anchor->fetchColumn();
            if ($placedAt === false) {
                throw new ApiException(400, 'INVALID_CURSOR', 'The provided cursor is invalid.');
            }
            $where = ' AND (o.finalized_at < ? OR (o.finalized_at = ? AND o.order_uuid < ?))';
            array_push($params, $placedAt, $placedAt, $cursor);
        }

        // Count before the page limit, in the same query that reads it, to preserve the company-relative ordinals.
        $query = $this->pdo->prepare('SELECT o.*, h.submitted_at, p.production_stage_id,
                p.production_completed_at, s.display_name AS stage_label, ' . self::STATE . ' AS workflow_state
             FROM (
                 SELECT o.order_uuid, o.order_code, o.status, o.currency_code, o.finalized_at,
                        COUNT(*) OVER () AS orders_remaining
                 FROM b2b_orders o
                 WHERE o.company_uuid = ? AND ' . self::PLACED . $where . '
                 ORDER BY o.finalized_at DESC, o.order_uuid DESC
                 LIMIT ' . (self::PAGE_SIZE + 1) . '
             ) o
             ' . self::PRODUCTION . '
             LEFT JOIN production_stages s ON s.stage_id = p.production_stage_id
             ORDER BY o.finalized_at DESC, o.order_uuid DESC');
        $query->execute($params);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<string> $orderIds @return array<string, list<array<string, mixed>>> */
    private function items(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($orderIds), '?'));
        $query = $this->pdo->prepare('SELECT order_uuid, product_code, product_name_snapshot, variant_snapshot, color_snapshot, quantity
            FROM b2b_order_lines WHERE order_uuid IN (' . $placeholders . ') ORDER BY order_uuid, line_number');
        $query->execute($orderIds);
        $items = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $items[$line['order_uuid']][] = [
                'productCode' => $line['product_code'],
                'productName' => $line['product_name_snapshot'],
                'variant' => $line['variant_snapshot'],
                'color' => $line['color_snapshot'],
                'quantity' => (int) $line['quantity'],
            ];
        }
        return $items;
    }

    /** @return array{total: int, completed: int, inProduction: int, notSubmitted: int, cancelled: int} */
    private function summary(string $companyId): array
    {
        $query = $this->pdo->prepare('SELECT ' . self::STATE . ' AS workflow_state, COUNT(*) AS order_count
            FROM b2b_orders o ' . self::PRODUCTION . '
            WHERE o.company_uuid = ? AND ' . self::PLACED . ' GROUP BY workflow_state');
        $query->execute([$companyId]);
        $counts = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'total' => array_sum($counts),
            'completed' => (int) ($counts['completed'] ?? 0),
            'inProduction' => (int) ($counts['in_production'] ?? 0),
            'notSubmitted' => (int) ($counts['not_submitted'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
        ];
    }

    /** @return list<array{productCode: string, productName: ?string, orderCount: int}> */
    private function topProducts(string $companyId): array
    {
        $query = $this->pdo->prepare('SELECT ranked.product_code, ranked.order_count,
                (SELECT l.product_name_snapshot
                 FROM b2b_orders o JOIN b2b_order_lines l ON l.order_uuid = o.order_uuid
                 WHERE o.company_uuid = ? AND ' . self::NON_CANCELLED . ' AND l.product_code = ranked.product_code
                   AND NULLIF(TRIM(l.product_name_snapshot), \'\') IS NOT NULL
                 ORDER BY o.finalized_at DESC, o.order_uuid DESC, l.line_number
                 LIMIT 1) AS product_name
             FROM (
                 SELECT l.product_code, COUNT(DISTINCT o.order_uuid) AS order_count
                 FROM b2b_orders o JOIN b2b_order_lines l ON l.order_uuid = o.order_uuid
                 WHERE o.company_uuid = ? AND ' . self::NON_CANCELLED . '
                 GROUP BY l.product_code
                 ORDER BY order_count DESC, l.product_code
                 LIMIT 5
             ) ranked
             ORDER BY ranked.order_count DESC, ranked.product_code');
        $query->execute([$companyId, $companyId]);
        return array_map(static fn (array $row): array => [
            'productCode' => $row['product_code'],
            'productName' => $row['product_name'],
            'orderCount' => (int) $row['order_count'],
        ], $query->fetchAll(PDO::FETCH_ASSOC));
    }
}
