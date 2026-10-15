<?php

declare(strict_types=1);

namespace Arasya\Operations\Production;

use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;
use PDOException;

/**
 * Manufacturing manifest of commercial lines (migration 029): how many physical pieces a line is made of, of which
 * production type, with which per-piece measurements and fabric.
 *
 * The commercial quantity is never taken as the number of physical pieces. No source states it (WooCommerce and YD SOFT
 * carry only free-text options such as "2 bucăți", B2B a panel layout, Trendyol nothing), so the piece count is unknown
 * until an authorized person confirms it; the evidence the sources do carry is returned as a proposal for that person,
 * never applied. Per-piece fabric is never derived by division unless the confirmer states that the pieces are equal
 * and the division is exact to the millimetre; per-piece cut dimensions of a line of several pieces are unknown unless
 * confirmed. Unknown is NULL, never zero.
 *
 * The production type may come from an explicit production classification of the source (the B2B line kind, the
 * Arasya/Trendyol preparation kind); a product title is never used. Once a route decision used a line's type, the type
 * is locked for the plan, so a later catalog or plan edit cannot silently change the route of pieces already routed.
 *
 * A plan is identified by the commercial line (item_uuid, stable across source re-syncs). Every change is a new revision
 * and the head points to the active one. A plan whose commercial quantity no longer matches the line (the source changed
 * the order) needs a new confirmation before any per-piece action.
 *
 * Writes run inside the caller's transaction after the caller locked the order row.
 */
