<?php
// Read-only helper for browser QR entry. Never claims, progresses, approves or impersonates an employee.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
$m=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);foreach($m['environment'] as $k=>$v) putenv($k . '=' . $v);
$c=new Arasya\Operations\Application\Container();$q=$c->pdo()->query("SELECT r.scenario_id,o.global_order_id,o.order_number,q.qr_reference FROM simulation_runs r JOIN operational_orders o ON o.global_order_id=r.order_id JOIN order_qr_references q ON q.order_uuid=o.order_uuid AND q.status='active'");
$result=[];foreach($q->fetchAll() as $r) $result[$r['scenario_id']]=['id'=>$r['global_order_id'],'number'=>$r['order_number'],'qr'=>'ARASYA:Q1:' . $r['qr_reference']];echo json_encode($result,JSON_THROW_ON_ERROR);
