<?php
use Arasya\Operations\Application\Container;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Simulation\ScenarioCatalog;
// Included only by the CLI isolated organization verifier with real root/CEO/worker sessions.
$appointment=static function(?string $ends=null)use($call,$ok,$sessions):array {return $ok($call('ceo','POST','/management/organization/responsibilities',['responsibility'=>'tailoring_quality_supervisor','employeeId'=>$sessions['tailoring-supervisor']['employeeUuid'],'endsAt'=>$ends]),'CEO-authorized-supervisor-appointment',201);};
$future=static function(string $who,string $path,array $body,int $seconds=120)use($container,$pdo,$sessions):array {
    $clock=new class($seconds) implements \Arasya\Operations\Support\Clock {
        public function __construct(private int $seconds) {}
        public function now(): \DateTimeImmutable {return (new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->modify('+' . $this->seconds . ' seconds');}
    };
    $test=new Container($container->config(),$pdo,$clock);$s=$sessions[$who];$r=$test->kernel()->handle(new Request('POST',$path,['origin'=>explode(',',(string)getenv('ARASYA_ALLOWED_ORIGINS'))[0],'content-type'=>'application/json','x-csrf-token'=>$s['csrf'],'idempotency-key'=>'future-' . bin2hex(random_bytes(10))],[$test->config()->cookieName()=>$s['cookie']],json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1','simulation-expiry','simulation-expiry'));
    return ['status'=>$r->status,'body'=>$r->payload];
};
$role=(int)$one("SELECT role_id FROM roles WHERE role_key='operations-manager'");
foreach(['WOO-TH-001','WOO-OP-001','TY-01'] as $scenario) {
    $id=$fixture($scenario);$bring($id,'material-straightening');$o=$getOrder('material-straightening',$id);$reportInput=['expectedVersion'=>$version($id),'itemIds'=>[$o['products'][0]['id']],'reasonKey'=>'cut-meterage','comment'=>'TEST negatives'];$key='negative-report-' . substr(hash('sha256',$id),0,24);
    $r=$call('material-straightening','POST','/orders/' . rawurlencode($id) . '/fault-reports',$reportInput,$key);$x=$ok($r,'negative-report',201)['id'];
    $repeat=$call('material-straightening','POST','/orders/' . rawurlencode($id) . '/fault-reports',$reportInput,$key);$check($repeat['status']===201 && $repeat['body']===$r['body'],'duplicate-defect-replay','Same key creates one defect and returns the committed response');
    $dup=$call('material-straightening','POST','/orders/' . rawurlencode($id) . '/fault-reports',array_replace($reportInput,['expectedVersion'=>$version($id)]));$check($dup['status']===409 && $dup['body']['error']['code']==='EXCEPTION_ALREADY_OPEN','duplicate-defect-other-key-denied','Second request cannot create another open defect');
    $ends=gmdate('Y-m-d\TH:i:s\Z',time()+60);$appointment($ends);
    $r=$future('tailoring-supervisor','/production-exceptions/' . $x . '/review',['expectedVersion'=>$exVersion($x),'decision'=>'accept','reasonKey'=>'cut-meterage','qrToken'=>$qr($id)]);$check($r['status']===403 && $r['body']['error']['code']==='SUPERVISOR_REQUIRED','appointment-expired','Injected Clock expires the actual policy window; no stage changes');
    $assigned=$appointment();$assignment=array_values(array_filter($assigned['responsibilities'],static fn($a)=>$a['responsibility']==='tailoring_quality_supervisor'&&$a['state']==='active'))[0]['id'];
    $ok($call('ceo','POST','/management/organization/responsibilities/' . $assignment . '/revoke',['reason'=>'TEST revoked appointment']),'CEO-revokes-supervisor');
    $r=$ex('tailoring-supervisor',$x,'review',['decision'=>'accept','reasonKey'=>'cut-meterage','qrToken'=>$qr($id)]);$check($r['status']===403,'appointment-revoked','Revoked appointment never accepts custody');$appointment();
    $ok($ex('material-straightening',$x,'notes',['comment'=>'TEST new version before stale review']),'exception-new-version');
    $stale=$call('tailoring-supervisor','POST','/production-exceptions/' . $x . '/review',['expectedVersion'=>$exVersion($x)-1,'decision'=>'accept','reasonKey'=>'cut-meterage','qrToken'=>$qr($id)]);
    $check($stale['status']===409,'stale-supervisor-version','Stale exception version cannot take custody');
    $accept($x,$id);$receiveCut($x,$id);
    // Give the reporter genuine manager rights to test separation of duties rather than merely a missing role.
    $actor=$sessions['material-straightening']['employeeUuid'];
    $ok($call('root','PUT','/management/employees/' . $actor . '/applications',['applications'=>['staff','dashboard']]),'reporter-manager-app-fixture');
    $ok($call('root','PUT','/management/employees/' . $actor . '/roles',['roleIds'=>[$role]]),'reporter-manager-role-fixture');
    $r=$ex('material-straightening',$x,'decision',['decision'=>'approve','attribution'=>'cutter','comment'=>'TEST self approval']);$check($r['status']===403 && $r['body']['error']['code']==='SELF_DECISION_DENIED','reporter-manager-self-approval-denied','An involved worker remains denied with real manager permissions');
    $ok($call('root','PUT','/management/employees/' . $actor . '/roles',['roleIds'=>[]]),'reporter-manager-role-removed');$ok($call('root','PUT','/management/employees/' . $actor . '/applications',['applications'=>['staff']]),'reporter-dashboard-removed');
    $manager=$sessions['manager']['employeeUuid'];$ok($call('root','PUT','/management/employees/' . $manager . '/roles',['roleIds'=>[]]),'manager-role-revoked');
    $r=$ex('manager',$x,'decision',['decision'=>'approve','attribution'=>'cutter','comment'=>'TEST revoked rights']);$check($r['status']===403,'manager-permission-revoked','Previously authenticated manager cannot decide after revocation');
    $ok($call('root','PUT','/management/employees/' . $manager . '/roles',['roleIds'=>[$role]]),'manager-permission-restored');
    $ok($ex('manager',$x,'decision',['decision'=>'approve','attribution'=>'cutter','comment'=>'TEST recovered rights']),'manager-recovery-approval');$finish($id,$scenario . '-permission-recovery');
}
// The responsible cutter is unavailable: choose and record an eligible substitute; no assignment bypass.
$id=$fixture('WOO-OP-001');$bring($id,'material-straightening');$x=$reportDefect($id,'material-straightening')['id'];$accept($x,$id);
$cutter=$sessions['material-preparation']['employeeUuid'];$ok($call('root','POST','/management/employees/' . $cutter . '/deactivate',null),'cutter-unavailable-fixture');
$r=$ex('tailoring-supervisor',$x,'return',[]);$check($r['status']===409 && $r['body']['error']['code']==='RESPONSIBLE_UNAVAILABLE','responsible-unavailable','Supervisor must select an eligible substitute');
$ok($ex('tailoring-supervisor',$x,'return',['receiverEmployeeId'=>$sessions['cutting-second']['employeeUuid'],'comment'=>'TEST substitute cutter']),'eligible-substitute-selected');
$ok($ex('cutting-second',$x,'acknowledge',['confirmed'=>true,'qrToken'=>$qr($id)]),'substitute-physical-receipt');
$ok($ex('manager',$x,'decision',['decision'=>'approve','attribution'=>'none','comment'=>'TEST approved unassigned attribution']),'substitute-independent-approval');
$finish($id,'substitute-rework');$ok($call('root','POST','/management/employees/' . $cutter . '/activate',null),'cutter-reactivated');
$a=$manifest['accounts']['material-preparation'];$r=$call('','POST','/auth/login',['username'=>$a['username'],'password'=>$a['password']]);$ok($r,'cutter-authenticates-again');preg_match('/^arasya_sim_session=([^;]+)/',$r['headers']['Set-Cookie'],$m);$sessions['material-preparation']=['cookie'=>rawurldecode($m[1]),'csrf'=>$r['body']['csrfToken'],'employeeUuid'=>$cutter];
// Real document change while a defect is open: stale paper blocks review; approved replacement rotates QR.
$id=$fixture('WOO-TH-001');$bring($id,'material-straightening');$x=$reportDefect($id,'material-straightening')['id'];$doc='/production-documents/orders/' . rawurlencode($id);
$ok($call('document-operator','POST',$doc . '/generate',['expectedDocumentVersion'=>0]),'negative-initial-ticket',201);$oldQr=$qr($id);
$q=$pdo->prepare('SELECT * FROM operational_order_items WHERE order_uuid=? ORDER BY line_number');$q->execute([$state($id)['order_uuid']]);$items=[];
foreach($q->fetchAll() as $i) {$context=json_decode($i['production_context'],true,32,JSON_THROW_ON_ERROR);$items[]=['id'=>$i['source_item_id'],'line'=>(int)$i['line_number'],'name'=>$i['name'],'sku'=>$i['product_code'],'variant'=>$i['variant'],'width'=>(float)$i['width_value']+0.1,'height'=>(float)$i['height_value'],'unit'=>$i['measurement_unit'],'meters'=>$i['meters']===null?null:(float)$i['meters'],'quantity'=>(int)$i['quantity'],'manufacturingType'=>$context['manufacturingType']];}
$row=$state($id);$payload=['schemaVersion'=>1,'eventId'=>'negative-doc-' . $manifest['sessionId'],'changedAt'=>(new DateTimeImmutable($row['source_changed_at'],new DateTimeZone('UTC')))->modify('+1 second')->format('Y-m-d\TH:i:s.u\Z'),'order'=>['id'=>$row['source_order_id'],'number'=>$row['order_number'],'availability'=>'active','items'=>$items]];
$ok($service->ingest('trendhome',$payload),'changed-source-during-defect');$check($state($id)['document_status']==='stale','open-defect-stale-ticket','Document becomes stale through the actual projection service');
$r=$ex('tailoring-supervisor',$x,'review',['decision'=>'accept','reasonKey'=>'cut-meterage','qrToken'=>$oldQr]);$check($r['status']===409 && $r['body']['error']['code']==='ORDER_BLOCKED_BY_DOCUMENT','stale-paper-refuses-custody','Changed source cannot be reviewed using stale paper');
$request=$ok($call('document-operator','POST',$doc . '/revision-requests',['expectedDocumentVersion'=>(int)$state($id)['document_version']]),'negative-revision-request',201)['request'];
$ok($call('document-backup','POST','/production-documents/revision-requests/' . $request['id'] . '/decision',['expectedVersion'=>1,'decision'=>'approve']),'negative-independent-document-backup-approval');
$ok($call('document-operator','POST',$doc . '/generate',['expectedDocumentVersion'=>(int)$state($id)['document_version'],'requestId'=>$request['id']]),'negative-new-ticket',201);
$r=$ex('tailoring-supervisor',$x,'review',['decision'=>'accept','reasonKey'=>'cut-meterage','qrToken'=>$oldQr]);$check(in_array($r['status'],[409,410,422],true) && in_array($r['body']['error']['code'],['DOCUMENT_SUPERSEDED','QR_SUPERSEDED','INVALID_QR'],true),'old-qr-refuses-review','Old revision QR does not approve custody');
$accept($x,$id);$receiveCut($x,$id);$ok($ex('manager',$x,'decision',['decision'=>'approve','attribution'=>'cutter','comment'=>'TEST changed-source rework']),'changed-source-manager-approval');$finish($id,'changed-source-rework');
