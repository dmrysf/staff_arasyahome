<?php
// Hosted three-domain boundary checks against the REAL API kernel and a provisioned simulation database:
// the Staff and Dashboard simulation origins work with host-only credentialed cookies, CSRF and IAM roles, every other
// origin is refused, and the hosted private manifest (schema 2) fails closed. Prints codes and results only.
// Usage: php simulation/hosted-api-test.php /abs/arasya-simulation/sessions/<id>/private.json /abs/report-dir
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\ConfigLoader;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Simulation\HostedManifest;

if (PHP_SAPI!=='cli' || $argc!==3) throw new RuntimeException('Usage: php simulation/hosted-api-test.php /private/manifest.json /report-dir');
umask(077);
$local=json_decode((string)file_get_contents($argv[1]),true,32,JSON_THROW_ON_ERROR);
$report=$argv[2]; if (!is_dir($report)) mkdir($report,0700,true);
$results=[]; $passed=0;
$check=static function(string $id,bool $ok,string $area) use(&$results,&$passed): void {
    $results[]=['id'=>$id,'area'=>$area,'result'=>$ok?'PASS':'FAIL'];
    if (!$ok) throw new RuntimeException('FAIL ' . $id);
    $passed++; echo "PASS {$area} {$id}\n";
};
$staff='https://sim.arasyahome.ro'; $dashboard='https://dashboard.sim.arasyahome.ro';

