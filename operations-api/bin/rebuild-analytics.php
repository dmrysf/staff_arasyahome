<?php
declare(strict_types=1);
/** Official bounded rebuild: derived tables only. No eligibility backfill or canonical event changes. */
$container=require __DIR__.'/cli-bootstrap.php';
try {
    $arguments=array_slice($argv,1); $batch=50;
    if ($arguments) {
        if (count($arguments)!==1 || !preg_match('/^--batch=([0-9]{1,3})$/D',$arguments[0],$match)) throw new InvalidArgumentException('Use --batch=1..500');
        $batch=(int)$match[1];
    }
    $count=(new Arasya\Operations\Analytics\AnalyticsCapture($container->pdo()))->rebuild($batch);
    fwrite(STDOUT,"Analytics projection rebuilt: {$count} orders. Canonical history unchanged.\n");
} catch (Throwable $error) {
    fwrite(STDERR,'Analytics rebuild failed ('.get_class($error)."). No secrets are printed.\n");
    exit(1);
}