final readonly class ManufacturingPlans
{
    public const MAX_PIECES = 200;
    private const DECIMAL = '/^\d{1,9}(?:\.\d{1,3})?$/D';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Commercial lines of the given orders with their resolved manufacturing plan, in line order.
     *
     * @param list<string> $orderUuids @return array<string, list<array<string, mixed>>>
     */
    public function linesFor(array $orderUuids): array
    {
        $orderUuids = array_values(array_unique($orderUuids));
        if ($orderUuids === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($orderUuids), '?'));
        $items = $this->pdo->prepare(
            "SELECT i.order_uuid, i.item_uuid, i.source_item_id, i.line_number, i.name, i.product_code, i.variant, i.color, i.width_value, i.height_value, i.measurement_unit, i.meters, i.quantity, i.production_context, o.source_key
             FROM operational_order_items i INNER JOIN operational_orders o ON o.order_uuid = i.order_uuid WHERE i.order_uuid IN ({$marks}) ORDER BY i.order_uuid, i.line_number",
        );
        $items->execute($orderUuids);
        $rows = $items->fetchAll(PDO::FETCH_ASSOC);
        $heads = $this->heads(array_map(static fn (array $row): string => (string) $row['item_uuid'], $rows));
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['order_uuid']][] = $this->resolve($row, $heads[(string) $row['item_uuid']] ?? null);
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function lines(string $orderUuid): array
    {
        return $this->linesFor([$orderUuid])[$orderUuid] ?? [];
    }

    /** One line of an order, or null. @return array<string, mixed>|null */
    public function line(string $orderUuid, string $itemUuid): ?array
    {
        foreach ($this->lines($orderUuid) as $line) {
            if ($line['itemId'] === $itemUuid) {
                return $line;
            }
        }
        return null;
    }

    /** Known piece count per line of the order (null = unknown or needs re-confirmation). @return array<string, int|null> */
    public function pieceCounts(string $orderUuid): array
    {
        $counts = [];
        foreach ($this->lines($orderUuid) as $line) {
            $counts[(string) $line['itemId']] = $line['known'] ? (int) $line['plan']['pieceCount'] : null;
        }
        return $counts;
    }

    /** Lines of the order whose piece count is unknown or must be re-confirmed. @return list<string> item ids */
    public function unknownLines(string $orderUuid): array
    {
        return array_values(array_map(static fn (array $line): string => (string) $line['itemId'], array_filter($this->lines($orderUuid), static fn (array $line): bool => !$line['known'])));
    }

    /**
     * Records the plan currently in force for the given lines (a derived classification gets revision 1) and locks
     * their production type, because a route decision is about to use it. Caller holds the order lock.
     *
     * @param list<string> $itemUuids
     */
    public function lockTypes(string $orderUuid, array $itemUuids, string $now): void
    {
        foreach ($this->lines($orderUuid) as $line) {
            if (!in_array($line['itemId'], $itemUuids, true)) {
                continue;
            }
            if ($line['plan']['revision'] === 0) {
                $this->write($orderUuid, $line, [
                    'productType' => $line['plan']['productType'], 'typeSource' => $line['plan']['typeSource'], 'pieceCount' => null,
                    'materialTotal' => $line['plan']['materialTotal'], 'materialSource' => $line['plan']['materialSource'], 'equalSplit' => false, 'pieces' => [],
                ], null, 'Clasificare înregistrată la prima aplicare a traseului de producție.', null, $now);
            }
            $this->pdo->prepare('UPDATE production_line_plan_heads SET type_locked_at = COALESCE(type_locked_at, ?), updated_at = ? WHERE item_uuid = ?')->execute([$now, $now, $line['itemId']]);
        }
    }

    /**
     * Validates a confirmation request (types and decimals only; business checks happen in confirm()).
     *
     * @param array<string, mixed> $input
     * @return array{expectedRevision: int, productType: string, pieceCount: int, pieces: list<array{index: int, width: ?string, height: ?string, unit: ?string, fabric: ?string}>, equalSplit: bool, materialTotal: ?string, materialTotalGiven: bool, note: string}
     */
    public static function parseConfirmation(array $input): array
    {
        $sourceHash = $input['expectedSourceHash'] ?? null;
        if (!is_string($sourceHash) || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) {
            throw new ApiException(400, 'SOURCE_PLAN_HASH_REQUIRED', 'Reîncarcă planul și trimite expectedSourceHash pentru sursa verificată.');
        }
        $expected = $input['expectedRevision'] ?? null;
        if (!is_int($expected) || $expected < 0) {
            throw new ApiException(400, 'INVALID_REQUEST', 'expectedRevision trebuie să fie un număr întreg nenegativ.');
        }
        $type = $input['productType'] ?? null;
        if (!in_array($type, [ProductRoutePolicy::PERDEA, ProductRoutePolicy::DRAPERII, ProductRoutePolicy::OTHER], true)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'productType trebuie să fie perdea, draperii sau other.');
        }
        $count = $input['pieceCount'] ?? null;
        if (!is_int($count) || $count < 1 || $count > self::MAX_PIECES) {
            throw new ApiException(400, 'INVALID_REQUEST', 'pieceCount trebuie să fie un număr întreg între 1 și ' . self::MAX_PIECES . '.');
        }
        $equal = $input['equalSplit'] ?? false;
        if (!is_bool($equal)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'equalSplit trebuie să fie o valoare booleană.');
        }
        $totalGiven = array_key_exists('materialTotalMeters', $input);
        $total = $input['materialTotalMeters'] ?? null;
        if ($total !== null) {
            $total = self::decimal($total, 'materialTotalMeters');
        }
        $pieces = [];
        $rawPieces = $input['pieces'] ?? [];
        if (!is_array($rawPieces) || !array_is_list($rawPieces) || ($rawPieces !== [] && count($rawPieces) !== $count)) {
            throw new ApiException(400, 'INVALID_REQUEST', 'pieces trebuie să enumere fiecare bucată exact o dată sau să lipsească.');
        }
        $seen = [];
        foreach ($rawPieces as $piece) {
            if (!is_array($piece) || !is_int($piece['index'] ?? null) || $piece['index'] < 1 || $piece['index'] > $count || isset($seen[$piece['index']])) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Fiecare bucată necesită un index distinct între 1 și pieceCount.');
            }
            $seen[$piece['index']] = true;
            $unit = $piece['unit'] ?? null;
            $width = isset($piece['width']) ? self::decimal($piece['width'], 'width') : null;
            $height = isset($piece['height']) ? self::decimal($piece['height'], 'height') : null;
            if (($width !== null || $height !== null) && !in_array($unit, ['mm', 'cm', 'm'], true)) {
                throw new ApiException(400, 'INVALID_REQUEST', 'unit trebuie să fie mm, cm sau m când este precizată o dimensiune.');
            }
            $fabric = isset($piece['fabricMeters']) ? self::decimal($piece['fabricMeters'], 'fabricMeters') : null;
            if ($fabric !== null && $equal) {
                throw new ApiException(400, 'INVALID_REQUEST', 'Precizează metrajul fiecărei bucăți sau equalSplit, nu ambele.');
            }
            $pieces[] = ['index' => $piece['index'], 'width' => $width, 'height' => $height, 'unit' => $width === null && $height === null ? null : $unit, 'fabric' => $fabric];
        }
        usort($pieces, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);
        $note = is_string($input['note'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $input['note']) ?? '') : '';
        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) {
            throw new ApiException(422, 'COMMENT_REQUIRED', 'Explică sursa planului (3-500 de caractere).');
        }
        return ['expectedSourceHash' => $sourceHash, 'expectedRevision' => $expected, 'productType' => (string) $type, 'pieceCount' => $count, 'pieces' => $pieces, 'equalSplit' => $equal,
            'materialTotal' => $total, 'materialTotalGiven' => $totalGiven, 'note' => $note];
    }

    /**
     * Confirms (or revises) the plan of one line. Caller holds the order lock and checked the confirmer's authority.
     *
     * @param array<string, mixed> $request parseConfirmation() result
     * @return array<string, mixed> the line with its new plan
     */
    public function confirm(string $orderUuid, string $itemUuid, array $request, string $actorUuid, string $requestId, string $now): array
    {
        $line = $this->line($orderUuid, $itemUuid) ?? throw new ApiException(404, 'LINE_NOT_FOUND', 'Linia nu a fost găsită.');
        if (!hash_equals($line['sourcePlanHash'], $request['expectedSourceHash'])) {
            throw new ApiException(409, 'SOURCE_PLAN_CHANGED', 'Datele de producție s-au schimbat la sursă. Reîncarcă înainte de confirmare.');
        }
        if ($line['plan']['revision'] !== $request['expectedRevision']) {
            throw new ApiException(409, 'PLAN_CHANGED', 'Planul de producție s-a schimbat. Reîncarcă înainte de a continua.', ['revision' => $line['plan']['revision']]);
        }
        if ($line['plan']['typeLocked'] && $line['plan']['productType'] !== $request['productType']) {
            throw new ApiException(409, 'PLAN_TYPE_LOCKED', 'Tipul produsului a fost deja folosit pentru traseul de producție și nu mai poate fi schimbat.');
        }
        // Reducing the piece count must not drop a piece that is in use (on a track, named by an open request, held).
        $inUse = $this->pdo->prepare(
            "SELECT u.unit_uuid FROM production_work_units u
             LEFT JOIN production_unit_replacements r ON r.replacement_unit_uuid = u.unit_uuid
             WHERE u.item_uuid = ? AND u.status = 'active' AND COALESCE(r.slot_index, u.unit_index) > ?
               AND (u.track_uuid IS NOT NULL OR r.replacement_unit_uuid IS NOT NULL
                 OR EXISTS (SELECT 1 FROM production_exception_units eu INNER JOIN production_exceptions x ON x.exception_uuid = eu.exception_uuid
                            WHERE eu.unit_uuid = u.unit_uuid AND x.resolved_at IS NULL AND x.status NOT IN ('approved', 'rejected', 'cancelled', 'dismissed', 'restored'))
                 OR EXISTS (SELECT 1 FROM production_unit_custody c WHERE c.unit_uuid = u.unit_uuid AND c.hold_reason IS NOT NULL))
             LIMIT 1",
        );
        $inUse->execute([$itemUuid, $request['pieceCount']]);
        if ($inUse->fetchColumn() !== false) {
            throw new ApiException(409, 'PLAN_PIECE_IN_USE', 'O bucată care ar fi eliminată este în refacere, reținută sau într-o cerere deschisă.');
        }
        $total = $request['materialTotalGiven'] ? $request['materialTotal'] : $line['plan']['materialTotal'];
        $totalSource = $request['materialTotalGiven'] ? ($total === null ? 'none' : 'confirmed') : ($total === null ? 'none' : $line['plan']['materialSource']);
        $pieces = $request['pieces'];
        if ($pieces === []) {
            for ($index = 1; $index <= $request['pieceCount']; $index++) {
                $pieces[] = ['index' => $index, 'width' => null, 'height' => null, 'unit' => null, 'fabric' => null];
            }
        }
        $fabricSource = 'confirmed';
        if ($request['equalSplit']) {
            if ($total === null) {
                throw new ApiException(422, 'MATERIAL_TOTAL_UNKNOWN', 'Metrajul total este necunoscut; împărțirea egală nu poate fi calculată.');
            }
            $thousandths = self::thousandths($total);
            if ($thousandths % $request['pieceCount'] !== 0) {
                throw new ApiException(422, 'MATERIAL_SPLIT_NOT_EXACT', 'Metrajul total nu se împarte exact la milimetru; introdu metrajul fiecărei bucăți.');
            }
            foreach ($pieces as &$piece) {
                $piece['fabric'] = self::fromThousandths(intdiv($thousandths, $request['pieceCount']));
            }
            unset($piece);
            $fabricSource = 'equal_split';
        } elseif ($request['pieceCount'] === 1 && $pieces[0]['fabric'] === null && $total !== null) {
            // One piece is the whole line: its fabric is the line's fabric, no division involved.
            $pieces[0]['fabric'] = $total;
        }
        $known = array_filter($pieces, static fn (array $piece): bool => $piece['fabric'] !== null);
        if ($total !== null && count($known) === count($pieces)) {
            $sum = array_sum(array_map(static fn (array $piece): int => self::thousandths((string) $piece['fabric']), $pieces));
            if ($sum !== self::thousandths($total)) {
                throw new ApiException(422, 'MATERIAL_SUM_MISMATCH', 'Suma metrajelor bucăților nu este egală cu metrajul total.', ['total' => $total, 'sum' => self::fromThousandths($sum)]);
            }
        }
        if ($request['pieceCount'] === 1 && $pieces[0]['width'] === null && $pieces[0]['height'] === null && ($line['width'] !== null || $line['height'] !== null)) {
            // One piece is the whole line: its measurements are the line's.
            $pieces[0] = ['index' => 1, 'width' => $line['width'], 'height' => $line['height'], 'unit' => $line['unit'], 'fabric' => $pieces[0]['fabric'], 'source' => 'line'];
        }
        if ($line['plan']['pieceCount'] !== null) {
            $previousPieces = array_map(static fn (array $piece): array => [$piece['index'], $piece['width'], $piece['height'], $piece['unit'], $piece['fabricMeters']], $line['plan']['pieces']);
            $nextPieces = array_map(static fn (array $piece): array => [$piece['index'], $piece['width'], $piece['height'], $piece['unit'], $piece['fabric']], $pieces);
            $changed = $line['stale'] || $line['plan']['productType'] !== $request['productType']
                || $line['plan']['pieceCount'] !== $request['pieceCount'] || $previousPieces !== $nextPieces || $line['plan']['materialTotal'] !== $total;
            if ($changed) {
                $used = $this->pdo->prepare("SELECT 1 FROM production_unit_stage_log l INNER JOIN production_work_units u ON u.unit_uuid = l.unit_uuid
                    WHERE u.item_uuid = ? AND l.outcome = 'completed' AND l.stage_id <> 'waiting' LIMIT 1");
                $used->execute([$itemUuid]);
                if ($used->fetchColumn() !== false) {
                    throw new ApiException(409, 'PLAN_PIECE_IN_USE', 'Planul fizic folosit în producție necesită reconciliere verificată; o revizie nu poate reutiliza etapele vechi pentru alte măsuri sau bucăți.');
                }
            }
        }
        $this->write($orderUuid, $line, [
            'productType' => $request['productType'], 'typeSource' => 'confirmed', 'pieceCount' => $request['pieceCount'],
            'materialTotal' => $total, 'materialSource' => $totalSource, 'equalSplit' => $request['equalSplit'], 'pieces' => $pieces, 'fabricSource' => $fabricSource,
        ], $actorUuid, $request['note'], $requestId, $now);
        return $this->line($orderUuid, $itemUuid) ?? throw new \RuntimeException('The confirmed line could not be reloaded.');
    }

    /** @param list<string> $itemUuids @return array<string, array<string, mixed>> head plan rows with pieces, by item */
    private function heads(array $itemUuids): array
    {
        if ($itemUuids === []) {
            return [];
        }
        try {
            $marks = implode(',', array_fill(0, count($itemUuids), '?'));
            $statement = $this->pdo->prepare(
                "SELECT p.*, h.type_locked_at, e.display_name AS confirmer_name FROM production_line_plan_heads h
                 INNER JOIN production_line_plans p ON p.plan_uuid = h.plan_uuid LEFT JOIN employees e ON e.employee_uuid = p.confirmed_by_employee_uuid
                 WHERE h.item_uuid IN ({$marks})",
            );
            $statement->execute($itemUuids);
            $plans = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $plans[(string) $row['plan_uuid']] = $row + ['pieces' => []];
            }
            if ($plans !== []) {
                $pieceMarks = implode(',', array_fill(0, count($plans), '?'));
                $pieces = $this->pdo->prepare("SELECT * FROM production_plan_pieces WHERE plan_uuid IN ({$pieceMarks}) ORDER BY plan_uuid, piece_index");
                $pieces->execute(array_keys($plans));
                foreach ($pieces->fetchAll(PDO::FETCH_ASSOC) as $piece) {
                    $plans[(string) $piece['plan_uuid']]['pieces'][] = $piece;
                }
            }
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            // Before migration 029 no plan exists.
            return [];
        }
        $byItem = [];
        foreach ($plans as $plan) {
            $byItem[(string) $plan['item_uuid']] = $plan;
        }
        return $byItem;
    }

    /** @param array<string, mixed> $row item row @param array<string, mixed>|null $head @return array<string, mixed> */
    private function resolve(array $row, ?array $head): array
    {
        $context = is_string($row['production_context']) ? json_decode($row['production_context'], true) : null;
        $context = is_array($context) ? $context : [];
        $quantity = (int) $row['quantity'];
        $lineMeters = self::measured($row['meters']) ? self::normalize((string) $row['meters']) : null;
        $derivedType = ProductRoutePolicy::fromSourceKind($context['kind'] ?? null);
        if ($head === null) {
            $plan = [
                'id' => null, 'revision' => 0, 'productType' => $derivedType, 'typeSource' => $derivedType === ProductRoutePolicy::UNKNOWN ? 'none' : 'source_classification',
                'typeLocked' => false, 'pieceCount' => null, 'pieceCountSource' => 'none', 'materialTotal' => $lineMeters, 'materialSource' => $lineMeters === null ? 'none' : 'source_line',
                'equalSplit' => false, 'confirmedBy' => null, 'confirmedAt' => null, 'note' => null, 'commercialQuantityAtConfirmation' => null, 'pieces' => [],
            ];
        } else {
            $plan = [
                'id' => (string) $head['plan_uuid'], 'revision' => (int) $head['revision'], 'productType' => (string) $head['product_type'], 'typeSource' => (string) $head['type_source'],
                'typeLocked' => $head['type_locked_at'] !== null, 'pieceCount' => $head['piece_count'] === null ? null : (int) $head['piece_count'], 'pieceCountSource' => (string) $head['piece_count_source'],
                'materialTotal' => $head['material_total'] === null ? null : self::normalize((string) $head['material_total']), 'materialSource' => (string) $head['material_source'],
                'equalSplit' => (int) $head['equal_split'] === 1,
                'confirmedBy' => $head['confirmed_by_employee_uuid'] === null ? null : ['id' => (string) $head['confirmed_by_employee_uuid'], 'displayName' => (string) $head['confirmer_name']],
                'confirmedAt' => $head['confirmed_at'] === null ? null : self::iso((string) $head['confirmed_at']), 'note' => $head['confirmation_note'],
                'commercialQuantityAtConfirmation' => (int) $head['commercial_quantity_snapshot'],
                'pieces' => array_map(static fn (array $piece): array => [
                    'index' => (int) $piece['piece_index'],
                    'width' => $piece['width_value'] === null ? null : self::normalize((string) $piece['width_value']),
                    'height' => $piece['height_value'] === null ? null : self::normalize((string) $piece['height_value']),
                    'unit' => $piece['measurement_unit'] === null ? null : (string) $piece['measurement_unit'],
                    'fabricMeters' => $piece['fabric_length'] === null ? null : self::normalize((string) $piece['fabric_length']),
                    'measurementSource' => (string) $piece['measurement_source'], 'fabricSource' => (string) $piece['fabric_source'],
                ], $head['pieces']),
            ];
        }
        $stale = $plan['pieceCount'] !== null && ($plan['commercialQuantityAtConfirmation'] !== $quantity || ($head !== null && $head['source_plan_hash'] !== self::sourcePlanHash($row)));
        $known = $plan['pieceCount'] !== null && !$stale;
        return [
            'itemId' => (string) $row['item_uuid'],
            'orderUuid' => (string) $row['order_uuid'],
            'sourceItemId' => (string) $row['source_item_id'],
            'lineNumber' => (int) $row['line_number'],
            'name' => (string) $row['name'],
            'code' => $row['product_code'] === null ? null : (string) $row['product_code'],
            'variant' => $row['variant'] === null ? null : (string) $row['variant'],
            'color' => $row['color'] === null ? null : (string) $row['color'],
            'commercialQuantity' => $quantity,
            'width' => $row['width_value'] === null ? null : self::normalize((string) $row['width_value']),
            'height' => $row['height_value'] === null ? null : self::normalize((string) $row['height_value']),
            'unit' => $row['measurement_unit'] === null ? null : (string) $row['measurement_unit'],
            'meters' => $lineMeters,
            'sourcePlanHash' => self::sourcePlanHash($row),
            'plan' => $plan,
            'known' => $known,
            'stale' => $stale,
            'typeVerified' => $plan['productType'] !== ProductRoutePolicy::UNKNOWN,
            'proposal' => self::proposal((string) $row['source_key'], $context, $quantity, $derivedType),
        ];
    }

    /**
     * Evidence the source carries about the physical plan, shown to the confirmer and never applied automatically.
     * @param array<string, mixed> $context @return array<string, mixed>
     */
    private static function proposal(string $source, array $context, int $quantity, string $derivedType): array
    {
        $evidence = [['kind' => 'commercial_quantity', 'value' => (string) $quantity]];
        foreach (is_array($context['options'] ?? null) ? $context['options'] : [] as $option) {
            if (is_array($option) && is_string($option['label'] ?? null) && is_string($option['value'] ?? null)
                && preg_match('/\b(buc|bucăți|bucati|bucată|bucata|segment|panou|panouri|pieces|kanat)\b/iu', $option['label'] . ' ' . $option['value']) === 1) {
                $evidence[] = ['kind' => 'source_option', 'value' => mb_substr($option['label'] . ': ' . $option['value'], 0, 200)];
            }
        }
        $layout = $context['project']['treatment']['panelLayout'] ?? null;
        if (is_string($layout)) {
            $evidence[] = ['kind' => 'panel_layout', 'value' => $layout];
        }
        if ($derivedType !== ProductRoutePolicy::UNKNOWN) {
            $evidence[] = ['kind' => 'source_classification', 'value' => (string) ($context['kind'] ?? '')];
        }
        return ['source' => $source, 'evidence' => $evidence];
    }

    /** Production-relevant source metadata only; catalog prose changes do not replace physical identities. */
    private static function sourcePlanHash(array $row): string
    {
        $context = json_decode((string) ($row['production_context'] ?? ''), true);
        $context = is_array($context) ? $context : [];
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($canonical, $value);
        };
        return hash('sha256', json_encode($canonical([
            'sourceItemId' => $row['source_item_id'], 'quantity' => (int) $row['quantity'],
            'productCode' => $row['product_code'], 'variant' => $row['variant'], 'color' => $row['color'],
            'width' => $row['width_value'] === null ? null : self::normalize((string) $row['width_value']),
            'height' => $row['height_value'] === null ? null : self::normalize((string) $row['height_value']),
            'unit' => $row['measurement_unit'], 'meters' => $row['meters'] === null ? null : self::normalize((string) $row['meters']),
            'kind' => $context['kind'] ?? null, 'options' => $context['options'] ?? null,
            'project' => $context['project'] ?? null,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Writes a new plan revision with its pieces and moves the head. @param array<string, mixed> $line @param array<string, mixed> $plan
     */
    private function write(string $orderUuid, array $line, array $plan, ?string $actorUuid, string $note, ?string $requestId, string $now): void
    {
        $previous = $line['plan']['id'];
        $revision = $line['plan']['revision'] + 1;
        $uuid = Uuid::v4();
        $this->pdo->prepare(
            'INSERT INTO production_line_plans (plan_uuid, order_uuid, item_uuid, source_item_id_snapshot, revision, product_type, type_source, commercial_quantity_snapshot, source_plan_hash, piece_count, piece_count_source,
                material_total, material_unit, material_source, equal_split, confirmed_by_employee_uuid, confirmed_at, confirmation_note, supersedes_plan_uuid, request_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'m\', ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $uuid, $orderUuid, $line['itemId'], mb_substr((string) $line['sourceItemId'], 0, 128), $revision, $plan['productType'], $plan['typeSource'], $line['commercialQuantity'], $line['sourcePlanHash'],
            $plan['pieceCount'], $plan['pieceCount'] === null ? 'none' : 'confirmed', $plan['materialTotal'], $plan['materialTotal'] === null ? 'none' : $plan['materialSource'],
            $plan['equalSplit'] ? 1 : 0, $actorUuid, $actorUuid === null ? null : $now, mb_substr($note, 0, 500), $previous, $requestId === null ? null : mb_substr($requestId, 0, 100), $now,
        ]);
        $insert = $this->pdo->prepare('INSERT INTO production_plan_pieces (plan_uuid, piece_index, width_value, height_value, measurement_unit, fabric_length, measurement_source, fabric_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($plan['pieces'] as $piece) {
            $sized = $piece['width'] !== null || $piece['height'] !== null;
            $insert->execute([$uuid, $piece['index'], $piece['width'], $piece['height'], $sized ? $piece['unit'] : null, $piece['fabric'],
                $piece['source'] ?? ($sized ? 'confirmed' : 'unknown'), $piece['fabric'] === null ? 'unknown' : ($plan['fabricSource'] ?? 'confirmed')]);
        }
        if ($previous === null) {
            $this->pdo->prepare('INSERT INTO production_line_plan_heads (item_uuid, order_uuid, plan_uuid, revision, type_locked_at, updated_at) VALUES (?, ?, ?, ?, NULL, ?)')
                ->execute([$line['itemId'], $orderUuid, $uuid, $revision, $now]);
            return;
        }
        $move = $this->pdo->prepare('UPDATE production_line_plan_heads SET plan_uuid = ?, revision = ?, updated_at = ? WHERE item_uuid = ? AND plan_uuid = ?');
        $move->execute([$uuid, $revision, $now, $line['itemId'], $previous]);
        if ($move->rowCount() !== 1) {
            throw new ApiException(409, 'PLAN_CHANGED', 'Planul de producție s-a schimbat. Reîncarcă înainte de a continua.');
        }
    }

    private static function decimal(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match(self::DECIMAL, $value) !== 1 || self::thousandths($value) === 0) {
            throw new ApiException(400, 'INVALID_REQUEST', "{$field} trebuie să fie un șir zecimal pozitiv cu maximum 3 zecimale.");
        }
        return self::normalize($value);
    }

    /** Exact integer thousandths of a non-negative decimal string. */
    public static function thousandths(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return (int) $whole * 1000 + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }

    public static function fromThousandths(int $value): string
    {
        return sprintf('%d.%03d', intdiv($value, 1000), $value % 1000);
    }

    public static function normalize(string $value): string
    {
        return self::fromThousandths(self::thousandths($value));
    }

    private static function measured(mixed $meters): bool
    {
        return $meters !== null && preg_match('/^0*\.?0*$/', (string) $meters) !== 1;
    }

    private static function iso(string $value): string
    {
        return substr(str_replace(' ', 'T', $value), 0, 23) . 'Z';
    }
}
