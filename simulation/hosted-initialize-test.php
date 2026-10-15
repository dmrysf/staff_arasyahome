<?php
// Runs the REAL hosted initialization path end to end against a disposable server, exactly as the owner runs it on
// cPanel: create-cpanel-manifest.php (password on STDIN), then initialize-cpanel.php on the EMPTY simulation database.
// It proves the full result (schema, every migration, synthetic accounts, scenarios, private manifest, hosted login)
// and that the initializer fails closed on a re-run and on a partially initialized (schema-only, 3-table) database.
// Every secret stays inside this process; output is check names only.
// Usage: php simulation/hosted-initialize-test.php /abs/runtime port
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
use Arasya\Operations\Application\Container;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Config\ConfigLoader;
use Arasya\Operations\Database\SqlFileRunner;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Simulation\HostedManifest;
use Arasya\Operations\Simulation\ScenarioCatalog;

if (PHP_SAPI!=='cli' || $argc!==3) throw new RuntimeException('Usage: php simulation/hosted-initialize-test.php /abs/runtime port');
umask(077);
$runtime=realpath($argv[1]); $port=(int)$argv[2];
if ($runtime===false || $port<1024 || $port>65535) throw new RuntimeException('SIMULATION_TEST_ARGUMENTS');
// The same disposable-server boundary as provision.php: the CI service, or the local server whose datadir is ours.
$tcp=getenv('GITHUB_ACTIONS')==='true' && getenv('ARASYA_SIMULATION_CI_SERVER')==='dedicated';
$socket=$runtime . '/sim-mariadb.sock';
if ($tcp) {
    $server=new PDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4','root',(string)getenv('ARASYA_SIMULATION_CI_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $accountHost=explode('@',(string)$server->query('SELECT USER()')->fetchColumn(),2)[1];
} else {
    $server=new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if (realpath($server->query("SHOW VARIABLES LIKE 'datadir'")->fetch(PDO::FETCH_NUM)[1])!==realpath($runtime . '/mariadb')) throw new RuntimeException('SIMULATION_SERVER_BOUNDARY');
    // cPanel connects to `localhost` through the server socket; the subprocesses do the same here.
    $accountHost='localhost';
}
$ini=$tcp ? [] : ['-d','pdo_mysql.default_socket=' . $socket];
$passed=0;
$check=static function(string $id,bool $ok) use(&$passed): void { if (!$ok) throw new RuntimeException('FAIL ' . $id); $passed++; echo "PASS hosted-initialize {$id}\n"; };
$php=static function(array $args,string $stdin='') use($ini): array {
    $process=proc_open([PHP_BINARY,...$ini,...$args],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],$stdin); fclose($pipes[0]);
    $out=(string)stream_get_contents($pipes[1]); $err=(string)stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process),$out,$err];
};
$script=static fn(string $name): string => dirname(__DIR__) . '/simulation/' . $name;
$work=$runtime . '/hosted-init-' . bin2hex(random_bytes(4));
$sessions=$work . '/arasya-simulation/sessions'; mkdir($sessions,0700,true); chmod($work . '/arasya-simulation',0700);
$admin=static function(string $db) use($tcp,$port,$socket): PDO {
    return new PDO(($tcp ? 'mysql:host=127.0.0.1;port=' . $port : 'mysql:unix_socket=' . $socket) . ';dbname=' . $db . ';charset=utf8mb4','root',$tcp ? (string)getenv('ARASYA_SIMULATION_CI_PASSWORD') : '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
};
$tables=static fn(PDO $pdo,string $db): int => (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=' . $pdo->quote($db))->fetchColumn();
$leaks=static function(string $output,array $secrets): bool { foreach ($secrets as $secret) if ($secret!=='' && str_contains($output,$secret)) return true; return false; };

// An owner-created empty database with a database-only grant, and the manifest from the real creator script.
$prepare=static function(string $id) use($server,$accountHost,$sessions,$php,$script,$check,$tcp): array {
    $db='arasya_sim_test_' . $id; $user='sim_' . $id; $password=bin2hex(random_bytes(24));
    $server->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $account="'{$user}'@" . $server->quote($accountHost);
    $server->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $server->quote($password));
    $server->exec('GRANT ALL PRIVILEGES ON `' . str_replace('_','\\_',$db) . '`.* TO ' . $account);
    $dir=$sessions . '/' . $id;
    [$code,$out,$err]=$php([$script('create-cpanel-manifest.php'),$dir,$id,$db,$user],$password);
    $check($id . '_manifest_created',$code===0 && trim($out)==='SIMULATION_PRIVATE_MANIFEST_CREATED' && !str_contains($out . $err,$password));
    $file=$dir . '/private.json';
    if ($tcp) {
        // The CI service has no local socket; 127.0.0.1 is the other database host the hosted manifest accepts.
        $manifest=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        $manifest['environment']['ARASYA_DB_HOST']='127.0.0.1'; $manifest['database']['host']='127.0.0.1';
        file_put_contents($file,json_encode($manifest,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); chmod($file,0600);
    }
    return [$file,$db,$password];
};

// ---------- 1. Full hosted initialization of an empty database ----------
$id=bin2hex(random_bytes(6));
[$file,$db,$dbPassword]=$prepare($id);
$before=HostedManifest::load($file);
[$code,$out,$err]=$php([$script('verify-hosted-manifest.php'),$file]);
$check('created_manifest_verifies',$code===0 && trim($out)==='SIMULATION_HOSTED_MANIFEST_OK');
[$code,$out,$err]=$php([$script('initialize-cpanel.php'),$file]);
$lines=array_values(array_filter(explode("\n",trim($out))));
// stderr carries the API's structured request log; PHP diagnostics there are failures.
$clean=preg_match('/PHP (?:Warning|Notice|Deprecated|Fatal)|Uncaught/',$err)!==1;
if ($code!==0 || end($lines)!=='SIMULATION_DB_INITIALIZE_OK' || !$clean) {
    // Diagnostics without values: every string of the private manifest and the DB password are masked.
    $known=[$dbPassword]; array_walk_recursive($before,static function($v) use(&$known): void { if (is_string($v) && strlen($v)>=16) $known[]=$v; });
    $raw=json_decode((string)file_get_contents($file),true); if (is_array($raw)) array_walk_recursive($raw,static function($v) use(&$known): void { if (is_string($v) && strlen($v)>=16) $known[]=$v; });
    fwrite(STDERR,'initializer exit ' . $code . "\n" . substr(str_replace($known,'[masked]',$err . "\n--- last stdout line: " . (string)end($lines)),0,2000) . "\n");
}
$check('initializer_completes_with_marker',$code===0 && end($lines)==='SIMULATION_DB_INITIALIZE_OK' && $clean);
$after=HostedManifest::load($file);
$secrets=[$dbPassword,$after['root']['password'],...array_values(array_filter($after['environment'],static fn(string $v,string $k): bool => $k==='ARASYA_APP_SECRET' || str_starts_with($k,'ARASYA_SOURCE_SECRET_'),ARRAY_FILTER_USE_BOTH)),...array_column($after['accounts'],'password')];
$check('initializer_output_has_no_secret',!$leaks($out . $err,$secrets));
$pdo=$admin($db);
$env=$pdo->query('SELECT database_name,secret_hash,session_id FROM simulation_environment')->fetchAll();
$check('environment_row_names_this_database',$env===[['database_name'=>$db,'secret_hash'=>hash('sha256',$after['environment']['ARASYA_APP_SECRET']),'session_id'=>$id]]);
$migrations=count(glob(dirname(__DIR__) . '/operations-api/database/migrations/*.sql'));
$check('every_migration_applied',(int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn()===$migrations && $migrations>0);
$check('schema_beyond_simulation_tables',$tables($pdo,$db)>3);
$usernames=$pdo->query("SELECT username FROM employees WHERE username LIKE 'sim.%'")->fetchAll(PDO::FETCH_COLUMN);
$check('synthetic_accounts_created',in_array('sim.root',$usernames,true) && count($after['accounts'])>=20 && array_diff(array_column($after['accounts'],'username'),$usernames)===[]);
$check('no_non_synthetic_employee',(int)$pdo->query("SELECT COUNT(*) FROM employees WHERE username NOT LIKE 'sim.%'")->fetchColumn()===0);
$runs=$pdo->query('SELECT scenario_id,state FROM simulation_runs')->fetchAll(PDO::FETCH_KEY_PAIR);
$check('every_scenario_started',count($runs)===count(ScenarioCatalog::all()) && !in_array('not_run',$runs,true));
$check('manifest_private_and_metadata_kept',(fileperms($file)&0777)===0600 && HostedManifest::metadata($after)===HostedManifest::metadata($before) && $after['environment']===$before['environment'] && $after['root']['username']==='sim.root');
[$code,$out,$err]=$php([$script('verify-hosted-manifest.php'),$file]);
$check('initialized_manifest_verifies',$code===0 && trim($out)==='SIMULATION_HOSTED_MANIFEST_OK');
[$code,$out,$err]=$php([$script('check-environment.php'),$file,'--hosted']);
$check('hosted_environment_invariants',$code===0 && !str_contains($out,'FAIL ') && str_contains($out,'PASS '));
[$code,$out,$err]=$php([$script('account-password.php'),$file]);
$check('account_list_names_only',$code===0 && str_contains($out,'root → sim.root') && !$leaks($out . $err,$secrets));
[$code,$out,$err]=$php([$script('account-password.php'),$file,'root']);
$check('password_refused_without_terminal',$code===2 && trim($err)==='SIMULATION_PASSWORD_REQUIRES_TERMINAL' && !$leaks($out,$secrets));

// A synthetic account signs in through the real kernel from the hosted origins only.
$values=$after['environment'];
$config=Config::fromEnvironment(new ConfigLoader($values));
$appPdo=new PDO(($tcp ? 'mysql:host=127.0.0.1;port=' . $port : 'mysql:unix_socket=' . $socket) . ';dbname=' . $db . ';charset=utf8mb4',$values['ARASYA_DB_USER'],$values['ARASYA_DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$kernel=(new Container($config,$appPdo))->kernel();
$login=static function(string $origin,array $account) use($kernel): int {
    $r=$kernel->handle(new Request('POST','/auth/login',['origin'=>$origin,'content-type'=>'application/json'],[],json_encode(['username'=>$account['username'],'password'=>$account['password']],JSON_THROW_ON_ERROR),'127.0.0.1','hosted-initialize-test','hosted-init'));
    return $r->status;
};
$check('synthetic_login_from_staff_origin',$login('https://sim.arasyahome.ro',$after['accounts']['controller'])===200);
$check('synthetic_login_from_dashboard_origin',$login('https://dashboard.sim.arasyahome.ro',$after['accounts']['manager'])===200);
$check('production_origin_refused',$login('https://staff.arasyahome.ro',$after['accounts']['controller'])>=400);

// ---------- 2. A re-run on the initialized database fails closed and changes nothing ----------
$hash=hash_file('sha256',$file); $count=$tables($pdo,$db);
[$code,$out,$err]=$php([$script('initialize-cpanel.php'),$file]);
$check('rerun_refused',$code!==0 && str_contains($err,'SIMULATION_INITIALIZATION_REQUIRES_EMPTY_DATABASE') && !str_contains($out,'SIMULATION_DB_INITIALIZE_OK'));
$check('rerun_changed_nothing',hash_file('sha256',$file)===$hash && $tables($pdo,$db)===$count && (int)$pdo->query('SELECT COUNT(*) FROM simulation_environment')->fetchColumn()===1);

// ---------- 3. A partially initialized (schema-only, 3-table) database fails closed ----------
$partialId=bin2hex(random_bytes(6));
[$partialFile,$partialDb]=$prepare($partialId);
$partial=$admin($partialDb);
(new SqlFileRunner($partial))->run(dirname(__DIR__) . '/simulation/schema.sql');
$check('partial_state_reproduced',$tables($partial,$partialDb)===3);
$hash=hash_file('sha256',$partialFile);
[$code,$out,$err]=$php([$script('initialize-cpanel.php'),$partialFile]);
$check('partial_database_refused',$code!==0 && str_contains($err,'SIMULATION_INITIALIZATION_REQUIRES_EMPTY_DATABASE') && !str_contains($out,'SIMULATION_DB_INITIALIZE_OK'));
$check('partial_database_unchanged',$tables($partial,$partialDb)===3 && (int)$partial->query('SELECT COUNT(*) FROM simulation_environment')->fetchColumn()===0 && hash_file('sha256',$partialFile)===$hash);
$check('partial_manifest_stays_uninitialized',HostedManifest::load($partialFile)['accounts']===[] && !isset(HostedManifest::load($partialFile)['root']));

// ---------- 4. The shared initializer refuses to run without its caller's context ----------
[$code,$out,$err]=$php(['-r','require ' . var_export(dirname(__DIR__) . '/operations-api/bootstrap.php',true) . '; $config=null; require ' . var_export($script('initialize-session.php'),true) . ';']);
$check('initializer_requires_context',$code!==0 && str_contains($err,'SIMULATION_INITIALIZER_CONTEXT'));

echo "SIMULATION_HOSTED_INITIALIZE_TEST_OK {$passed}\n";
