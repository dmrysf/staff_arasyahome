<?php
declare(strict_types=1);
use Arasya\Operations\Application\Container;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Database\MigrationRunner;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Analytics\AnalyticsCapture;
use Arasya\Operations\Analytics\AnalyticsPolicy;
use Arasya\Operations\Analytics\AnalyticsService;
use Arasya\Operations\Iam\RootBootstrapService;
use Arasya\Operations\Support\Uuid;
use Arasya\Operations\Tests\OperationsTestSupport as T;
require dirname(__DIR__).'/bootstrap.php';
require __DIR__.'/OperationsTestSupport.php';
$db=T::requireTestDatabase();
if ($db===null) { echo "SKIP analytics performance: test database not configured\n"; exit; }
// Destructive fixture setup is constrained to this exact disposable performance database name.
if ($db!=='arasya_analytics_performance_test') throw new RuntimeException('Dedicated analytics performance test database required');
$config=T::config($db);
$server=new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4',$config->dbHost,$config->dbPort),$config->dbUser,$config->dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$server->exec('DROP DATABASE IF EXISTS arasya_analytics_performance_test');
$server->exec('CREATE DATABASE arasya_analytics_performance_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo=Connection::create($config); (new MigrationRunner($pdo))->migrate(dirname(__DIR__).'/database/migrations');
foreach (glob(dirname(__DIR__).'/database/seeds/*.sql')?:[] as $file) (new SqlFileRunner($pdo))->run($file);
$c=new Container($config,$pdo); $kernel=$c->kernel();
$temporary=(new RootBootstrapService($pdo,$c->passwordHasher(),$c->clock()))->bootstrap('analytics-perf','analytics.perf.root');
$root=T::login($kernel,'analytics.perf.root',$temporary); $password='analytics performance fixture only 2026';
$change=T::call($kernel,'POST','/auth/password',['currentPassword'=>$temporary,'newPassword'=>$password],['x-csrf-token'=>$root['csrf']],$root['cookie']);
if ($change['status']!==200) throw new RuntimeException('Test Root setup failed');
$actor=$c->employeeRepository()->findByNormalizedUsername('analytics.perf.root');
$stages=$pdo->query('SELECT stage_id,display_name FROM production_stages ORDER BY ordinal')->fetchAll(PDO::FETCH_ASSOC);
$worker=$c->employeeAdmin()->create('Fixture istoric sintetic','analytics.perf.worker',null,'pregatire-material','employee',$password,array_column($stages,'stage_id'),'analytics-perf');
$result=T::ingest($kernel,'trendhome',T::sourceOrder('analytics-perf-template','analytics-perf-template-event',gmdate('Y-m-d\TH:i:s\Z',time()-60),T::stage('waiting')));
if ($result['status']!==200) throw new RuntimeException('Test template setup failed');
$template=$pdo->query("SELECT * FROM operational_orders WHERE source_order_id='analytics-perf-template'")->fetch(PDO::FETCH_ASSOC);
$items=$pdo->query("SELECT * FROM operational_order_items WHERE order_uuid=".$pdo->quote($template['order_uuid']))->fetchAll(PDO::FETCH_ASSOC);
$columns=array_keys($template);
$insertOrder=$pdo->prepare('INSERT INTO operational_orders (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')');
$itemColumns=array_keys($items[0]);
$insertItem=$pdo->prepare('INSERT INTO operational_order_items (`'.implode('`,`',$itemColumns).'`) VALUES ('.implode(',',array_fill(0,count($itemColumns),'?')).')');
$event=$pdo->prepare('INSERT INTO order_activity_events (event_id,employee_uuid,order_uuid,global_order_id,source_key,order_number_snapshot,action,workflow_key,workflow_version,from_stage_id,from_stage_label_snapshot,to_stage_id,to_stage_label_snapshot,production_version_before,production_version_after,meters_snapshot,request_id,idempotency_key,occurred_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$qr=$pdo->prepare("INSERT INTO order_qr_references (qr_reference,order_uuid,status,created_at) VALUES (?,?,'active',?)");
$fact=$pdo->prepare("INSERT INTO cutting_facts (fact_uuid,order_uuid,production_version,fact_type,employee_uuid,occurred_at,meters,item_count_snapshot) VALUES (?,?,5,'completed',?,?,8.400,?)");
$poolFact=$pdo->prepare("INSERT INTO cutting_facts (fact_uuid,order_uuid,production_version,fact_type,occurred_at) VALUES (?,?,3,'pool_entered',?)");
$capture=new AnalyticsCapture($pdo); $now=new DateTimeImmutable('now',new DateTimeZone('UTC')); $last='';
$orderCount=(int)(getenv('ARASYA_ANALYTICS_PERF_ORDERS')?:12000);
if ($orderCount<1000 || $orderCount>20000) throw new RuntimeException('Performance fixture size must be 1000..20000');
$seedStart=microtime(true);
for ($n=0;$n<$orderCount;$n++) {
    if ($n%100===0) $pdo->beginTransaction();
    $at=$now->modify('-'.($n%1095).' days')->setTime(6,0); $id=Uuid::v4(); $last=$id; $sourceId='analytics-perf-'.$n; $global='trendhome:'.$sourceId;
    $row=$template;
    $row['order_uuid']=$id; $row['global_order_id']=$global; $row['source_order_id']=$sourceId; $row['order_number']=$sourceId;
    $row['production_stage_id']='delivery'; $row['production_version']=29; $row['version']=29; $row['production_authority']='operations';
    $row['production_owner_employee_uuid']=null; $row['production_claimed_at']=null;
    $row['production_completed_at']=$at->modify('+27 minutes')->format('Y-m-d H:i:s'); $row['production_changed_at']=$row['production_completed_at'];
    $row['cutting_first_claimed_at']=$at->modify('+2 minutes')->format('Y-m-d H:i:s');
    foreach (['created_at','source_changed_at','last_source_seen_at','projected_at','updated_at','accepted_at'] as $column) $row[$column]=$at->format('Y-m-d H:i:s');
    $row['source_event_id']='analytics-perf-event-'.$n; $row['projection_hash']=hash('sha256',$global,true);
    $insertOrder->execute(array_values($row));
    foreach ($items as $item) { $item['order_uuid']=$id; $item['item_uuid']=Uuid::v4(); $insertItem->execute(array_values($item)); }
    $qr->execute([\Arasya\Operations\Order\QrReference::generate()->value,$id,$at->format('Y-m-d H:i:s')]);
    $version=2;
    foreach ($stages as $ordinal=>$stage) {
        $start=$at->modify('+'.($ordinal*2).' minutes')->format('Y-m-d H:i:s');
        $end=$at->modify('+'.($ordinal*2+1).' minutes')->format('Y-m-d H:i:s'); $next=$stages[$ordinal+1]??null;
        foreach ([['claimed',$start,null],[$next===null?'production_completed':'stage_completed',$end,$next]] as [$action,$instant,$target]) {
            $event->execute([Uuid::v4(),$worker->employeeUuid,$id,$global,'trendhome',$sourceId,$action,'curtain-production',1,$stage['stage_id'],$stage['display_name'],$target['stage_id']??null,$target['display_name']??null,$version-1,$version,'8.400','analytics-perf','analytics-perf-'.$n.'-'.$version,$instant]); $version++;
        }
    }
    $fact->execute([Uuid::v4(),$id,$worker->employeeUuid,$at->modify('+3 minutes')->format('Y-m-d H:i:s'),count($items)]);
    $poolFact->execute([Uuid::v4(),$id,$at->modify('+1 minute')->format('Y-m-d H:i:s')]);
    $capture->refreshOrder($id);
    if ($n%100===99 || $n===$orderCount-1) $pdo->commit();
}
echo 'Synthetic history: '.$orderCount.' orders / '.($orderCount*28).' canonical activity events over 3 years; seed '.round(microtime(true)-$seedStart,2)."s\n";
$planIds=$pdo->query('SELECT order_uuid FROM analytics_order_projection ORDER BY order_uuid LIMIT 400')->fetchAll(PDO::FETCH_COLUMN);
$planMarks=implode(',',array_fill(0,count($planIds),'?'));
$plans=[
    ['batched work evidence',"SELECT order_uuid,MIN(occurred_at) FROM order_activity_events WHERE order_uuid IN ({$planMarks}) AND action IN ('claimed','stage_completed','production_completed') GROUP BY order_uuid",$planIds],
    ['period activity',"SELECT employee_uuid,order_uuid,from_stage_id,action FROM order_activity_events WHERE action IN ('stage_completed','production_completed','production_submitted') AND occurred_at>=? AND occurred_at<?",[$now->modify('-6 months')->format('Y-m-d H:i:s'),$now->modify('+1 day')->format('Y-m-d H:i:s')]],
];
foreach($plans as [$label,$sql,$parameters]) {
    $explain=$pdo->prepare('EXPLAIN '.$sql); $explain->execute($parameters);
    foreach($explain->fetchAll(PDO::FETCH_ASSOC) as $row) if(!str_contains((string)($row['Extra']??''),'Using index')) throw new RuntimeException('Canonical '.$label.' must use a covering index');
    echo 'PASS covering index: '.$label."\n";
}
$service=new AnalyticsService($pdo,new AnalyticsPolicy($pdo),$c->clock());
$measure=static function(string $label,string $section,array $query,?string $id=null) use($pdo,$service,$actor):array {
    $before=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1]; $start=microtime(true);
    $response=$service->report($actor,$section,$query,$id); $elapsed=microtime(true)-$start;
    $questions=(int)$pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1]-$before-1;
    echo $label.': '.round($elapsed,3).'s / '.$questions." SQL statements\n";
    if ($questions>250 || $elapsed>15) throw new RuntimeException('Unbounded/slow analytics query: '.$label);
    return $response;
};
$measure('Current month employee summary','employees',['period'=>'month','limit'=>'50']);
$six=['from'=>$now->modify('-6 months')->format('Y-m-d'),'to'=>$now->format('Y-m-d'),'limit'=>'50'];
$measure('Six months employee summary','employees',$six);
$sources=$measure('Six months source lifecycle','sources',$six);
if ($sources['items'][0]['cuttingWaiting']['wallSeconds']['sampleCount']<1000 || $sources['items'][0]['cuttingWaiting']['wallSeconds']['median']!==60) throw new RuntimeException('Canonical cutting pool cohorts require real bounded wait samples');
$detail=$measure('Employee detail paginated','employee',$six,$worker->employeeUuid);
if (count($detail['orders']['items'])!==50 || $detail['orders']['nextCursor']===null) throw new RuntimeException('Employee detail must be paginated');
$order=$measure('Order timeline paginated','order',['limit'=>'5'],$last);
if (count($order['timeline'])>5 || $order['nextVersion']===null) throw new RuntimeException('Order timeline must be paginated');
echo 'PASS analytics performance: peak memory '.round(memory_get_peak_usage(true)/1048576,1)." MiB; no per-row report queries\n";
