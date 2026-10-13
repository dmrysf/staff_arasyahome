<?php

declare(strict_types=1);
namespace Arasya\Operations\Simulation;

use Arasya\Operations\Application\Container;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Integration\SourceSignatureVerifier;
use Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore;
use Arasya\Operations\Integration\Trendyol\TrendyolPackage;
use PDO;

/** Scenario controls never write stages, ownership, QR or document rows. */
final readonly class ScenarioService
{
    public function __construct(private Container $container) {}

    public function overview(): array
    {
        $pdo = $this->container->pdo();
        $env = $pdo->query('SELECT session_id, baseline_ms, created_at FROM simulation_environment')->fetch();
        $runs = $pdo->query('SELECT * FROM simulation_runs ORDER BY scenario_id')->fetchAll();
        foreach ($runs as &$run) {
            $run['evidence'] = $run['evidence_json'] === null ? [] : json_decode($run['evidence_json'],true,32,JSON_THROW_ON_ERROR);
            unset($run['evidence_json']);
        }
        $orders = $pdo->query('SELECT r.scenario_id, o.global_order_id, o.order_number, o.source_key, o.production_stage_id, o.production_version,
            o.production_owner_employee_uuid, o.open_exception_uuid, o.document_status, o.operational_status
            FROM simulation_runs r JOIN operational_orders o ON o.global_order_id = r.order_id OR (o.source_key = \'trendyol\' AND o.source_order_id = r.package_id) ORDER BY r.scenario_id')->fetchAll();
        foreach ($orders as &$order) {
            $q=$pdo->prepare('SELECT i.name,i.product_code,i.variant,i.width_value,i.height_value,i.measurement_unit,i.meters,i.quantity FROM operational_order_items i JOIN operational_orders o ON o.order_uuid=i.order_uuid WHERE o.global_order_id=? ORDER BY i.line_number');
            $q->execute([$order['global_order_id']]); $order['items']=$q->fetchAll();
            $q=$pdo->prepare('SELECT e.action,e.from_stage_id,e.to_stage_id,e.occurred_at,p.display_name AS actor FROM order_activity_events e LEFT JOIN employees p ON p.employee_uuid=e.employee_uuid WHERE e.global_order_id=? ORDER BY e.occurred_at LIMIT 100');
            $q->execute([$order['global_order_id']]); $order['history']=$q->fetchAll();
            $q=$pdo->prepare("SELECT q.qr_reference FROM order_qr_references q JOIN operational_orders o ON o.order_uuid=q.order_uuid WHERE o.global_order_id=? AND q.status='active'");
            $q->execute([$order['global_order_id']]); $reference=$q->fetchColumn();
            $order['qrSvg']=$reference===false?null:\Arasya\Operations\Production\ProductionQrService::svg('ARASYA:Q1:' . $reference);
        }
        $accounts=$pdo->query("SELECT e.username,e.display_name,e.status,e.staff_locale,s.stage_id FROM employees e LEFT JOIN employee_stage_access s ON s.employee_uuid=e.employee_uuid WHERE e.username LIKE 'sim.%' ORDER BY e.username,s.stage_id")->fetchAll();
        return ['environment'=>'SIMULARE / TEST','session'=>$env,'realTime'=>gmdate(DATE_ATOM),'clockMode'=>$this->container->config()->simulationClockMode,'simulationTime'=>$this->container->clock()->now()->format(DATE_ATOM),
            'fixtureTime'=>gmdate(DATE_ATOM,intdiv((int)$env['baseline_ms'],1000)),
            'apiVersion'=>\Arasya\Operations\Http\HealthController::VERSION,
            'scenarios'=>ScenarioCatalog::all(),'runs'=>$runs,'orders'=>$orders,'accounts'=>$accounts,
            'invariants'=>$this->invariants(),
            'limits'=>['Human acceptance pending','Validated line classification required; live metadata rollout pending','Setup checks do not verify an entire factory scenario']];
    }

    public function command(string $id, string $action, string $actor, int $expectedReplay): array
    {
        $pdo = $this->container->pdo();
        $lock=new \Arasya\Operations\Database\DatabaseAdvisoryLock($pdo,'sim_center_' . substr(hash('sha256',$this->container->config()->dbName),0,40));
        try { $lock->acquire(2); }
        catch (\RuntimeException $error) {
            if ($error->getMessage()==='The database maintenance lock is already held.') throw new ApiException(409,'SIMULATION_BUSY','Another simulation control is running.');
            throw $error;
        }
        try {
            $q=$pdo->prepare('SELECT * FROM simulation_runs WHERE scenario_id = ?'); $q->execute([$id]); $run=$q->fetch();
            if (!is_array($run)) throw new ApiException(404,'SIMULATION_SCENARIO_UNKNOWN','Scenario was not found.');
            if ((int)$run['replay_number'] !== $expectedReplay) throw new ApiException(409,'SIMULATION_CHANGED','Reload the current scenario.');
            if ($action === 'pause' || $action === 'resume') {
                if ($run['state'] === 'not_run') throw new ApiException(409,'SIMULATION_NOT_STARTED','Start the scenario first.');
                $state=$action === 'pause' ? 'paused' : 'ready';
                $pdo->prepare('UPDATE simulation_runs SET state=?, changed_at=UTC_TIMESTAMP(6) WHERE scenario_id=?')->execute([$state,$id]);
            } elseif ($action === 'start' || $action === 'reset') {
                if ($action === 'start' && $run['state'] !== 'not_run') throw new ApiException(409,'SIMULATION_ALREADY_STARTED','Use replay to create a fresh isolated fixture.');
                $replay=(int)$run['replay_number']+1;
                if ($replay > 9999) throw new ApiException(409,'SIMULATION_REPLAY_LIMIT','Create a new session.');
                if ($action === 'reset') $this->retire($id,$run);
                $this->start($id,$replay);
            } elseif ($action === 'fault') {
                if ($run['state'] !== 'ready') throw new ApiException(409,'SIMULATION_NOT_RUNNING','Resume or start the scenario first.');
                $this->fault($id,$run);
            } else throw new ApiException(400,'INVALID_REQUEST','Unknown simulation control.');
            $pdo->prepare('INSERT INTO simulation_control_events(scenario_id,replay_number,actor_uuid,action,created_at) SELECT scenario_id,replay_number,?,?,UTC_TIMESTAMP(6) FROM simulation_runs WHERE scenario_id=?')->execute([$actor,$action,$id]);
            return $this->overview();
        } finally {
$lock->release();
        }
    }


    /** Logical reset retains append-only history and withdraws only this scenario's old generation. */
    private function retire(string $id, array $run): void
    {
        $pdo=$this->container->pdo();
        $env=$pdo->query('SELECT session_id,baseline_ms FROM simulation_environment')->fetch();
        if (isset(ScenarioCatalog::WOO[$id]) && $run['order_id'] !== null) {
            $q=$pdo->prepare('SELECT * FROM operational_orders WHERE global_order_id=?'); $q->execute([$run['order_id']]); $order=$q->fetch();
            $q=$pdo->prepare('SELECT * FROM operational_order_items WHERE order_uuid=? ORDER BY line_number'); $q->execute([$order['order_uuid']]);
            $items=[];
            foreach ($q->fetchAll() as $row) { $items[]=['id'=>$row['source_item_id'],'line'=>(int)$row['line_number'],'name'=>$row['name'],'sku'=>$row['product_code'],'variant'=>$row['variant'],'color'=>$row['color'],
                'width'=>$row['width_value']===null?null:(float)$row['width_value'],'height'=>$row['height_value']===null?null:(float)$row['height_value'],'unit'=>$row['measurement_unit'],
                'meters'=>$row['meters']===null?null:(float)$row['meters'],'quantity'=>(int)$row['quantity']];
                $context=$row['production_context']===null?null:json_decode($row['production_context'],true,32,JSON_THROW_ON_ERROR);
                if (isset($context['manufacturingType'])) $items[array_key_last($items)]['manufacturingType']=$context['manufacturingType'];
                if (isset($context['options'])) $items[array_key_last($items)]['options']=$context['options'];
            }
            $at=(new \DateTimeImmutable($order['source_changed_at'],new \DateTimeZone('UTC')))->modify('+1 second');
            $payload=['schemaVersion'=>1,'eventId'=>'sim-retire-' . $env['session_id'] . '-' . $id . '-' . $run['replay_number'],'changedAt'=>$at->format('Y-m-d\TH:i:s.u\Z'),
                'order'=>['id'=>$order['source_order_id'],'number'=>$order['order_number'],'status'=>['code'=>'cancelled'],'availability'=>'cancelled','notes'=>$order['production_notes'],'items'=>$items]];
            if ($order['accepted_at']!==null) $payload['order']['acceptedAt']=(new \DateTimeImmutable($order['accepted_at'],new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            $r=$this->ingest(ScenarioCatalog::WOO[$id][0],$payload);
            if ($r['status']!==200) throw new ApiException(409,'SIMULATION_RESET_REFUSED','The old fixture could not be retired.');
        } elseif ($run['package_id'] !== null) {
            $file=$this->container->config()->trendyolFixtureFile;
            $fixture=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
            foreach ($fixture['packages']??[] as $raw) if ((string)$raw['shipmentPackageId']===$run['package_id']) {
                $raw['shipmentPackageStatus']='Cancelled'; $raw['lastModifiedDate']+=1000;
                (new TrendyolIntakeStore($pdo,$this->container->projectionWriter(),$this->container->clock()))->record(TrendyolPackage::fromApi($raw),(int)$env['baseline_ms']);
                $this->marketplace($raw);
            }
        }
    }

    private function start(string $id, int $replay): void
    {
        $pdo=$this->container->pdo();
        $env=$pdo->query('SELECT session_id,baseline_ms FROM simulation_environment')->fetch();
        $orderId=null; $packageId=null; $checks=[];
        if (isset(ScenarioCatalog::WOO[$id])) {
            $source=ScenarioCatalog::WOO[$id][0];
            $payload=ScenarioCatalog::woo($id,$env['session_id'],$replay,(int)$env['baseline_ms']);
            $r=$this->ingest($source,$payload);
            $this->check($r['status']===200 && ($r['body']['outcome']??'')==='applied','signed_ingestion',$checks);
            $orderId=$r['body']['globalOrderId']??null;
            $again=$this->ingest($source,$payload);
            $this->check(($again['body']['outcome']??'')==='duplicate','duplicate_ingress',$checks);
            $q=$pdo->prepare('SELECT i.* FROM operational_order_items i JOIN operational_orders o ON o.order_uuid=i.order_uuid WHERE o.global_order_id=? ORDER BY i.line_number'); $q->execute([$orderId]); $rows=$q->fetchAll();
            $this->check(count($rows)===count($payload['order']['items']),'line_count_preserved',$checks);
            foreach ($payload['order']['items'] as $index=>$item) {
                $row=$rows[$index]??[];
                $this->check((int)($row['quantity']??0)===$item['quantity'],'quantity_line_' . ($index+1),$checks);
                foreach (['width'=>'width_value','height'=>'height_value','meters'=>'meters'] as $field=>$column) {
                    $expected=$item[$field]??null; $actual=$row[$column]??null;
                    $this->check($expected===null ? $actual===null : $actual!==null && abs((float)$actual-$expected)<0.0001,$field . '_line_' . ($index+1),$checks);
                }
            }
        } else {
            $raw=ScenarioCatalog::trendyol($id,$env['session_id'],$replay,(int)$env['baseline_ms']);
            $packageId=(string)$raw['shipmentPackageId'];
            $store=new TrendyolIntakeStore($pdo,$this->container->projectionWriter(),$this->container->clock());
            $outcome=$store->record(TrendyolPackage::fromApi($raw),(int)$env['baseline_ms']);
            $this->check($outcome===($id==='TY-16'?'ignored':'received'),'canonical_intake',$checks);
            $this->check($store->record(TrendyolPackage::fromApi($raw),(int)$env['baseline_ms'])===($id==='TY-16'?'ignored':'unchanged'),'duplicate_package',$checks);
            $this->marketplace($raw);
            if ($id==='TY-15') {
                $split=$raw; $split['shipmentPackageId']+=50; $split['lines'][0]['lineId']+=50;
                $this->check($store->record(TrendyolPackage::fromApi($split),(int)$env['baseline_ms'])==='received','split_package_identity',$checks);
                $this->marketplace($split);
            }
        }
        $result=in_array(false,array_column($checks,'pass'),true)?'FAIL':'NOT VERIFIED';
        $evidence=['setup'=>$checks,'humanWorkflow'=>'NOT VERIFIED','note'=>$id==='WOO-OP-002'?'Business decision required: standard-only routing. No bypass added.':'Complete the real Staff workflow using the assigned test accounts.'];
        $pdo->prepare('UPDATE simulation_runs SET replay_number=?,state=?,result=?,order_id=?,package_id=?,evidence_json=?,changed_at=UTC_TIMESTAMP(6) WHERE scenario_id=?')
            ->execute([$replay,'ready',$result,$orderId,$packageId,json_encode($evidence,JSON_THROW_ON_ERROR),$id]);
    }

    private function fault(string $id, array $run): void
    {
        $config=$this->container->config();
        $env=$this->container->pdo()->query('SELECT session_id,baseline_ms FROM simulation_environment')->fetch();
        $checks=[];
        if (isset(ScenarioCatalog::WOO[$id])) {
            // Same event ID, changed quantity: must be rejected, keeping canonical content.
            $payload=ScenarioCatalog::woo($id,$env['session_id'],(int)$run['replay_number'],(int)$env['baseline_ms']);
            $payload['order']['items'][0]['quantity']++;
            $r=$this->ingest(ScenarioCatalog::WOO[$id][0],$payload);
            $this->check($r['status']===409 && ($r['body']['error']['code']??'')==='SOURCE_EVENT_CONFLICT','event_conflict_rejected',$checks);
        } elseif ($id==='TY-12') {
            $fixture=json_decode((string)file_get_contents($config->trendyolFixtureFile),true,32,JSON_THROW_ON_ERROR);
            $fixture['faults'][$run['package_id']]=['status'=>429];
            $this->writeFixture($fixture);
            $checks[]=['name'=>'fixture_429_selected','pass'=>true];
        } else {
            $raw=ScenarioCatalog::trendyol($id,$env['session_id'],(int)$run['replay_number'],(int)$env['baseline_ms']);
            $raw['lastModifiedDate']+=1000;
            if (in_array($id,['TY-09','TY-10'],true)) $raw['shipmentPackageStatus']='Cancelled';
            else $raw['lines'][0]['quantity']++;
            // Change only the fixture API; release must discover the fresh change itself.
            $this->marketplace($raw);
            $checks[]=['name'=>'marketplace_change_selected','pass'=>true];
        }
        $evidence=json_decode($run['evidence_json']??'{}',true,32,JSON_THROW_ON_ERROR);
        $evidence['fault']=$checks;
        $evidence['faultStaffResult']='NOT VERIFIED';
        $this->container->pdo()->prepare('UPDATE simulation_runs SET result=?,evidence_json=?,changed_at=UTC_TIMESTAMP(6) WHERE scenario_id=?')
            ->execute([in_array(false,array_column($checks,'pass'),true)?'FAIL':'NOT VERIFIED',json_encode($evidence,JSON_THROW_ON_ERROR),$id]);
    }

    public function ingest(string $source, array $payload): array
    {
        $body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $timestamp=(string)$this->container->clock()->now()->getTimestamp();
        $r=$this->container->kernel()->handle(new Request('POST','/integrations/sources/' . $source . '/orders',
            ['x-arasya-timestamp'=>$timestamp,'x-arasya-signature'=>SourceSignatureVerifier::sign($this->container->config()->sourceSecrets[$source],$timestamp,$body),'content-type'=>'application/json'],[], $body,'127.0.0.1','simulation-center','sim-' . bin2hex(random_bytes(8))));
        return ['status'=>$r->status,'body'=>$r->payload];
    }

    private function marketplace(array $raw): void
    {
        $file=$this->container->config()->trendyolFixtureFile;
        $fixture=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        $packages=[];
        foreach ($fixture['packages']??[] as $package) $packages[(string)$package['shipmentPackageId']]=$package;
        $packages[(string)$raw['shipmentPackageId']]=$raw;
        $faults=$fixture['faults']??[];
        unset($faults[(string)$raw['shipmentPackageId']]);
        $this->writeFixture(['packages'=>array_values($packages),'faults'=>$faults]);
    }

    private function writeFixture(array $fixture): void
    {
        $file=$this->container->config()->trendyolFixtureFile;
        $temporary=$file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary,json_encode($fixture,JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new \RuntimeException('SIMULATION_FIXTURE_WRITE_FAILED');
        chmod($temporary,0600);
        if (!rename($temporary,$file)) throw new \RuntimeException('SIMULATION_FIXTURE_WRITE_FAILED');
    }

    private function check(bool $pass,string $name,array &$checks): void { $checks[]=['name'=>$name,'pass'=>$pass]; }

    public function invariants(): array
    {
        $pdo=$this->container->pdo();
        $queries=[
            'one_canonical_order'=>'SELECT COUNT(*) FROM (SELECT global_order_id FROM operational_orders GROUP BY global_order_id HAVING COUNT(*)>1) x',
            'one_active_qr'=>'SELECT COUNT(*) FROM (SELECT order_uuid FROM order_qr_references WHERE status=\'active\' GROUP BY order_uuid HAVING COUNT(*)>1) x',
            'one_active_document'=>'SELECT COUNT(*) FROM (SELECT order_uuid FROM production_document_revisions WHERE status=\'active\' GROUP BY order_uuid HAVING COUNT(*)>1) x',
            'canonical_stage'=>'SELECT COUNT(*) FROM operational_orders o LEFT JOIN production_stages s ON s.stage_id=o.production_stage_id WHERE s.stage_id IS NULL',
            'valid_source'=>'SELECT COUNT(*) FROM operational_orders WHERE source_key NOT IN (\'trendhome\',\'outletperdele\',\'trendyol\')',
        ];
        $results=[];
        foreach ($queries as $key=>$sql) $results[$key]=(int)$pdo->query($sql)->fetchColumn()===0;
        return $results;
    }
}