// ---------- Part A: hosted manifest creation and validation (no database) ----------
$work=sys_get_temp_dir() . '/arasya-hosted-manifest-' . bin2hex(random_bytes(4));
$sessions=$work . '/arasya-simulation/sessions'; mkdir($sessions,0700,true); chmod($work . '/arasya-simulation',0700);
$id='0123456789ab'; $dir=$sessions . '/' . $id; $password='hosted-test-' . bin2hex(random_bytes(8));
$create=static function(array $args,string $stdin) : array {
    $process=proc_open([PHP_BINARY,dirname(__DIR__) . '/simulation/create-cpanel-manifest.php',...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],$stdin); fclose($pipes[0]); $out=stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process),$out];
};
$verify=static function(string $file): array {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/simulation/verify-hosted-manifest.php') . ' ' . escapeshellarg($file) . ' 2>&1',$lines,$code);
    return [$code,implode("\n",$lines)];
};
[$code,$out]=$create([$dir,$id,'arasya_sim_test_' . $id,'sim_' . $id],$password);
$check('manifest_created_with_password_on_stdin',$code===0 && trim($out)==='SIMULATION_PRIVATE_MANIFEST_CREATED' && !str_contains($out,$password),'manifest');
$file=$dir . '/private.json';
$check('manifest_and_folder_are_private',(fileperms($file)&0777)===0600 && (fileperms($dir)&0777)===0700 && (fileperms($dir . '/marketplace.json')&0777)===0600,'manifest');
$created=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
$check('manifest_names_three_exact_origins',$created['origins']===['staff'=>$staff,'dashboard'=>$dashboard,'api'=>'https://api.sim.arasyahome.ro'] && $created['environment']['ARASYA_ALLOWED_ORIGINS']===$staff . ',' . $dashboard,'manifest');
$check('manifest_secrets_fresh_and_simulation_only',preg_match('/^sim:[a-f0-9]{64}$/D',$created['environment']['ARASYA_APP_SECRET'])===1 && $created['environment']['ARASYA_SOURCE_SECRET_TRENDHOME']!==$created['environment']['ARASYA_SOURCE_SECRET_OUTLETPERDELE'],'manifest');
[$code,$out]=$verify($file);
$check('hosted_manifest_verifies',$code===0 && trim($out)==='SIMULATION_HOSTED_MANIFEST_OK','manifest');
foreach ([
    'refuse_existing_folder'=>[[$dir,$id,'arasya_sim_test_' . $id,'sim_' . $id],$password],
    'refuse_folder_not_named_after_session'=>[[$sessions . '/other',$id,'arasya_sim_test_' . $id,'sim_' . $id],$password],
    'refuse_production_database'=>[[$sessions . '/aaaaaaaaaaaa','aaaaaaaaaaaa','arasya_operations','sim_aaaaaaaaaaaa'],$password],
    'refuse_other_session_user'=>[[$sessions . '/bbbbbbbbbbbb','bbbbbbbbbbbb','arasya_sim_test_bbbbbbbbbbbb','sim_cccccccccccc'],$password],
    'refuse_missing_password'=>[[$sessions . '/dddddddddddd','dddddddddddd','arasya_sim_test_dddddddddddd','sim_dddddddddddd'],''],
    'refuse_folder_outside_private_sessions'=>[[$work . '/eeeeeeeeeeee','eeeeeeeeeeee','arasya_sim_test_eeeeeeeeeeee','sim_eeeeeeeeeeee'],$password],
] as $name=>[$args,$stdin]) { [$code,$out]=$create($args,$stdin); $check($name,$code!==0 && !str_contains($out,$password),'manifest'); }
$tamper=static function(string $name,callable $change) use($file,$verify,$check,$password): void {
    $copy=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR); $change($copy);
    $target=dirname($file) . '/tampered.json'; file_put_contents($target,json_encode($copy,JSON_UNESCAPED_SLASHES)); chmod($target,0600);
    [$code,$out]=$verify($target); unlink($target);
    $check($name,$code!==0 && preg_match('/^SIMULATION_[A-Z_]+$/D',trim($out))===1 && !str_contains($out,$password),'manifest');
};
$tamper('reject_production_dashboard_origin',static function(array &$m): void { $m['environment']['ARASYA_ALLOWED_ORIGINS']='https://sim.arasyahome.ro,https://dashboard.arasyahome.ro'; });
$tamper('reject_staff_only_origin_list',static function(array &$m): void { $m['environment']['ARASYA_ALLOWED_ORIGINS']='https://sim.arasyahome.ro'; });
$tamper('reject_extra_origin',static function(array &$m): void { $m['environment']['ARASYA_ALLOWED_ORIGINS'].=',https://evil.example'; });
$tamper('reject_production_api_origin',static function(array &$m): void { $m['origins']['api']='https://api.arasyahome.ro'; });
$tamper('reject_schema_downgrade',static function(array &$m): void { unset($m['schemaVersion']); });
$tamper('reject_real_trendyol_credentials',static function(array &$m): void { $m['environment']['ARASYA_TRENDYOL_API_KEY']='real'; });
$tamper('reject_proxy_trust',static function(array &$m): void { $m['environment']['ARASYA_TRUST_PROXY']='true'; });
$tamper('reject_private_configuration_file',static function(array &$m): void { $m['environment']['ARASYA_CONFIG_FILE']='/home/x/arasya-config/secrets.json'; });
$tamper('reject_production_database',static function(array &$m): void { $m['environment']['ARASYA_DB_NAME']='arasya_operations'; $m['database']['name']='arasya_operations'; });
$tamper('reject_remote_database_host',static function(array &$m): void { $m['environment']['ARASYA_DB_HOST']='db.example'; $m['database']['host']='db.example'; });
$tamper('reject_identity_mismatch',static function(array &$m): void { $m['database']['user']='sim_ffffffffffff'; });
$tamper('reject_production_secret',static function(array &$m): void { $m['environment']['ARASYA_APP_SECRET']=str_repeat('p',64); });
$tamper('reject_reused_secret',static function(array &$m): void { $m['environment']['ARASYA_SOURCE_SECRET_TRENDHOME']=$m['environment']['ARASYA_APP_SECRET']; });
$tamper('reject_fixture_outside_session',static function(array &$m): void { $m['environment']['ARASYA_TRENDYOL_FIXTURE_FILE']='/etc/hosts'; });
$tamper('reject_non_simulation_environment',static function(array &$m): void { $m['environment']['ARASYA_APP_ENV']='production'; });
chmod($file,0644); [$code]=$verify($file); chmod($file,0600);
$check('reject_readable_manifest',$code!==0,'manifest');
chmod($dir,0755); [$code]=$verify($file); chmod($dir,0700);
$check('reject_open_session_folder',$code!==0,'manifest');
symlink($file,$dir . '/link.json'); [$code]=$verify($dir . '/link.json'); unlink($dir . '/link.json');
$check('reject_linked_manifest',$code!==0,'manifest');
$check('webroot_paths_detected',HostedManifest::insideWebRoot('/home/u/public_html/x') && HostedManifest::insideWebRoot('/home/u/dashboard.sim.arasyahome.ro/p') && !HostedManifest::insideWebRoot('/home/u/arasya-simulation/sessions/' . $id),'manifest');
exec('rm -rf ' . escapeshellarg($work));

