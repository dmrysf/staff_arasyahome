<?php

declare(strict_types=1);

// Registry of physical production locations (migration 029): cutting tables, the supervisor's inspection table, rails,
// rework holding areas, packing waiting areas. The software never invents a location; an operator registers the places
// that really exist, with the identifier written on them. Retired locations stay for history.
//
//   php bin/production-locations.php list
//   php bin/production-locations.php add --id=<a-z0-9-> --label=<text> --kind=<kind> --operator=<name> [--stage=<stage id>]
//   php bin/production-locations.php retire --id=<a-z0-9-> --operator=<name>
//
// kind: cutting, tailoring_intake, supervisor_table, rail, rework_hold, quality, packing_wait, other.
// Exit codes: 0 done; 1 failed; 2 invalid invocation (nothing changed).

$commands = ['list' => [[], []], 'add' => [['id', 'label', 'kind', 'operator'], ['stage']], 'retire' => [['id', 'operator'], []]];
$kinds = ['cutting', 'tailoring_intake', 'supervisor_table', 'rail', 'rework_hold', 'quality', 'packing_wait', 'other'];
$refuse = static function (string $message): never {
    fwrite(STDERR, "Refused: {$message}\nUsage: php bin/production-locations.php list|add|retire [--name=value ...]\n");
    exit(2);
};
$arguments = array_slice($argv, 1);
$command = $arguments === [] ? 'list' : (string) array_shift($arguments);
if (!array_key_exists($command, $commands)) {
    $refuse('unsupported command.');
}
[$required, $optional] = $commands[$command];
$options = [];
foreach ($arguments as $argument) {
    if (preg_match('/^--([a-z]+)=(.*)$/sD', (string) $argument, $match) !== 1 || !in_array($match[1], [...$required, ...$optional], true) || array_key_exists($match[1], $options)) {
        $refuse('unexpected or repeated option.');
    }
    $options[$match[1]] = trim($match[2]);
}
foreach ($required as $name) {
    if (($options[$name] ?? '') === '') {
        $refuse("--{$name} is required.");
    }
}
if (isset($options['id']) && preg_match('/^[a-z0-9][a-z0-9-]{0,59}$/D', $options['id']) !== 1) {
    $refuse('--id must be lowercase letters, digits and dashes (max 60).');
}
if (isset($options['kind']) && !in_array($options['kind'], $kinds, true)) {
    $refuse('--kind is not a known location kind.');
}
if (isset($options['label']) && (mb_strlen($options['label']) < 2 || mb_strlen($options['label']) > 160)) {
    $refuse('--label must be 2-160 characters.');
}

$container = require __DIR__ . '/cli-bootstrap.php';
$pdo = $container->pdo();
$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
try {
    if ($command === 'add') {
        $statement = $pdo->prepare("INSERT INTO production_locations (location_id, label, kind, stage_id, status, created_by_operator, created_at) VALUES (?, ?, ?, ?, 'active', ?, ?)");
        $statement->execute([$options['id'], $options['label'], $options['kind'], $options['stage'] ?? null, mb_substr($options['operator'], 0, 100), $now]);
    } elseif ($command === 'retire') {
        $statement = $pdo->prepare("UPDATE production_locations SET status = 'retired', retired_at = ? WHERE location_id = ? AND status = 'active'");
        $statement->execute([$now, $options['id']]);
        if ($statement->rowCount() !== 1) {
            fwrite(STDERR, "Refused: no active location with this id.\n");
            exit(2);
        }
    }
    $rows = $pdo->query('SELECT location_id, label, kind, stage_id, status FROM production_locations ORDER BY status, kind, location_id')->fetchAll(PDO::FETCH_ASSOC);
    fwrite(STDOUT, json_encode(['locations' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Failed: ' . get_class($error) . "\n");
    exit(1);
}
