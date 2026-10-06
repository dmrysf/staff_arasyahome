<?php

declare(strict_types=1);

namespace Arasya\Operations\Application;

use Arasya\Operations\Activity\ActivityController;
use Arasya\Operations\Activity\PdoActivityRepository;
use Arasya\Operations\Audit\PdoAuditLogger;
use Arasya\Operations\Authorization\AuthorizationService;
use Arasya\Operations\Auth\AuthenticationService;
use Arasya\Operations\Auth\PdoLoginRateLimiter;
use Arasya\Operations\Auth\PdoSessionRepository;
use Arasya\Operations\B2B\AccountCommands;
use Arasya\Operations\B2B\AccountController;
use Arasya\Operations\B2B\AccountQueries;
use Arasya\Operations\B2B\CompanyCommands;
use Arasya\Operations\B2B\CompanyController;
use Arasya\Operations\B2B\CompanyQueries;
use Arasya\Operations\B2B\OrderCommands;
use Arasya\Operations\B2B\OrderController;
use Arasya\Operations\B2B\OrderQueries;
use Arasya\Operations\B2B\ProductionCommands;
use Arasya\Operations\B2B\ProductionQueries;
use Arasya\Operations\B2B\ProjectCommands;
use Arasya\Operations\B2B\ProjectController;
use Arasya\Operations\B2B\ProjectQueries;
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
use Arasya\Operations\Iam\IamAuditLogger;
use Arasya\Operations\Integration\SourceIngestionController;
use Arasya\Operations\Integration\SourceSignatureVerifier;
use Arasya\Operations\Integration\Trendyol\StreamTrendyolTransport;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
use Arasya\Operations\Integration\Trendyol\TrendyolSynchronizer;
use Arasya\Operations\Management\ManagementController;
use Arasya\Operations\Management\ManagementService;
use Arasya\Operations\Management\OrderControlService;
use Arasya\Operations\Management\OrderLookupService;
use Arasya\Operations\Management\OrderOwnershipService;
use Arasya\Operations\Management\OrganizationService;
use Arasya\Operations\Management\ProductionSettingsService;
use Arasya\Operations\Management\ProductionOverviewService;
use Arasya\Operations\Order\OperationalOrderController;
use Arasya\Operations\Order\OrderAccessPolicy;
use Arasya\Operations\Order\OrderOperationsService;
use Arasya\Operations\Order\OrderProjectionWriter;
use Arasya\Operations\Order\OrderSerializer;
use Arasya\Operations\Order\PdoOperationalOrderRepository;
use Arasya\Operations\Production\PdoProductionWorkflowRepository;
use Arasya\Operations\Production\ProductionWorkflowService;
use Arasya\Operations\Quality\ApproverPolicy;
use Arasya\Operations\Quality\CuttingFaultService;
use Arasya\Operations\Quality\ExceptionQueries;
use Arasya\Operations\Quality\IdempotencyStore;
use Arasya\Operations\Quality\LiveEvents;
use Arasya\Operations\Quality\QualityController;
use Arasya\Operations\Security\CookiePolicy;
use Arasya\Operations\Security\CsrfGuard;
use Arasya\Operations\Security\PasswordHasher;
use Arasya\Operations\Security\PdoApiRateLimiter;
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
        $authorization = new AuthorizationService();
        $csrf = new CsrfGuard($this->tokens);
        $workflows = new ProductionWorkflowService(new PdoProductionWorkflowRepository($this->pdo));
        $orders = $this->orderRepository();
        $policy = new OrderAccessPolicy($authorization);
        $serializer = new OrderSerializer();
        $rateLimiter = new PdoApiRateLimiter($this->pdo, $this->clock, $this->config->appSecret);
        $iamAudit = new IamAuditLogger($this->pdo);
        $approvers = new ApproverPolicy($this->pdo, $authorization, $this->clock);
        $exceptions = new ExceptionQueries($this->pdo);
        $live = new LiveEvents($this->pdo);
        $idempotency = new IdempotencyStore($this->pdo);
        $faults = new CuttingFaultService($this->pdo, $authorization, $this->employees, $workflows, $approvers, $exceptions, $live, $idempotency, $iamAudit, $this->clock);
        return new ApiKernel(
            new AuthController($this->authentication, $csrf, new CookiePolicy($this->config), $this->config, $authorization, $context),
            new HealthController($this->pdo, $this->clock),
            new CorsPolicy($this->config->allowedOrigins),
            new StructuredLogger(),
            new CookiePolicy($this->config),
            $context,
            new ProductionWorkflowController($workflows, $this->authentication, $this->config, $context),
            new OperationalOrderController(
                $orders,
                $serializer,
                $this->authentication,
                $authorization,
                $this->config,
                $context,
                $policy,
                $workflows,
                new OrderOperationsService($this->pdo, $workflows, $orders, $policy, $serializer, $this->clock, $this->audit, $exceptions),
                $csrf,
                $rateLimiter,
                $exceptions,
            ),
            new ActivityController(new PdoActivityRepository($this->pdo), $this->authentication, $authorization, $this->config, $context, $this->clock),
            new SourceIngestionController(new SourceSignatureVerifier($this->config->sourceSecrets), $this->projectionWriter(), $rateLimiter, $this->clock),
            new ManagementController(
                new ManagementService($this->pdo, $authorization, new IamAuditLogger($this->pdo), $this->passwords, $this->usernames, $this->clock, HealthController::VERSION),
                $this->authentication,
                $csrf,
                $this->config,
                $context,
                new ProductionOverviewService($this->pdo, $authorization, $this->clock, $this->config),
                new OrderControlService($this->pdo, $authorization),
                new OrderOwnershipService($this->pdo, $authorization, $this->employees, $workflows, new IamAuditLogger($this->pdo), $this->clock),
                $approvers,
                $faults,
                $exceptions,
                new OrderLookupService($this->pdo, $authorization, $approvers, $exceptions, $rateLimiter),
                new OrganizationService($this->pdo, $authorization, $idempotency, $iamAudit, $this->clock),
                new ProductionSettingsService($this->pdo, $authorization, $idempotency, $iamAudit, $this->clock),
            ),
            new CompanyController(
                new CompanyQueries($this->pdo, $authorization),
                new CompanyCommands($this->pdo, $authorization, $this->clock),
                $this->authentication,
                $authorization,
                $csrf,
                $this->config,
                $context,
            ),
            new OrderController(new OrderQueries($this->pdo,$authorization),new OrderCommands($this->pdo,$authorization,$this->clock),
                $this->authentication,$authorization,$csrf,$this->config,$context,
                new ProductionCommands($this->pdo,$authorization,$this->clock,$workflows,$this->projectionWriter()),
                new ProductionQueries($this->pdo,$authorization,$workflows)),
            new AccountController($this->authentication, $authorization, $csrf, $this->config, $context,
                new AccountQueries($this->pdo, $authorization, $this->clock), new AccountCommands($this->pdo, $authorization, $this->clock)),
            new ProjectController(new ProjectQueries($this->pdo, $authorization, $this->clock), new ProjectCommands($this->pdo, $authorization, $this->clock),
                $this->authentication, $authorization, $csrf, $this->config, $context),
            new QualityController($faults, $exceptions, $approvers, $live, $this->authentication, $authorization, $csrf, $this->config, $context, $this->pdo, $this->clock),
        );
    }

    public function passwordHasher(): PasswordHasher
    {
        return $this->passwords;
    }

    public function clock(): SystemClock
    {
        return $this->clock;
    }

    public function orderRepository(): PdoOperationalOrderRepository
    {
        return new PdoOperationalOrderRepository($this->pdo, $this->clock, $this->config->sourceFreshSeconds, $this->config->sourceUnavailableSeconds);
    }

    public function projectionWriter(): OrderProjectionWriter
    {
        return new OrderProjectionWriter($this->pdo, $this->clock);
    }

    /** Returns null when Trendyol credentials are not configured. */
    public function trendyolSynchronizer(): ?TrendyolSynchronizer
    {
        if ($this->config->trendyol === null) {
            return null;
        }
        return new TrendyolSynchronizer($this->pdo, new TrendyolClient($this->config->trendyol, new StreamTrendyolTransport()), $this->projectionWriter(), $this->clock);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function requestFactory(): RequestFactory
    {
        return new RequestFactory($this->config);
    }

    public function employeeAdmin(): EmployeeAdminService
    {
        return new EmployeeAdminService($this->employees, $this->sessions, $this->passwords, $this->usernames, $this->audit, $this->clock);
    }

    /** The official IAM service for server-side CLI tools (they act as the root identity). */
    public function managementService(): ManagementService
    {
        return new ManagementService($this->pdo, new AuthorizationService(), new IamAuditLogger($this->pdo), $this->passwords, $this->usernames, $this->clock, HealthController::VERSION);
    }

    public function employeeRepository(): PdoEmployeeRepository
    {
        return $this->employees;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
