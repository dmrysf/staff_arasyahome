<?php

declare(strict_types=1);

namespace Arasya\Operations\Application;

use Arasya\Operations\Audit\PdoAuditLogger;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Auth\PdoLoginRateLimiter;
use Arasya\Operations\Auth\PdoSessionRepository;
use Arasya\Operations\Config\Config;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Employee\EmployeeAdminService;
use Arasya\Operations\Employee\PdoEmployeeRepository;
use Arasya\Operations\Http\ApiKernel;
use Arasya\Operations\Http\AuthController;
use Arasya\Operations\Http\CorsPolicy;
use Arasya\Operations\Http\HealthController;
use Arasya\Operations\Http\RequestFactory;
use Arasya\Operations\Http\RequestContext;
use Arasya\Operations\Http\ProductionWorkflowController;
use Arasya\Operations\Order\OperationalOrderController;
use Arasya\Operations\Order\OrderSerializer;
use Arasya\Operations\Order\PdoOperationalOrderRepository;
use Arasya\Operations\Production\PdoProductionWorkflowRepository;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Security\CookiePolicy;
use Arasya\Operations\Security\CsrfGuard;
use Arasya\Operations\Security\PasswordHasher;
use Arasya\Operations\Security\SessionTokenManager;
use Arasya\Operations\Security\UsernameNormalizer;
use Arasya\Operations\Support\StructuredLogger;
use Arasya\Operations\Support\SystemClock;
use PDO;

final class Container
{
    private readonly Config $config;
    private readonly PDO $pdo;
    private readonly PdoEmployeeRepository $employees;
    private readonly PdoSessionRepository $sessions;
    private readonly PdoAuditLogger $audit;
    private readonly PasswordHasher $passwords;
    private readonly SessionTokenManager $tokens;
    private readonly UsernameNormalizer $usernames;
    private readonly SystemClock $clock;
    private readonly AuthenticationService $authentication;

    public function __construct(?Config $config = null, ?PDO $pdo = null)
    {
        $this->config = $config ?? Config::fromEnvironment();
        $this->pdo = $pdo ?? Connection::create($this->config);
        $this->employees = new PdoEmployeeRepository($this->pdo);
        $this->sessions = new PdoSessionRepository($this->pdo);
        $this->audit = new PdoAuditLogger($this->pdo, $this->config->appSecret);
        $this->passwords = new PasswordHasher();
        $this->tokens = new SessionTokenManager($this->config->appSecret);
        $this->usernames = new UsernameNormalizer();
        $this->clock = new SystemClock();
        $this->authentication = new AuthenticationService(
            $this->employees,
            $this->sessions,
            new PdoLoginRateLimiter($this->pdo, $this->config->loginUsernameLimit, $this->config->loginIpLimit, $this->config->loginWindowSeconds, $this->config->appSecret),
            $this->audit,
            $this->passwords,
            $this->tokens,
            $this->usernames,
            $this->clock,
            $this->config->sessionTtlSeconds,
            $this->config->sessionTouchIntervalSeconds,
        );
    }

    public function kernel(): ApiKernel
    {
        $context = new RequestContext();
        return new ApiKernel(
            new AuthController($this->authentication, new CsrfGuard($this->tokens), new CookiePolicy($this->config), $this->config, new AuthorizationService(), $context),
            new HealthController($this->pdo, $this->clock),
            new CorsPolicy($this->config->allowedOrigins),
            new StructuredLogger(),
            new CookiePolicy($this->config),
            $context,
            new ProductionWorkflowController(
                new ProductionWorkflowService(new PdoProductionWorkflowRepository($this->pdo)),
                $this->authentication,
                $this->config,
                $context,
            ),
            new OperationalOrderController(
                new PdoOperationalOrderRepository($this->pdo),
                new OrderSerializer(),
                $this->authentication,
                new AuthorizationService(),
                $this->config,
                $context,
            ),
        );
    }

    public function requestFactory(): RequestFactory
    {
        return new RequestFactory($this->config);
    }

    public function employeeAdmin(): EmployeeAdminService
    {
        return new EmployeeAdminService($this->employees, $this->sessions, $this->passwords, $this->usernames, $this->audit, $this->clock);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
