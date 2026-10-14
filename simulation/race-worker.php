<?php
// Child has its own PDO and kernel. Commands arrive over stdin, never process arguments.
declare(strict_types=1);
require dirname(__DIR__) . '/operations-api/bootstrap.php';
$input=json_decode((string)stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
foreach ($input['environment'] as $key=>$value) putenv($key . '=' . $value);
$container=new Arasya\Operations\Application\Container();
touch($input['ready']);
$deadline=microtime(true)+10;
while (!is_file($input['barrier'])) {
    if (microtime(true)>$deadline) throw new RuntimeException('SIMULATION_BARRIER_TIMEOUT');
    usleep(1000);
}
$started=microtime(true);
touch($input['started']);
$r=$container->kernel()->handle(new Arasya\Operations\Http\Request('POST',$input['path'],['origin'=>explode(',',(string)getenv('ARASYA_ALLOWED_ORIGINS'))[0],'content-type'=>'application/json','x-csrf-token'=>$input['csrf'],'idempotency-key'=>$input['key']],
    [$container->config()->cookieName()=>$input['cookie']],json_encode($input['body'],JSON_THROW_ON_ERROR),'127.0.0.1','factory-race','factory-race-' . getmypid()));
echo json_encode(['pid'=>getmypid(),'startedAt'=>$started,'endedAt'=>microtime(true),'status'=>$r->status,'code'=>$r->payload['error']['code']??null],JSON_THROW_ON_ERROR);