// ---------- Part B: the hosted origins against the real kernel and the provisioned simulation database ----------
$values=$local['environment']; $values['ARASYA_ALLOWED_ORIGINS']=$staff . ',' . $dashboard;
$config=Config::fromEnvironment(new ConfigLoader($values));
$kernel=(new Container($config))->kernel();
$call=static function(string $method,string $path,?string $origin,?array $body=null,array $cookies=[],array $extra=[]) use($kernel): array {
    $headers=array_filter(['origin'=>$origin,'content-type'=>$body===null?null:'application/json',...$extra],static fn($v)=>$v!==null);
    $r=$kernel->handle(new Request($method,$path,$headers,$cookies,$body===null?'':json_encode($body,JSON_THROW_ON_ERROR),'127.0.0.1','hosted-api-test','hosted-' . bin2hex(random_bytes(4))));
    return ['status'=>$r->status,'body'=>$r->payload,'headers'=>$r->headers];
};
$code=static fn(array $r): string => (string)($r['body']['error']['code']??'');
$preflight=static fn(string $origin,string $method='POST'): array => $call('OPTIONS','/auth/login',$origin,null,[],['access-control-request-method'=>$method,'access-control-request-headers'=>'content-type,x-csrf-token']);
foreach ([$staff=>'staff',$dashboard=>'dashboard'] as $origin=>$name) {
    $r=$preflight($origin);
    $check("preflight_{$name}_simulation_origin",$r['status']===204 && ($r['headers']['Access-Control-Allow-Origin']??'')===$origin && ($r['headers']['Access-Control-Allow-Credentials']??'')==='true' && ($r['headers']['Vary']??'')==='Origin','cors');
}
foreach (['https://dashboard.arasyahome.ro'=>'production_dashboard','https://staff.arasyahome.ro'=>'production_staff','https://api.sim.arasyahome.ro'=>'api_itself','https://evil.example'=>'external','https://dashboard.sim.arasyahome.ro.evil.example'=>'lookalike','https://dashboard.sim.arasyahome.ro/'=>'malformed_slash','null'=>'null','http://127.0.0.1:4173'=>'loopback'] as $origin=>$name) {
    $r=$preflight($origin);
    $check("preflight_refuses_{$name}",$r['status']===403 && $code($r)==='ORIGIN_DENIED' && !isset($r['headers']['Access-Control-Allow-Origin']),'cors');
}
$r=$call('OPTIONS','/auth/login',$dashboard,null,[],['access-control-request-method'=>'POST','access-control-request-headers'=>'authorization']);
$check('preflight_refuses_unlisted_header',$r['status']===403 && $code($r)==='CORS_HEADER_DENIED','cors');
$accounts=$local['accounts'];
$login=static function(string $name,string $origin) use($call,$accounts): array { return $call('POST','/auth/login',$origin,['username'=>$accounts[$name]['username'],'password'=>$accounts[$name]['password']]); };
$r=$login('manager','https://dashboard.arasyahome.ro');
$check('login_refused_from_production_dashboard',$r['status']===403 && $code($r)==='ORIGIN_DENIED','cors');
$r=$login('manager','https://evil.example');
$check('login_refused_from_external_origin',$r['status']===403 && $code($r)==='ORIGIN_DENIED','cors');
$r=$call('POST','/auth/login',null,['username'=>$accounts['manager']['username'],'password'=>$accounts['manager']['password']]);
$check('login_refused_without_origin',$r['status']===403 && $code($r)==='ORIGIN_DENIED','cors');
$session=static function(array $r): array {
    preg_match('/^arasya_sim_session=([^;]+)/',(string)($r['headers']['Set-Cookie']??''),$m);
    return ['cookie'=>['arasya_sim_session'=>rawurldecode($m[1]??'')],'csrf'=>(string)($r['body']['csrfToken']??''),'raw'=>(string)($r['headers']['Set-Cookie']??'')];
};
$r=$login('manager',$dashboard); $manager=$session($r);
$cookie=$manager['raw'];
$check('dashboard_login_sets_host_only_secure_cookie',$r['status']===200 && ($r['headers']['Access-Control-Allow-Origin']??'')===$dashboard && str_contains($cookie,'HttpOnly') && str_contains($cookie,'Secure') && str_contains($cookie,'SameSite=Lax') && !preg_match('/;\s*Domain=/i',$cookie) && !str_contains($cookie,'__Host-arasya_session') && !isset($r['body']['token']),'session');
$r=$call('GET','/management/me',$dashboard,null,$manager['cookie']);
$check('dashboard_reads_management_profile',$r['status']===200 && in_array('dashboard',$r['body']['applications']??[],true) && ($r['headers']['Access-Control-Allow-Origin']??'')===$dashboard,'session');
$r=$call('GET','/management/me',$dashboard,null,['__Host-arasya_session'=>$manager['cookie']['arasya_sim_session']]);
$check('production_cookie_name_is_never_a_simulation_session',$r['status']===401,'session');
$r=$login('manager',$staff); $managerStaff=$session($r);
$check('same_identity_signs_in_on_staff_simulation',$r['status']===200 && ($r['headers']['Access-Control-Allow-Origin']??'')===$staff,'session');
$r=$call('GET','/orders/mine',$staff,null,$managerStaff['cookie']);
$check('staff_session_reads_own_queue',$r['status']===200,'session');
$unknown='00000000-0000-4000-8000-000000000000';
$decision=['expectedVersion'=>1,'decision'=>'approve','comment'=>'hosted boundary test'];
$r=$call('POST',"/management/production-exceptions/{$unknown}/decision",$dashboard,$decision,$manager['cookie'],['idempotency-key'=>'hosted-no-csrf']);
$check('management_mutation_without_csrf_refused',$r['status']===403 && $code($r)==='CSRF_INVALID','csrf');
$r=$call('POST',"/management/production-exceptions/{$unknown}/decision",$dashboard,$decision,$manager['cookie'],['idempotency-key'=>'hosted-bad-csrf','x-csrf-token'=>'not-the-token']);
$check('management_mutation_with_wrong_csrf_refused',$r['status']===403 && $code($r)==='CSRF_INVALID','csrf');
$r=$call('POST',"/management/production-exceptions/{$unknown}/decision",$dashboard,$decision,$manager['cookie'],['idempotency-key'=>'hosted-other-session-csrf','x-csrf-token'=>$managerStaff['csrf']]);
$check('csrf_token_is_bound_to_its_own_session',$r['status']===403 && $code($r)==='CSRF_INVALID','csrf');
$r=$call('POST',"/management/production-exceptions/{$unknown}/decision",'https://dashboard.arasyahome.ro',$decision,$manager['cookie'],['idempotency-key'=>'hosted-foreign-origin','x-csrf-token'=>$manager['csrf']]);
$check('valid_session_and_csrf_from_production_origin_refused',$r['status']===403 && $code($r)==='ORIGIN_DENIED','csrf');
$r=$call('POST',"/management/production-exceptions/{$unknown}/decision",$dashboard,$decision,$manager['cookie'],['idempotency-key'=>'hosted-authorized','x-csrf-token'=>$manager['csrf']]);
$check('authorized_manager_reaches_the_decision_rules',$r['status']===404 && $code($r)==='EXCEPTION_NOT_FOUND','roles');
$r=$login('labeling',$dashboard); $worker=$session($r);
$check('worker_session_from_dashboard_origin',$r['status']===200,'roles');
$r=$call('GET','/management/me',$dashboard,null,$worker['cookie']);
$check('worker_without_dashboard_application_refused',$r['status']===403,'roles');
$r=$call('POST',"/management/production-exceptions/{$unknown}/decision",$dashboard,$decision,$worker['cookie'],['idempotency-key'=>'hosted-worker','x-csrf-token'=>$worker['csrf']]);
$check('worker_cannot_decide_management_approvals',$r['status']===403,'roles');
$r=$login('stage-supervisor',$dashboard); $supervisor=$session($r);
$r=$call('GET','/management/production-exceptions',$dashboard,null,$supervisor['cookie']);
$supervisorList=$r['status'];
$r=$call('POST',"/management/production-exceptions/{$unknown}/decision",$dashboard,$decision,$supervisor['cookie'],['idempotency-key'=>'hosted-supervisor','x-csrf-token'=>$supervisor['csrf']]);
$check('stage_supervisor_decides_only_through_iam_rules',in_array($r['status'],[403,404],true) && in_array($supervisorList,[200,403],true),'roles');
$r=$login('outsider',$dashboard); $outsider=$session($r);
$r=$call('GET','/management/me',$dashboard,null,$outsider['cookie']);
$check('unauthorized_identity_refused',$r['status']===403,'roles');
$r=$call('GET','/management/me',$dashboard);
$check('anonymous_management_request_refused',$r['status']===401,'roles');
$r=$call('POST','/simulation/scenarios/WOO-TH-001/reset',$dashboard,['expectedReplay'=>0],$manager['cookie'],['idempotency-key'=>'hosted-controller','x-csrf-token'=>$manager['csrf']]);
$check('manager_cannot_use_the_simulation_controller',$r['status']===403,'roles');
// Explicit per-source payment gates survive configuration exactly (SIM-BUG-002 regression).
$gate=Config::fromEnvironment(new ConfigLoader([...$values,'ARASYA_SOURCE_PAYMENT_GATE_TRENDHOME'=>'enforce','ARASYA_SOURCE_PAYMENT_GATE_OUTLETPERDELE'=>'observe']));
$check('payment_gates_preserved',($gate->sourceSettings['trendhome']['paymentGate']??null)==='enforce' && ($gate->sourceSettings['outletperdele']['paymentGate']??null)==='observe','regression');
file_put_contents($report . '/hosted-api.json',json_encode(['passed'=>$passed,'results'=>$results],JSON_PRETTY_PRINT));
echo "PASS hosted-api {$passed} checks\n";
