<?php
declare(strict_types=1);
namespace Arasya\Operations\Analytics;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;
final readonly class AnalyticsController
{
    public function __construct(private AnalyticsService $service,private AuthenticationService $auth,private CsrfGuard $csrf,private Config $config,private RequestContext $context) {}
    public function handle(Request $request): Response
    {
        $session=$this->auth->authenticate($request->cookie($this->config->cookieName())??'',$request->ipAddress,$request->userAgent,$request->requestId);
        $this->context->authenticatedAs($session->employee->employeeUuid); $actor=$session->employee;
        $path=substr($request->path,strlen('/management/analytics'));
        if ($request->method==='PUT' && $path==='/policy') {
            $this->csrf->requireValid($session->rawToken,$request->header('x-csrf-token'));
            return Response::json($this->service->updatePolicy($actor,$request->json(),$request->header('idempotency-key')??'',$request->requestId));
        }
        if ($request->method!=='GET') throw new ApiException(404,'NOT_FOUND','Analytics route not found.');
        if ($path==='/policy') return Response::json($this->service->policy($actor));
        $query=[];
        foreach (['period','from','to','search','departmentId','source','companyId','cursor','limit','afterVersion','afterRequest','afterGroup'] as $name) {
            $value=$request->query($name); if ($value!==null) $query[$name]=$value;
        }
        foreach (['employees'=>'employee','companies'=>'company','orders'=>'order'] as $plural=>$singular) {
            if (preg_match('#^/'.$plural.'/([0-9a-f-]{36})$#D',$path,$m)) return Response::json($this->service->report($actor,$singular,$query,$m[1]));
        }
        if (!in_array($path,['/employees','/departments','/managers','/sources','/companies','/orders'],true)) throw new ApiException(404,'NOT_FOUND','Analytics route not found.');
        return Response::json($this->service->report($actor,substr($path,1),$query));
    }
}
