<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;

final readonly class OrderController
{
    public function __construct(private OrderQueries $queries,private OrderCommands $commands,
        private AuthenticationService $auth,private AuthorizationService $authorization,
        private CsrfGuard $csrf,private Config $config,private RequestContext $context,
        private ?ProductionCommands $productionCommands=null,private ?ProductionQueries $productionQueries=null) {}

    public function handle(Request $request): Response
    {
        $path=substr($request->path,strlen('/b2b/orders'));
        if(preg_match('#^(?:/([^/]{1,64})(?:/(activity|finalize|cancel|duplicate|lines|production|production-sheet\.pdf)(?:/([^/]{1,64})(?:/(duplicate|remove))?)?)?)?$#D',$path,$m)!==1)
            throw new ApiException(404,'NOT_FOUND','Route was not found.');
        $id=$m[1]??''; $section=$m[2]??''; $line=$m[3]??''; $action=$m[4]??'';
        $session=$this->auth->authenticate($request->cookie($this->config->cookieName())??'',$request->ipAddress,$request->userAgent,$request->requestId);
        $actor=$session->employee;
        $this->context->authenticatedAs($actor->employeeUuid);
        OrderAccess::require($this->authorization,$actor,'b2b.access');
        if($section==='production-sheet.pdf') {
            if($line!=='' || $request->method!=='GET') throw new ApiException(405,'METHOD_NOT_ALLOWED','Method is not allowed.');
            $queries=$this->productionQueries??throw new ApiException(503,'SERVICE_UNAVAILABLE','Production handoff is not ready.');
            $lang=ProductionSheetPdf::language($this->filters($request,['lang'])['lang']??null);
            $sheet=$queries->sheet($actor,$id,new \DateTimeImmutable('now',new \DateTimeZone('UTC')));
            return Response::file(ProductionSheetPdf::render($sheet,$lang),['Content-Type'=>'application/pdf',
                'Content-Disposition'=>'attachment; filename="'.ProductionSheetPdf::filename($sheet).'"']);
        }
        if($section==='production') {
            if($line!=='' || $request->query!==[]) throw new ApiException(400,'INVALID_REQUEST','Invalid production request.');
            $queries=$this->productionQueries??throw new ApiException(503,'SERVICE_UNAVAILABLE','Production handoff is not ready.');
            if($request->method==='GET') return Response::json(['production'=>$queries->read($actor,$id)]);
            if($request->method!=='POST') throw new ApiException(405,'METHOD_NOT_ALLOWED','Method is not allowed.');
            $this->csrf->requireValid($session->rawToken,$request->header('x-csrf-token'));
            $data=$this->body($request,['expectedVersion'],['expectedVersion']);
            if(!is_int($data['expectedVersion']) || $data['expectedVersion']<1) throw new ApiException(400,'INVALID_REQUEST','Invalid production version.');
            $commands=$this->productionCommands??throw new ApiException(503,'SERVICE_UNAVAILABLE','Production handoff is not ready.');
            $result=$commands->submit($actor,$id,$data['expectedVersion'],$request->header('idempotency-key')??'',$request->requestId);
            return Response::json(['orderId'=>$id,'production'=>$queries->status($id)],$result['status']);
        }
        if($request->method==='GET') {
            $data=match(true) {
                $id===''=>$this->queries->list($actor,$this->filters($request,['search','status','companyId','currency','from','to','limit','cursor'])),
                $section==='' && $id!=='calculate'=>$this->queries->detail($actor,$id),
                $section==='activity' && $line==='' =>$this->queries->activity($actor,$id,$this->filters($request,['limit','cursor'])),
                default=>throw new ApiException(405,'METHOD_NOT_ALLOWED','Method is not allowed.'),
            };
            return Response::json($data);
        }
        $this->csrf->requireValid($session->rawToken,$request->header('x-csrf-token'));
        $key=$request->header('idempotency-key')??'';
        if($request->method==='POST' && $id==='calculate' && $section==='') {
            // Pure preview: protected by access and CSRF; no business mutation or idempotency record.
            if(!$this->authorization->can($actor,OrderAccess::CREATE) && !$this->authorization->can($actor,OrderAccess::UPDATE))
                throw new ApiException(403,'UNAUTHORIZED_ACTION','Order editing permission is required.');
            $data=OrderInput::calculation($this->body($request,['currencyCode','lines']));
            return Response::json(OrderCalculator::calculate($data['currencyCode'],$data['lines']));
        }
        $result=match(true) {
            $request->method==='POST' && $id==='' =>$this->commands->create($actor,$this->body($request,OrderInput::FIELDS),$key,$request->requestId),
            $request->method==='PUT' && $id!=='' && $section==='' =>(function() use($request,$actor,$id,$key) {
                $data=$this->body($request,[...OrderInput::FIELDS,'expectedVersion'],['expectedVersion']);
                $version=$data['expectedVersion']; unset($data['expectedVersion']);
                return $this->commands->update($actor,$id,$data,$version,$key,$request->requestId);
            })(),
            $request->method==='POST' && in_array($section,['finalize','cancel','duplicate'],true) && $line==='' =>(function() use($request,$actor,$id,$section,$key) {
                $data=$this->body($request,['expectedVersion'],['expectedVersion']);
                return $section==='duplicate'?
                    $this->commands->duplicate($actor,$id,$data['expectedVersion'],$key,$request->requestId):
                    $this->commands->status($actor,$id,$section,$data['expectedVersion'],$key,$request->requestId);
            })(),
            $section==='lines' =>(function() use($request,$actor,$id,$line,$action,$key) {
                $op=match(true) {
                    $request->method==='POST' && $line==='' =>'create',
                    $request->method==='POST' && $line==='reorder' && $action==='' =>'reorder',
                    $request->method==='PUT' && $line!=='' && $action==='' =>'update',
                    $request->method==='POST' && $line!=='' && in_array($action,['duplicate','remove'],true) =>$action,
                    default=>throw new ApiException(405,'METHOD_NOT_ALLOWED','Method is not allowed.'),
                };
                $allowed=match($op){'create','update'=>OrderInput::LINE_FIELDS,'reorder'=>['lineIds'],default=>[]};
                $data=$this->body($request,[...$allowed,'expectedVersion'],['expectedVersion']);
                $version=$data['expectedVersion']; unset($data['expectedVersion']);
                return $this->commands->line($actor,$id,$op,in_array($op,['create','reorder'],true)?null:$line,$data,$version,$key,$request->requestId);
            })(),
            default=>throw new ApiException(405,'METHOD_NOT_ALLOWED','Method is not allowed.'),
        };
        return Response::json(['orderId'=>$result['orderId'],'detail'=>$this->authorization->can($actor,OrderAccess::VIEW)?
            $this->queries->detail($actor,$result['orderId']):null],$result['status']);
    }
    private function body(Request $request,array $allowed,array $required=[]): array
    {
        // 100 lines with bounded notes fit in this limit. No payload is logged.
        $data=$request->json(1_048_576);
        if(array_diff(array_keys($data),$allowed)!==[] || array_diff($required,array_keys($data))!==[])
            throw new ApiException(400,'INVALID_REQUEST','Invalid request fields.');
        return $data;
    }
    private function filters(Request $request,array $allowed): array
    {
        if(array_diff(array_keys($request->query),$allowed)!==[]) throw new ApiException(400,'INVALID_REQUEST','Unknown filter.');
        $out=[];
        foreach($request->query as $field=>$value) {
            if(!is_string($value) || strlen($value)>1400) throw new ApiException(400,'INVALID_REQUEST','Invalid filter.');
            if($value!=='') $out[$field]=$value;
        }
        return $out;
    }
}
