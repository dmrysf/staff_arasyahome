<?php

declare(strict_types=1);
namespace Arasya\Operations\Simulation;

use Arasya\Operations\Application\Container;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Http\ApiException;
use Arasya\Operations\Http\Request;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\Response;
use Arasya\Operations\Security\CsrfGuard;

final readonly class SimulationController
{
    public function __construct(private Container $container, private AuthenticationService $auth, private CsrfGuard $csrf, private AuthorizationService $authorization, private RequestContext $context) {}

    public function handle(Request $request): Response
    {
        if ($this->container->config()->environment !== 'simulation') throw new ApiException(404,'NOT_FOUND','API route was not found.');
        $s=$this->auth->authenticate($request->cookie($this->container->config()->cookieName())??'',$request->ipAddress,$request->userAgent,$request->requestId);
        $this->context->authenticatedAs($s->employee->employeeUuid);
        $this->authorization->requireApplication($s->employee,'staff');
        // This permission is seeded ONLY into the isolated schema. No live role is widened.
        $this->authorization->require($s->employee,'simulation.manage');
        $service=new ScenarioService($this->container);
        if ($request->method==='GET' && in_array($request->path,['/simulation/overview','/simulation/report'],true)) return Response::json($service->overview(),200,['Cache-Control'=>'private, no-store']);
        if ($request->method==='POST' && preg_match('#^/simulation/scenarios/(WOO-(?:TH|OP)-00[12]|TY-(?:0[1-9]|1[0-6]))/(start|pause|resume|reset|fault)$#D',$request->path,$m)) {
            $this->csrf->requireValid($s->rawToken,$request->header('x-csrf-token'));
            $body=$request->json(1024);
            if (array_keys($body)!==['expectedReplay'] || !is_int($body['expectedReplay']) || $body['expectedReplay']<0) throw new ApiException(400,'INVALID_REQUEST','The current replay number is required.');
            return Response::json($service->command($m[1],$m[2],$s->employee->employeeUuid,$body['expectedReplay']),200,['Cache-Control'=>'private, no-store']);
        }
        throw new ApiException(404,'NOT_FOUND','API route was not found.');
    }
}
