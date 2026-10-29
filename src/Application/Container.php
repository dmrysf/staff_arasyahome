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
use Arasya\Operations\Cutting\BoardSnapshot;
use Arasya\Operations\Cutting\CuttingController;
use Arasya\Operations\Cutting\CuttingService;
use Arasya\Operations\Cutting\DisplayDevices;
use Arasya\Operations\Database\Connection;
use Arasya\Operations\Document\DocumentAudiences;
use Arasya\Operations\Document\DocumentController;
use Arasya\Operations\Document\DocumentQueries;
use Arasya\Operations\Document\DocumentService;
use Arasya\Operations\Document\RevisionApproverPolicy;
use Arasya\Operations\Document\TicketSnapshot;
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
use Arasya\Operations\Integration\SourceRegistry;
use Arasya\Operations\Integration\SourceSignatureVerifier;
use Arasya\Operations\Integration\Trendyol\StreamTrendyolTransport;
use Arasya\Operations\Integration\Trendyol\TrendyolClient;
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

    /**
     * @param \Arasya\Operations\Integration\SourceAccess\SourceAccessTransport|null $sourceAccessTransport test stand-in for the
     *        shop; production always uses StreamSourceAccessTransport (loopback test shops only outside production)
     */
    public function __construct(?Config $config = null, ?PDO $pdo = null, private readonly ?\Arasya\Operations\Integration\SourceAccess\SourceAccessTransport $sourceAccessTransport = null)
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

    /** Confirmare operations (API 2.38.0): pool, distribution, decisions. Also used by bin/confirmare-distribute.php. */
    public function confirmareService(?AuthorizationService $authorization = null): \Arasya\Operations\Confirmare\ConfirmareService
    {
        return new \Arasya\Operations\Confirmare\ConfirmareService($this->pdo, $authorization ?? new AuthorizationService(), new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo), $this->clock, \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config), $this->authorityModes());
    }

    /** Confirmare source access (API 2.40.0): the shop's own view of one order for its operator, signed, audited. */
    public function confirmareSourceAccess(?AuthorizationService $authorization = null): \Arasya\Operations\Confirmare\ConfirmareSourceAccess
    {
        $transport = $this->sourceAccessTransport ?? new \Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport(allowLoopback: !$this->config->isProduction());
        return new \Arasya\Operations\Confirmare\ConfirmareSourceAccess(
            $this->pdo,
            $authorization ?? new AuthorizationService(),
            new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo),
            \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config),
            new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($transport, $this->clock),
            new PdoApiRateLimiter($this->pdo, $this->clock, $this->config->appSecret),
            $this->clock,
        );
    }

    /** Confirmare finance (API 2.41.0, Phase 5): the shop's money record of an order, two-person decisions, no amounts kept. */
    /** SmartBill invoicing of Confirmare orders (API 2.43.0). */
    public function confirmareInvoice(?AuthorizationService $authorization = null): \Arasya\Operations\Confirmare\ConfirmareInvoice
    {
        $transport = $this->sourceAccessTransport ?? new \Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport(allowLoopback: !$this->config->isProduction());
        return new \Arasya\Operations\Confirmare\ConfirmareInvoice(
            $this->pdo,
            $authorization ?? new AuthorizationService(),
            new \Arasya\Operations\Confirmare\InvoiceScopePolicy($this->pdo),
            new \Arasya\Operations\Confirmare\FinanceScopePolicy($this->pdo),
            new \Arasya\Operations\Document\DocumentScopePolicy($this->pdo),
            \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config),
            new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($transport, $this->clock),
            new PdoApiRateLimiter($this->pdo, $this->clock, $this->config->appSecret),
            $this->clock,
            hash_hmac('sha256', 'confirmare-invoice-request', $this->config->appSecret),
            $this->authorityModes(),
            new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo),
            $this->confirmareCycles(),
            new \Arasya\Operations\Confirmare\InvoiceEfactura($this->pdo, $this->clock, new \Arasya\Operations\Confirmare\InvoiceScopePolicy($this->pdo)),
        );
    }

    /** Reactivation, FAN Courier AWB and NETOPIA card refund relayed to the shop (API 2.46.0). */
    public function confirmareOperations(?AuthorizationService $authorization = null): \Arasya\Operations\Confirmare\ConfirmareOperations
    {
        $transport = $this->sourceAccessTransport ?? new \Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport(allowLoopback: !$this->config->isProduction());
        return new \Arasya\Operations\Confirmare\ConfirmareOperations(
            $this->pdo,
            $this->clock,
            \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config),
            new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($transport, $this->clock),
            new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo),
            new \Arasya\Operations\Confirmare\FinanceScopePolicy($this->pdo),
            new \Arasya\Operations\Confirmare\InvoiceScopePolicy($this->pdo),
            new \Arasya\Operations\Document\DocumentScopePolicy($this->pdo),
            $this->confirmareService($authorization),
            hash_hmac('sha256', 'confirmare-operation-request', $this->config->appSecret),
        );
    }

    /** The Confirmare operating cycle (API 2.46.0): calls, the proforma timer, bank checks; also bin/confirmare-cycles.php. */
    public function confirmareCycles(): \Arasya\Operations\Confirmare\ConfirmareCycles
    {
        $transport = $this->sourceAccessTransport ?? new \Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport(allowLoopback: !$this->config->isProduction());
        return new \Arasya\Operations\Confirmare\ConfirmareCycles(
            $this->pdo,
            $this->clock,
            \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config),
            new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($transport, $this->clock),
            new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo),
            new \Arasya\Operations\Confirmare\FinanceScopePolicy($this->pdo),
            new \Arasya\Operations\Confirmare\InvoiceScopePolicy($this->pdo),
            $this->cancellationSyncService(),
        );
    }

    /** The shop's bank transfers that match no order (API 2.44.0, YD SOFT 2.18.0). */
    public function financeTransferExceptions(?AuthorizationService $authorization = null): \Arasya\Operations\Confirmare\FinanceTransferExceptions
    {
        $transport = $this->sourceAccessTransport ?? new \Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport(allowLoopback: !$this->config->isProduction());
        return new \Arasya\Operations\Confirmare\FinanceTransferExceptions(
            $this->pdo,
            $authorization ?? new AuthorizationService(),
            new \Arasya\Operations\Confirmare\FinanceScopePolicy($this->pdo),
            \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config),
            new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($transport, $this->clock),
            new PdoApiRateLimiter($this->pdo, $this->clock, $this->config->appSecret),
            $this->audit,
            $this->clock,
        );
    }

    public function confirmareFinance(?AuthorizationService $authorization = null): \Arasya\Operations\Confirmare\ConfirmareFinance
    {
        $transport = $this->sourceAccessTransport ?? new \Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport(allowLoopback: !$this->config->isProduction());
        return new \Arasya\Operations\Confirmare\ConfirmareFinance(
            $this->pdo,
            $authorization ?? new AuthorizationService(),
            new \Arasya\Operations\Confirmare\FinanceScopePolicy($this->pdo),
            new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo),
            \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config),
            new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($transport, $this->clock),
            new PdoApiRateLimiter($this->pdo, $this->clock, $this->config->appSecret),
            $this->clock,
            // Request HMACs of the finance audit (an amount is low-entropy: a plain hash would reveal it).
            hash_hmac('sha256', 'confirmare-finance-request', $this->config->appSecret),
        );
    }

    /** Finance verification requests (API 2.50.0, migration 044): the explicit finance task of an advance payment. */
    public function financeVerificationRequests(?AuthorizationService $authorization = null): \Arasya\Operations\Confirmare\FinanceVerificationRequests
    {
        $transport = $this->sourceAccessTransport ?? new \Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport(allowLoopback: !$this->config->isProduction());
        return new \Arasya\Operations\Confirmare\FinanceVerificationRequests(
            $this->pdo,
            $authorization ?? new AuthorizationService(),
            new \Arasya\Operations\Confirmare\FinanceScopePolicy($this->pdo),
            new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo),
            $this->confirmareFinance($authorization),
            \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config),
            new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($transport, $this->clock),
            new PdoApiRateLimiter($this->pdo, $this->clock, $this->config->appSecret),
            $this->clock,
            // The HMAC of the bank evidence of a decision (amounts and references are low-entropy).
            hash_hmac('sha256', 'finance-verification-evidence', $this->config->appSecret),
        );
    }

    /** The finance task inventory (API 2.50.0): existing finance work of covered orders as tasks of named employees. */
    public function financeTaskInventory(): \Arasya\Operations\Confirmare\FinanceTaskInventory
    {
        return new \Arasya\Operations\Confirmare\FinanceTaskInventory($this->pdo, $this->clock);
    }

    /** Confirmare controlled shop edits (API 2.40.0, Phase 4B): preview and apply through the shop's own pricing. */
    public function confirmareSourceEdits(?AuthorizationService $authorization = null): \Arasya\Operations\Confirmare\ConfirmareSourceEdits
    {
        $transport = $this->sourceAccessTransport ?? new \Arasya\Operations\Integration\SourceAccess\StreamSourceAccessTransport(allowLoopback: !$this->config->isProduction());
        return new \Arasya\Operations\Confirmare\ConfirmareSourceEdits(
            $this->pdo,
            $this->confirmareSourceAccess($authorization),
            \Arasya\Operations\Integration\SourceAccess\SourceAccessRegistry::fromConfig($this->config),
            new \Arasya\Operations\Integration\SourceAccess\SourceAccessClient($transport, $this->clock),
            new PdoApiRateLimiter($this->pdo, $this->clock, $this->config->appSecret),
            $this->clock,
        );
    }

    public function kernel(): ApiKernel
    {
        $context = new RequestContext();
        $authorization = new AuthorizationService();
        $csrf = new CsrfGuard($this->tokens);
        $workflows = new ProductionWorkflowService(new PdoProductionWorkflowRepository($this->pdo));
        $orders = $this->orderRepository();
        $authorityModes = $this->authorityModes();
        $policy = new OrderAccessPolicy($authorization, $authorityModes);
        $serializer = new OrderSerializer($this->authorityModes());
        $rateLimiter = new PdoApiRateLimiter($this->pdo, $this->clock, $this->config->appSecret);
        $iamAudit = new IamAuditLogger($this->pdo);
        $approvers = new ApproverPolicy($this->pdo, $authorization, $this->clock);
        $exceptions = new ExceptionQueries($this->pdo);
        $live = new LiveEvents($this->pdo);
        $idempotency = new IdempotencyStore($this->pdo);
        $devices = new DisplayDevices($this->pdo, $this->config, $this->clock, $this->employees, $iamAudit, $idempotency);
        $cutting = new CuttingService($this->pdo, $this->employees, $approvers, $workflows, $idempotency, $iamAudit, $live, $this->clock);
        $faults = new CuttingFaultService($this->pdo, $authorization, $this->employees, $workflows, $approvers, $exceptions, $live, $idempotency, $iamAudit, $this->clock);
        $analyticsPolicy=new \Arasya\Operations\Analytics\AnalyticsPolicy($this->pdo);
        $documentQueries = new DocumentQueries($this->pdo);
        $revisionApprovers = new RevisionApproverPolicy($this->pdo, $authorization, $this->clock);
        $documentScopes = new \Arasya\Operations\Document\DocumentScopePolicy($this->pdo);
        $documents = $this->documentService($authorization, $revisionApprovers, $documentQueries, $live, $idempotency, $iamAudit);
        $tracks = new \Arasya\Operations\Production\ReworkTrackService($this->pdo, $workflows, $authorization, $this->clock, $live, $authorityModes);
        return new ApiKernel(
            new AuthController($this->authentication, $csrf, new CookiePolicy($this->config), $this->config, $authorization, $context, new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo), new \Arasya\Operations\Confirmare\FinanceScopePolicy($this->pdo), new \Arasya\Operations\Confirmare\InvoiceScopePolicy($this->pdo), new \Arasya\Operations\Employee\OwnStaffLocale($this->pdo, $iamAudit, $this->clock)),
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
                new OrderOperationsService($this->pdo, $workflows, $orders, $policy, $serializer, $this->clock, $this->audit, $exceptions, $documentQueries, $tracks),
                $csrf,
                $rateLimiter,
                $exceptions,
                $documentQueries,
                $this->pdo,
                $authorityModes,
                $tracks,
            ),
            new ActivityController(new PdoActivityRepository($this->pdo), $this->authentication, $authorization, $this->config, $context, $this->clock),
            new SourceIngestionController(new SourceSignatureVerifier($this->config->sourceSecrets), $this->projectionWriter(), $rateLimiter, $this->clock, SourceRegistry::fromConfig($this->config), new \Arasya\Operations\Integration\SourceAuthorityQueries($this->pdo), $this->productionQrService($authorization, $iamAudit), $this->documentService($authorization, audit: $iamAudit), new \Arasya\Operations\Document\DocumentRenderer($this->pdo), new \Arasya\Operations\Integration\SourceTrackingQueries($this->pdo), $this->cancellationSyncService($authorization, $idempotency)),
            new ManagementController(
                new ManagementService($this->pdo, $authorization, new IamAuditLogger($this->pdo), $this->passwords, $this->usernames, $this->clock, HealthController::VERSION),
                $this->authentication,
                $csrf,
                $this->config,
                $context,
                new ProductionOverviewService($this->pdo, $authorization, $this->clock, $this->config),
                new OrderControlService($this->pdo, $authorization),
                new OrderOwnershipService($this->pdo, $authorization, $this->employees, $workflows, new IamAuditLogger($this->pdo), $this->clock, $authorityModes),
                $approvers,
                $faults,
                $exceptions,
                new OrderLookupService($this->pdo, $authorization, $approvers, $exceptions, $rateLimiter),
                new OrganizationService($this->pdo, $authorization, $idempotency, $iamAudit, $this->clock),
                new ProductionSettingsService($this->pdo, $authorization, $idempotency, $iamAudit, $this->clock),
                $analyticsPolicy,
                $revisionApprovers,
                $documentScopes,
                new \Arasya\Operations\Cancellation\CancellationService($this->pdo, $authorization, $idempotency, $this->clock, $this->cancellationSyncService($authorization, $idempotency)),
                $this->cancellationSyncService($authorization, $idempotency),
                new \Arasya\Operations\Cancellation\Sync\CancellationReconciler($this->pdo, $this->clock, $this->cancellationProviders()),
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
                new ProductionCommands($this->pdo,$authorization,$this->clock,$workflows,$this->projectionWriter(),$documents),
                new ProductionQueries($this->pdo,$authorization,$workflows),$documents,$this->pdo),
            new AccountController($this->authentication, $authorization, $csrf, $this->config, $context,
                new AccountQueries($this->pdo, $authorization, $this->clock), new AccountCommands($this->pdo, $authorization, $this->clock)),
            new ProjectController(new ProjectQueries($this->pdo, $authorization, $this->clock), new ProjectCommands($this->pdo, $authorization, $this->clock),
                $this->authentication, $authorization, $csrf, $this->config, $context),
            new QualityController($faults, $exceptions, $approvers, $live, $this->authentication, $authorization, $csrf, $this->config, $context, $this->pdo, $this->clock, new DocumentAudiences($authorization, $revisionApprovers, $documentScopes), $rateLimiter),
            new CuttingController($cutting, $devices, new BoardSnapshot($this->pdo, $this->clock, $devices), $live, $this->authentication, $csrf, $this->config, $context, $rateLimiter),
            new \Arasya\Operations\Analytics\AnalyticsController(new \Arasya\Operations\Analytics\AnalyticsService($this->pdo,$analyticsPolicy,$this->clock),$this->authentication,$csrf,$this->config,$context),
            new DocumentController($documents, $documentQueries, $revisionApprovers, $this->authentication, $csrf, $this->config, $context, $rateLimiter, $this->pdo, $documentScopes),
            new \Arasya\Operations\Production\ProductionAuthorityController(
                new \Arasya\Operations\Production\ProductionAuthorityService($this->pdo, $authorization, $workflows, $authorityModes, $iamAudit, $this->audit, $this->clock),
                $this->authentication,
                $csrf,
                $this->config,
                $context,
            ),
            new \Arasya\Operations\Production\ProductionQrController($this->productionQrService($authorization, $iamAudit), $this->authentication, $csrf, $this->config, $context),
            new \Arasya\Operations\Trendyol\TrendyolWorkspaceController(
                new \Arasya\Operations\Trendyol\TrendyolWorkspace($this->pdo, $authorization, $this->employees, $workflows, $this->projectionWriter(), $documents, $idempotency, $this->clock,
                    $this->trendyolClient(), new \Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore($this->pdo, $this->projectionWriter(), $this->clock)),
                $this->authentication,
                $csrf,
                $this->config,
                $context,
            ),
            new \Arasya\Operations\Production\PieceController(
                new \Arasya\Operations\Production\PieceOperationsService($this->pdo, $authorization, $approvers, $idempotency, $this->clock, $workflows),
                $this->authentication,
                $csrf,
                $this->config,
                $context,
            ),
            new \Arasya\Operations\Confirmare\ConfirmareController(
                $this->confirmareService($authorization),
                $this->confirmareSourceAccess($authorization),
                $this->confirmareSourceEdits($authorization),
                $this->confirmareFinance($authorization),
                $this->confirmareInvoice($authorization),
                $this->financeTransferExceptions($authorization),
                $this->confirmareCycles(),
                $this->confirmareOperations($authorization),
                $this->financeVerificationRequests($authorization),
                new \Arasya\Operations\Confirmare\InvoiceEfactura($this->pdo, $this->clock, new \Arasya\Operations\Confirmare\InvoiceScopePolicy($this->pdo)),
                new \Arasya\Operations\Confirmare\ConfirmareHealth($this->pdo, $this->clock, new \Arasya\Operations\Confirmare\ConfirmareScopePolicy($this->pdo),
                    new \Arasya\Operations\Confirmare\InvoiceScopePolicy($this->pdo), new \Arasya\Operations\Confirmare\FinanceScopePolicy($this->pdo)),
                $this->authentication,
                $csrf,
                $this->config,
                $context,
            ),
        );
    }

    /** Production QR authority: manager view and rotation, signed source answer, operator reconciliation. */
    public function productionQrService(?AuthorizationService $authorization = null, ?IamAuditLogger $iamAudit = null): \Arasya\Operations\Production\ProductionQrService
    {
        return new \Arasya\Operations\Production\ProductionQrService($this->pdo, $authorization ?? new AuthorizationService(), $this->authorityModes(), $iamAudit ?? new IamAuditLogger($this->pdo), $this->audit, $this->clock);
    }

    /** The central production document engine (also used by server-side tools and tests). */
    public function documentService(
        ?AuthorizationService $authorization = null,
        ?RevisionApproverPolicy $approvers = null,
        ?DocumentQueries $queries = null,
        ?LiveEvents $live = null,
        ?IdempotencyStore $idempotency = null,
        ?IamAuditLogger $audit = null,
    ): DocumentService {
        $authorization ??= new AuthorizationService();
        return new DocumentService(
            $this->pdo,
            $authorization,
            $this->employees,
            $approvers ?? new RevisionApproverPolicy($this->pdo, $authorization, $this->clock),
            new TicketSnapshot($this->pdo),
            $queries ?? new DocumentQueries($this->pdo),
            $live ?? new LiveEvents($this->pdo),
            $idempotency ?? new IdempotencyStore($this->pdo),
            $audit ?? new IamAuditLogger($this->pdo),
            $this->clock,
            $this->authorityModes(),
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
        return new OrderProjectionWriter($this->pdo, $this->clock, $this->authorityModes());
    }

    /** Per-source production authority modes from the signed source registry (default: legacy). */
    public function authorityModes(): \Arasya\Operations\Production\ProductionAuthorityModes
    {
        return \Arasya\Operations\Production\ProductionAuthorityModes::fromRegistry(SourceRegistry::fromConfig($this->config));
    }

    /**
     * Trendyol intake synchronization, or null while it may not read Trendyol: credentials missing or the
     * ARASYA_TRENDYOL_INTAKE switch not `enabled`. The database activation is checked by the synchronizer.
     */
    public function trendyolIntakeSynchronizer(): ?\Arasya\Operations\Integration\Trendyol\TrendyolIntakeSynchronizer
    {
        $client = $this->trendyolClient();
        if ($client === null || !$this->config->trendyolIntakeEnabled()) {
            return null;
        }
        $writer = $this->projectionWriter();
        return new \Arasya\Operations\Integration\Trendyol\TrendyolIntakeSynchronizer(
            $this->pdo,
            $client,
            new \Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore($this->pdo, $writer, $this->clock),
            $writer,
            $this->clock,
        );
    }

    /**
     * Read-only reconciliation of known Trendyol packages, under the same gates as the synchronization: null without
     * credentials or with the switch off; the reconciler itself refuses unless the intake is `active`.
     */
    public function trendyolReconciler(): ?\Arasya\Operations\Integration\Trendyol\TrendyolReconciler
    {
        $client = $this->trendyolClient();
        if ($client === null || !$this->config->trendyolIntakeEnabled()) {
            return null;
        }
        return new \Arasya\Operations\Integration\Trendyol\TrendyolReconciler(
            $this->pdo,
            $client,
            new \Arasya\Operations\Integration\Trendyol\TrendyolIntakeStore($this->pdo, $this->projectionWriter(), $this->clock),
            $this->clock,
        );
    }

    /**
     * The read-only Trendyol Order V2 client (GET only), or null without credentials. Outside production a test
     * fixture file may stand in for the API (Config refuses it in production).
     */
    public function trendyolClient(): ?TrendyolClient
    {
        if ($this->config->trendyolFixtureFile !== null) {
            return new TrendyolClient(
                \Arasya\Operations\Config\TrendyolCredentials::fromValues('1000001', 'fixture', 'fixture', '') ?? throw new \LogicException('fixture credentials'),
                new \Arasya\Operations\Integration\Trendyol\FixtureTrendyolTransport($this->config->trendyolFixtureFile),
            );
        }
        return $this->config->trendyol === null ? null : new TrendyolClient($this->config->trendyol, new StreamTrendyolTransport());
    }

    /**
     * Outbound cancellation providers (API 2.36.0). The Trendyol provider exists only with credentials and a storefront
     * code (a test fixture outside production stands in for both); without them Trendyol is a manual provider. Nothing
     * is sent unless ARASYA_CANCELLATION_SYNC=enabled and the provider's database flag is enabled as well.
     */
    public function cancellationProviders(): \Arasya\Operations\Cancellation\Sync\ProviderRegistry
    {
        $trendyol = null;
        if ($this->config->trendyolFixtureFile !== null) {
            $transport = new \Arasya\Operations\Integration\Trendyol\FixtureTrendyolTransport($this->config->trendyolFixtureFile);
            $credentials = \Arasya\Operations\Config\TrendyolCredentials::fromValues('1000001', 'fixture', 'fixture', '') ?? throw new \LogicException('fixture credentials');
            $trendyol = new \Arasya\Operations\Cancellation\Sync\TrendyolCancellationProvider(new TrendyolClient($credentials, $transport),
                new \Arasya\Operations\Integration\Trendyol\TrendyolCancellationApi($credentials, $transport, $this->config->trendyolStoreFrontCode ?? 'RO'));
        } elseif ($this->config->trendyol !== null && $this->config->trendyolStoreFrontCode !== null) {
            $transport = new StreamTrendyolTransport();
            $trendyol = new \Arasya\Operations\Cancellation\Sync\TrendyolCancellationProvider(new TrendyolClient($this->config->trendyol, $transport),
                new \Arasya\Operations\Integration\Trendyol\TrendyolCancellationApi($this->config->trendyol, $transport, $this->config->trendyolStoreFrontCode));
        }
        return new \Arasya\Operations\Cancellation\Sync\ProviderRegistry($this->pdo, $this->config, $trendyol);
    }

    public function cancellationSyncService(?AuthorizationService $authorization = null, ?IdempotencyStore $idempotency = null): \Arasya\Operations\Cancellation\Sync\CancellationSyncService
    {
        return new \Arasya\Operations\Cancellation\Sync\CancellationSyncService($this->pdo, $authorization ?? new AuthorizationService(),
            $idempotency ?? new IdempotencyStore($this->pdo), $this->clock, $this->cancellationProviders());
    }

    /** The outbound cancellation worker (operator CLI / cron); it contacts nothing while the gates are closed. */
    public function cancellationSyncWorker(): \Arasya\Operations\Cancellation\Sync\CancellationSyncWorker
    {
        return new \Arasya\Operations\Cancellation\Sync\CancellationSyncWorker($this->pdo, $this->clock, $this->cancellationProviders());
    }

    public function cancellationReconciler(): \Arasya\Operations\Cancellation\Sync\CancellationReconciler
    {
        return new \Arasya\Operations\Cancellation\Sync\CancellationReconciler($this->pdo, $this->clock, $this->cancellationProviders());
    }

    /** The explicit Trendyol intake activation control (operator CLI). */
    public function trendyolIntakeState(): \Arasya\Operations\Integration\Trendyol\TrendyolIntakeState
    {
        return new \Arasya\Operations\Integration\Trendyol\TrendyolIntakeState($this->pdo, $this->clock);
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

    /** The organisation service (CEO principal, responsibilities) for server-side CLI tools acting as root. */
    public function organizationService(): OrganizationService
    {
        return new OrganizationService($this->pdo, new AuthorizationService(), new IdempotencyStore($this->pdo), new IamAuditLogger($this->pdo), $this->clock);
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
