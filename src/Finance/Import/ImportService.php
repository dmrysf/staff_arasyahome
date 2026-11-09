<?php

declare(strict_types=1);

namespace Arasya\Operations\Finance\Import;

use Arasya\Operations\Employee\EmployeeIdentity;
use Arasya\Operations\Finance\Company\CaseService;
use Arasya\Operations\Finance\Company\DuplicateFinder;
use Arasya\Operations\Finance\FinanceAccess as A;
use Arasya\Operations\Finance\FinanceInput as In;
use Arasya\Operations\Finance\FinanceStore;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Support\Uuid;
use PDO;

/**
 * Company import workflow: upload (validated, parsed once, raw file discarded) -> column mapping -> chunked analysis
 * (normalization, in-file duplicates, earlier imports, duplicate candidates) -> dry-run preview -> explicit human
 * confirmation -> chunked staging into verification cases. Every step is resumable: analysis and staging advance a
 * cursor stored on the batch, so a client simply repeats the call until the batch reports completion, and a
 * retried chunk never creates a second case (unique import row and fingerprint per case).
 * Import never creates a booked balance or a credit limit; declared amounts stay informational source data.
 */
final readonly class ImportService
{
    public const CHUNK = 250;
    public const SOURCES = ['CSV', 'XLSX', 'SAGA_EXPORT', 'SMARTBILL_EXPORT', 'OTHER_LEGACY'];
    public const PROFILES = ['STANDARD', 'LEGACY_CUSTOMER_LIST'];

    public function __construct(private FinanceStore $store, private CaseService $cases)
    {
    }

    /** @param array{body: string, format: string, fileName: string, source: string, encoding: string, profile?: string} $upload */
    public function upload(EmployeeIdentity $actor, array $upload, string $key, string $request): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_IMPORT);
        if (!in_array($upload['source'], self::SOURCES, true)) {
            In::fail('source', 'invalid');
        }
        $profile = $upload['profile'] ?? 'STANDARD';
        if (!in_array($profile, self::PROFILES, true)) {
            In::fail('profile', 'invalid');
        }
        $name = self::fileName($upload['fileName']);
        $table = ImportFileReader::read($upload['body'], $upload['format'], $upload['encoding']);
        $sha = hash('sha256', $upload['body'], true);
        $body = ['sha256' => bin2hex($sha), 'source' => $upload['source'], 'format' => $upload['format'], 'name' => $name, 'encoding' => $upload['encoding'], 'profile' => $profile];
        return $this->store->mutate($actor, 'import_upload', $body, $key, [A::COMPANIES_IMPORT], function (string $now) use ($actor, $upload, $profile, $name, $table, $sha, $key, $request): array {
            $pdo = $this->store->pdo();
            $s = $pdo->prepare('SELECT batch_uuid FROM finance_import_batches WHERE active_file_sha256 = ? FOR UPDATE');
            $s->execute([$sha]);
            $existing = $s->fetchColumn();
            if ($existing !== false) {
                throw new ApiException(409, 'DUPLICATE_UPLOAD', 'This file was already uploaded.', ['batchId' => (string) $existing]);
            }
            $id = Uuid::v4();
            $pdo->prepare("INSERT INTO finance_import_batches (batch_uuid, source_system, import_profile, file_format, file_name, file_sha256, active_file_sha256, file_bytes, header_json, mapping_json,
                    status, row_count, created_by_employee_uuid, created_at, updated_at, version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 'uploaded', ?, ?, ?, ?, 1)")
                ->execute([$id, $upload['source'], $profile, $table['format'], $name, $sha, $sha, strlen($upload['body']), FinanceStore::json($table['headers']), count($table['rows']), $actor->employeeUuid, $now, $now]);
            foreach (array_chunk($table['rows'], 500, true) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $index => $cells) {
                    $values[] = '(?, ?, ?, ?, ?, ?)';
                    array_push($params, Uuid::v4(), $id, $index + 1, FinanceStore::json($cells), $now, $now);
                }
                $pdo->prepare('INSERT INTO finance_import_rows (row_uuid, batch_uuid, line_no, raw_json, created_at, updated_at) VALUES ' . implode(', ', $values))->execute($params);
            }
            $this->store->event('import', $id, null, $actor, 'import_uploaded', null, 1, null, ['rows' => count($table['rows']), 'format' => $table['format'], 'source' => $upload['source'], 'profile' => $profile], $request, $key, $now);
            return ['status' => 201, 'batchId' => $id];
        });
    }

    public function map(EmployeeIdentity $actor, string $batchId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'mapping']);
        $version = In::version($input);
        $mapping = $input['mapping'] ?? null;
        if (!is_array($mapping) || $mapping === [] || count($mapping) > ImportFileReader::MAX_COLUMNS) {
            In::fail('mapping', 'invalid');
        }
        return $this->store->mutate($actor, 'import_map:' . $batchId, $input, $key, [A::COMPANIES_IMPORT], function (string $now) use ($actor, $batchId, $version, $mapping, $key, $request): array {
            $batch = $this->lockBatch($batchId);
            FinanceStore::expectVersion($batch, $version);
            if (!in_array($batch['status'], ['uploaded', 'mapped', 'analyzed'], true)) {
                throw new ApiException(409, 'IMPORT_LOCKED', 'The mapping can no longer be changed.');
            }
            $headers = json_decode((string) $batch['header_json'], true);
            $clean = [];
            $used = [];
            foreach ($mapping as $column => $field) {
                if (!is_string($column) && !is_int($column) || preg_match('/^\d{1,3}$/D', (string) $column) !== 1 || (int) $column >= count($headers)) {
                    In::fail('mapping', 'unknown_column');
                }
                if ($field === null || $field === '') {
                    continue;
                }
                if (!is_string($field) || !in_array($field, ImportNormalizer::FIELDS, true)) {
                    In::fail('mapping', 'unknown_field');
                }
                if (isset($used[$field])) {
                    In::fail('mapping', 'field_mapped_twice');
                }
                $used[$field] = true;
                $clean[(int) $column] = $field;
            }
            if (!isset($used['legalName']) && !isset($used['commercialName'])) {
                In::fail('mapping', 'company_name_required');
            }
            ksort($clean);
            $pdo = $this->store->pdo();
            $pdo->prepare("UPDATE finance_import_rows SET normalized_json = NULL, issues_json = NULL, flags_json = NULL, fingerprint = NULL, content_fingerprint = NULL, identity_key = NULL, case_uuid = NULL, outcome = 'pending', duplicate_strength = NULL, updated_at = ? WHERE batch_uuid = ?")
                ->execute([$now, $batchId]);
            $pdo->prepare("UPDATE finance_import_batches SET mapping_json = ?, status = 'mapped', analysis_cursor = 0, ready_rows = 0, incomplete_rows = 0, duplicate_rows = 0, known_rows = 0, rejected_rows = 0,
                    updated_at = ?, version = version + 1 WHERE batch_uuid = ?")->execute([FinanceStore::json((object) $clean), $now, $batchId]);
            $this->store->event('import', $batchId, null, $actor, 'import_mapped', (int) $batch['version'], (int) $batch['version'] + 1, null, ['fields' => array_values($clean)], $request, $key, $now);
            return ['status' => 200, 'batchId' => $batchId, 'version' => (int) $batch['version'] + 1];
        });
    }

    /** Analyzes the next chunk of rows. Repeat until status is "analyzed". */
    public function analyze(EmployeeIdentity $actor, string $batchId, array $input, string $key, string $request): array
    {
        In::only($input, []);
        return $this->store->mutate($actor, 'import_analyze:' . $batchId, $input, $key, [A::COMPANIES_IMPORT], function (string $now) use ($actor, $batchId, $key, $request): array {
            $batch = $this->lockBatch($batchId);
            if ($batch['status'] === 'analyzed') {
                return ['status' => 200, 'batchId' => $batchId, 'done' => true, 'version' => (int) $batch['version']];
            }
            if ($batch['status'] !== 'mapped') {
                throw new ApiException(409, 'IMPORT_NOT_MAPPED', 'Map the columns before analysis.');
            }
            $mapping = array_map('strval', (array) json_decode((string) $batch['mapping_json'], true));
            $mapping = array_combine(array_map('intval', array_keys($mapping)), array_values($mapping));
            $pdo = $this->store->pdo();
            $s = $pdo->prepare('SELECT row_uuid, line_no, raw_json FROM finance_import_rows WHERE batch_uuid = ? AND line_no > ? ORDER BY line_no LIMIT ' . self::CHUNK);
            $s->execute([$batchId, (int) $batch['analysis_cursor']]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            $finder = new DuplicateFinder($pdo);
            $counts = ['ready' => 0, 'incomplete' => 0, 'duplicate_in_file' => 0, 'known' => 0, 'rejected' => 0];
            $earlier = $pdo->prepare("SELECT line_no FROM finance_import_rows WHERE batch_uuid = ? AND identity_key = ? AND line_no < ? AND outcome IN ('ready', 'incomplete') LIMIT 1");
            $known = $pdo->prepare('SELECT case_uuid FROM finance_company_cases WHERE source_fingerprint = ? LIMIT 1');
            $update = $pdo->prepare('UPDATE finance_import_rows SET normalized_json = ?, issues_json = ?, flags_json = ?, fingerprint = ?, content_fingerprint = ?, identity_key = ?, outcome = ?, duplicate_strength = ?, case_uuid = ?, updated_at = ? WHERE row_uuid = ?');
            $legacy = $batch['import_profile'] === 'LEGACY_CUSTOMER_LIST';
            // Identical rows of one file stay separate records: each occurrence gets its own stable fingerprint.
            $occurrence = $pdo->prepare('SELECT COUNT(*) FROM finance_import_rows WHERE batch_uuid = ? AND content_fingerprint = ? AND line_no < ?');
            $last = (int) $batch['analysis_cursor'];
            foreach ($rows as $row) {
                $result = ImportNormalizer::normalize($mapping, (array) json_decode((string) $row['raw_json'], true), (string) $batch['source_system'], $legacy);
                $v = $result['values'];
                $outcome = $result['outcome'];
                $content = null;
                $fingerprint = $result['fingerprint'];
                if ($legacy) {
                    $content = $fingerprint;
                    $occurrence->execute([$batchId, $content, (int) $row['line_no']]);
                    $fingerprint = hash('sha256', $content . '|' . (int) $occurrence->fetchColumn(), true);
                }
                $knownCase = null;
                $identity = $v['countryCode'] !== null && $v['taxNormalized'] !== null ? $v['countryCode'] . '|' . $v['taxNormalized'] : null;
                $strength = null;
                if ($outcome !== 'rejected') {
                    if ($identity !== null && !$legacy) {
                        $earlier->execute([$batchId, $identity, (int) $row['line_no']]);
                        if ($earlier->fetchColumn() !== false) {
                            $outcome = 'duplicate_in_file';
                        }
                    }
                    if ($outcome !== 'duplicate_in_file') {
                        $known->execute([$fingerprint]);
                        $found = $known->fetchColumn();
                        if ($found !== false) {
                            $outcome = 'known';
                            $knownCase = (string) $found;
                        } else {
                            $strength = DuplicateFinder::strongest($finder->find($v));
                        }
                    }
                }
                $counts[$outcome]++;
                $update->execute([FinanceStore::json($v), $result['issues'] === [] ? null : FinanceStore::json($result['issues']), $result['flags'] === [] ? null : FinanceStore::json($result['flags']),
                    $fingerprint, $content, $identity, $outcome, $strength, $knownCase, $now, $row['row_uuid']]);
                $last = (int) $row['line_no'];
            }
            $done = $last >= (int) $batch['row_count'];
            $pdo->prepare('UPDATE finance_import_batches SET analysis_cursor = ?, ready_rows = ready_rows + ?, incomplete_rows = incomplete_rows + ?, duplicate_rows = duplicate_rows + ?, known_rows = known_rows + ?,
                    rejected_rows = rejected_rows + ?, status = ?, updated_at = ?, version = version + 1 WHERE batch_uuid = ?')
                ->execute([$last, $counts['ready'], $counts['incomplete'], $counts['duplicate_in_file'], $counts['known'], $counts['rejected'], $done ? 'analyzed' : 'mapped', $now, $batchId]);
            if ($done) {
                $this->store->event('import', $batchId, null, $actor, 'import_analyzed', null, (int) $batch['version'] + 1, null, null, $request, $key, $now);
            }
            return ['status' => 200, 'batchId' => $batchId, 'done' => $done, 'processed' => count($rows), 'cursor' => $last, 'version' => (int) $batch['version'] + 1];
        });
    }

    /**
     * Human confirmation, then staging in chunks. The first call (status analyzed) must carry confirm=true and the
     * analyzed version the person looked at; later calls continue until status is "completed".
     */
    public function confirm(EmployeeIdentity $actor, string $batchId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'confirm']);
        return $this->store->mutate($actor, 'import_confirm:' . $batchId, $input, $key, [A::COMPANIES_IMPORT], function (string $now) use ($actor, $batchId, $input, $key, $request): array {
            $batch = $this->lockBatch($batchId);
            $pdo = $this->store->pdo();
            if ($batch['status'] === 'completed') {
                return ['status' => 200, 'batchId' => $batchId, 'done' => true, 'version' => (int) $batch['version']];
            }
            if ($batch['status'] === 'analyzed') {
                if (($input['confirm'] ?? null) !== true) {
                    In::fail('confirm', 'required');
                }
                FinanceStore::expectVersion($batch, In::version($input), 'IMPORT_CHANGED');
                $this->store->event('import', $batchId, null, $actor, 'import_confirmed', (int) $batch['version'], (int) $batch['version'] + 1, null,
                    ['ready' => (int) $batch['ready_rows'], 'incomplete' => (int) $batch['incomplete_rows']], $request, $key, $now);
            } elseif ($batch['status'] !== 'confirming') {
                throw new ApiException(409, 'IMPORT_NOT_ANALYZED', 'Analyze the file before confirming the import.');
            }
            $s = $pdo->prepare("SELECT row_uuid, line_no, normalized_json, flags_json, fingerprint FROM finance_import_rows WHERE batch_uuid = ? AND line_no > ? AND outcome IN ('ready', 'incomplete') ORDER BY line_no LIMIT " . self::CHUNK . ' FOR UPDATE');
            $s->execute([$batchId, (int) $batch['confirm_cursor']]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            $known = $pdo->prepare('SELECT case_uuid FROM finance_company_cases WHERE source_fingerprint = ? FOR UPDATE');
            $mark = $pdo->prepare('UPDATE finance_import_rows SET outcome = ?, case_uuid = ?, updated_at = ? WHERE row_uuid = ?');
            $staged = 0;
            $last = (int) $batch['confirm_cursor'];
            foreach ($rows as $row) {
                $known->execute([$row['fingerprint']]);
                $existing = $known->fetchColumn();
                if ($existing !== false) {
                    // Imported meanwhile by another batch: idempotent, the same record is never staged twice.
                    $mark->execute(['known', (string) $existing, $now, $row['row_uuid']]);
                } else {
                    $candidate = (array) json_decode((string) $row['normalized_json'], true);
                    $candidate['reviewFlags'] = $row['flags_json'] === null ? [] : (array) json_decode((string) $row['flags_json'], true);
                    $case = $this->cases->createCase($candidate, 'IMPORT', (string) $batch['source_system'], (string) $row['row_uuid'], (string) $row['fingerprint'], null, $actor, $now, $request, $key);
                    $mark->execute(['staged', $case['caseId'], $now, $row['row_uuid']]);
                    $staged++;
                }
                $last = (int) $row['line_no'];
            }
            $done = count($rows) < self::CHUNK;
            $pdo->prepare('UPDATE finance_import_batches SET status = ?, confirm_cursor = ?, staged_rows = staged_rows + ?, updated_at = ?, version = version + 1 WHERE batch_uuid = ?')
                ->execute([$done ? 'completed' : 'confirming', $last, $staged, $now, $batchId]);
            if ($done) {
                $this->store->event('import', $batchId, null, $actor, 'import_completed', null, (int) $batch['version'] + 1, null, null, $request, $key, $now);
            }
            return ['status' => 200, 'batchId' => $batchId, 'done' => $done, 'staged' => $staged, 'version' => (int) $batch['version'] + 1];
        });
    }

    /** Cancels a batch before staging started; the same file may then be uploaded again. */
    public function cancel(EmployeeIdentity $actor, string $batchId, array $input, string $key, string $request): array
    {
        In::only($input, ['expectedVersion', 'reason']);
        $version = In::version($input);
        $reason = (string) In::reason($input);
        return $this->store->mutate($actor, 'import_cancel:' . $batchId, $input, $key, [A::COMPANIES_IMPORT], function (string $now) use ($actor, $batchId, $version, $reason, $key, $request): array {
            $batch = $this->lockBatch($batchId);
            FinanceStore::expectVersion($batch, $version);
            if (!in_array($batch['status'], ['uploaded', 'mapped', 'analyzed'], true)) {
                throw new ApiException(409, 'IMPORT_NOT_CANCELLABLE', 'Staging already started; review the staged cases instead.');
            }
            $this->store->pdo()->prepare("UPDATE finance_import_batches SET status = 'cancelled', active_file_sha256 = NULL, updated_at = ?, version = version + 1 WHERE batch_uuid = ?")->execute([$now, $batchId]);
            $this->store->event('import', $batchId, null, $actor, 'import_cancelled', (int) $batch['version'], (int) $batch['version'] + 1, $reason, null, $request, $key, $now);
            return ['status' => 200, 'batchId' => $batchId, 'version' => (int) $batch['version'] + 1];
        });
    }

    // ---- reads ---------------------------------------------------------------------------------------------

    public function list(EmployeeIdentity $actor, array $query): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_IMPORT);
        $s = $this->store->pdo()->query('SELECT b.*, e.display_name FROM finance_import_batches b JOIN employees e ON e.employee_uuid = b.created_by_employee_uuid ORDER BY b.created_at DESC, b.batch_uuid LIMIT 50');
        return ['items' => array_map(fn (array $b): array => $this->present($b), $s->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function detail(EmployeeIdentity $actor, string $batchId): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_IMPORT);
        $batch = $this->read($batchId);
        $headers = json_decode((string) $batch['header_json'], true);
        $s = $this->store->pdo()->prepare('SELECT raw_json FROM finance_import_rows WHERE batch_uuid = ? ORDER BY line_no LIMIT 5');
        $s->execute([$batchId]);
        return $this->present($batch) + [
            'headers' => $headers,
            'mapping' => $batch['mapping_json'] === null ? null : json_decode((string) $batch['mapping_json'], true),
            'suggestedMapping' => (object) ImportNormalizer::suggestMapping($headers),
            'sample' => array_map(static fn ($r): array => json_decode((string) $r, true), $s->fetchAll(PDO::FETCH_COLUMN)),
            'fields' => ImportNormalizer::FIELDS,
            'history' => $this->store->history('import', $batchId, 50),
        ];
    }

    /** Dry-run preview rows with normalized values, issues and duplicate strength. */
    public function rows(EmployeeIdentity $actor, string $batchId, array $query): array
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_IMPORT);
        $this->read($batchId);
        $outcome = $query['outcome'] ?? null;
        if ($outcome !== null && !in_array($outcome, ['pending', 'ready', 'incomplete', 'duplicate_in_file', 'known', 'rejected', 'staged'], true)) {
            In::fail('outcome', 'invalid');
        }
        $cursor = (int) ($query['cursor'] ?? 0);
        $limit = (int) ($query['limit'] ?? 50);
        if ($cursor < 0 || !in_array($limit, [25, 50, 100], true)) {
            In::fail('limit', 'invalid');
        }
        $sql = 'SELECT line_no, raw_json, normalized_json, issues_json, flags_json, outcome, duplicate_strength, case_uuid FROM finance_import_rows WHERE batch_uuid = ? AND line_no > ?' . ($outcome !== null ? ' AND outcome = ?' : '') . ' ORDER BY line_no LIMIT ' . ($limit + 1);
        $s = $this->store->pdo()->prepare($sql);
        $s->execute($outcome !== null ? [$batchId, $cursor, $outcome] : [$batchId, $cursor]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) > $limit ? (int) $rows[$limit - 1]['line_no'] : null;
        $rows = array_slice($rows, 0, $limit);
        return ['items' => array_map(static fn (array $r): array => [
            'line' => (int) $r['line_no'], 'raw' => json_decode((string) $r['raw_json'], true),
            'values' => $r['normalized_json'] === null ? null : json_decode((string) $r['normalized_json'], true),
            'issues' => $r['issues_json'] === null ? (object) [] : json_decode((string) $r['issues_json'], true),
            'flags' => $r['flags_json'] === null ? [] : json_decode((string) $r['flags_json'], true),
            'outcome' => $r['outcome'], 'duplicateStrength' => $r['duplicate_strength'], 'caseId' => $r['case_uuid'],
        ], $rows), 'nextCursor' => $next];
    }

    /** Rejection report as CSV. Cells are neutralized against spreadsheet formula injection. */
    public function rejectionsCsv(EmployeeIdentity $actor, string $batchId): string
    {
        A::require($this->store->authorization(), $actor, A::COMPANIES_IMPORT);
        $batch = $this->read($batchId);
        $headers = json_decode((string) $batch['header_json'], true);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(self::safeCell(...), ['line', 'outcome', 'issues', ...$headers]), ',', '"', '');
        $s = $this->store->pdo()->prepare("SELECT line_no, raw_json, issues_json, outcome FROM finance_import_rows WHERE batch_uuid = ? AND outcome IN ('rejected', 'duplicate_in_file', 'incomplete') ORDER BY line_no LIMIT 20000");
        $s->execute([$batchId]);
        while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
            $issues = $r['issues_json'] === null ? [] : (array) json_decode((string) $r['issues_json'], true);
            $text = implode('; ', array_map(static fn ($f, $c): string => "{$f}:{$c}", array_keys($issues), $issues));
            fputcsv($out, array_map(self::safeCell(...), [(string) $r['line_no'], (string) $r['outcome'], $text, ...(array) json_decode((string) $r['raw_json'], true)]), ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    public static function safeCell(mixed $value): string
    {
        $value = (string) $value;
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    public static function fileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '_', $name);
        $name = trim($name, ' .');
        return $name === '' ? 'import' : mb_substr($name, 0, 200);
    }

    /** @return array<string, mixed> */
    private function present(array $b): array
    {
        return [
            'id' => $b['batch_uuid'], 'source' => $b['source_system'], 'profile' => $b['import_profile'], 'format' => $b['file_format'], 'fileName' => $b['file_name'], 'bytes' => (int) $b['file_bytes'],
            'sha256' => bin2hex((string) $b['file_sha256']), 'status' => $b['status'], 'rowCount' => (int) $b['row_count'],
            'analyzed' => (int) $b['analysis_cursor'], 'confirmed' => (int) $b['confirm_cursor'],
            'counts' => ['ready' => (int) $b['ready_rows'], 'incomplete' => (int) $b['incomplete_rows'], 'duplicateInFile' => (int) $b['duplicate_rows'],
                'known' => (int) $b['known_rows'], 'rejected' => (int) $b['rejected_rows'], 'staged' => (int) $b['staged_rows']],
            'createdBy' => $b['display_name'] ?? null, 'createdAt' => FinanceStore::iso($b['created_at']), 'updatedAt' => FinanceStore::iso($b['updated_at']), 'version' => (int) $b['version'],
        ];
    }

    private function read(string $batchId): array
    {
        if (!In::isUuid($batchId)) {
            throw new ApiException(404, 'IMPORT_NOT_FOUND', 'Import was not found.');
        }
        $s = $this->store->pdo()->prepare('SELECT b.*, e.display_name FROM finance_import_batches b JOIN employees e ON e.employee_uuid = b.created_by_employee_uuid WHERE b.batch_uuid = ?');
        $s->execute([$batchId]);
        $batch = $s->fetch(PDO::FETCH_ASSOC);
        if (!$batch) {
            throw new ApiException(404, 'IMPORT_NOT_FOUND', 'Import was not found.');
        }
        return $batch;
    }

    private function lockBatch(string $batchId): array
    {
        return $this->store->lock('finance_import_batches', 'batch_uuid', $batchId, 'IMPORT_NOT_FOUND', 'Import was not found.');
    }
}
