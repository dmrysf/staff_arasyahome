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

final readonly class ProjectController
{
    public function __construct(private ProjectQueries $queries,private ProjectCommands $commands,
        private AuthenticationService $auth,private AuthorizationService $authorization,
        private CsrfGuard $csrf,private Config $config,private RequestContext $context) {}

    public function handle(Request $request): Response
    {
        $path=substr($request->path,strlen('/b2b/projects'));
        if(preg_match('#^(?:/([^/]{1,64})(?:/(status|changes|rooms|scene|commercial|orders|activity|proposal\.pdf)(?:/([^/]{1,64}))?)?)?$#D',$path,$m)!==1)
            throw new ApiException(404,'NOT_FOUND','Route was not found.');
        $id=$m[1]??''; $section=$m[2]??''; $child=$m[3]??'';
        if($child!=='' && $section!=='rooms') throw new ApiException(404,'NOT_FOUND','Route was not found.');
        $session=$this->auth->authenticate($request->cookie($this->config->cookieName())??'',$request->ipAddress,$request->userAgent,$request->requestId);
        $actor=$session->employee;
        $this->context->authenticatedAs($actor->employeeUuid);
        OrderAccess::require($this->authorization,$actor,'b2b.access');
        if($request->method==='GET') {
            if($section==='proposal.pdf') {
                $lang=ProjectProposalPdf::language($this->filters($request,['lang'])['lang']??null);
                $data=$this->queries->proposal($actor,$id);
                return Response::file(ProjectProposalPdf::render($data,$lang),[
                    'Content-Type'=>'application/pdf',
                    'Content-Disposition'=>'attachment; filename="'.ProjectProposalPdf::filename($data).'"',
                ]);
            }
            $data=match(true) {
                $id===''=>$this->queries->list($actor,$this->filters($request,['search','status','companyId','limit','cursor'])),
                $section===''=>$this->queries->detail($actor,$this->noQuery($request,$id)),
                $section==='rooms' && $child!==''=>$this->queries->room($actor,$this->noQuery($request,$id),$child),
                $section==='scene'=>$this->queries->scene($actor,$id,$this->filters($request,['roomId'])['roomId']??null),
                $section==='commercial'=>$this->queries->commercial($actor,$this->noQuery($request,$id)),
                $section==='orders'=>$this->queries->orders($actor,$this->noQuery($request,$id)),
                $section==='activity'=>$this->queries->activity($actor,$id,$this->filters($request,['limit','cursor'])),
                default=>throw new ApiException(405,'METHOD_NOT_ALLOWED','Method is not allowed.'),
            };
            return Response::json($data);
        }
        $this->csrf->requireValid($session->rawToken,$request->header('x-csrf-token'));
        if($request->query!==[]) throw new ApiException(400,'INVALID_REQUEST','Invalid request.');
        $key=$request->header('idempotency-key')??'';
        $result=match(true) {
            $request->method==='POST' && $id==='' =>$this->commands->create($actor,$this->body($request,ProjectInput::PROJECT_FIELDS),$key,$request->requestId),
            $request->method==='PUT' && $id!=='' && $section==='' =>(function() use($request,$actor,$id,$key) {
                $data=$this->body($request,[...ProjectInput::PROJECT_FIELDS,'expectedVersion'],['expectedVersion']);
                $version=$data['expectedVersion']; unset($data['expectedVersion']);
                return $this->commands->update($actor,$id,$data,$version,$key,$request->requestId);
            })(),
            $request->method==='POST' && $section==='status' =>(function() use($request,$actor,$id,$key) {
                $data=$this->body($request,['status','expectedVersion'],['status','expectedVersion']);
                return $this->commands->status($actor,$id,$data['status'],$data['expectedVersion'],$key,$request->requestId);
            })(),
            $request->method==='POST' && $section==='changes' =>$this->commands->changes($actor,$id,
                $this->body($request,['operations'],['operations'],4_194_304)['operations'],$key,$request->requestId),
            $request->method==='POST' && $section==='orders' =>$this->commands->convert($actor,$id,$this->body($request,['treatmentIds','expectedRevision']),$key,$request->requestId),
            default=>throw new ApiException(405,'METHOD_NOT_ALLOWED','Method is not allowed.'),
        };
        $status=$result['status']; unset($result['status']);
        if(in_array($section,['','status'],true) && $this->authorization->can($actor,ProjectAccess::VIEW))
            $result['detail']=$this->queries->detail($actor,$result['projectId']);
        return Response::json($result,$status);
    }

    private function noQuery(Request $request,string $id): string
    {
        if($request->query!==[]) throw new ApiException(400,'INVALID_REQUEST','Unknown filter.');
        return $id;
    }
    private function body(Request $request,array $allowed,array $required=[],int $limit=1_048_576): array
    {
        $data=$request->json($limit);
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
