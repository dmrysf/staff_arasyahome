<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;
use PDO;
/** Narrow read adapter for authorized analytics: identity only, never commercial amounts or contacts. */
final readonly class ProductionAnalyticsIdentity
{
    public function __construct(private PDO $pdo) {}
    public function companyForOperationalOrder(string $orderUuid): ?string
    {
        $s=$this->pdo->prepare('SELECT b.company_uuid FROM b2b_production_handoffs h JOIN b2b_orders b ON b.order_uuid=h.b2b_order_uuid WHERE h.operational_order_uuid=?');
        $s->execute([$orderUuid]); $id=$s->fetchColumn(); return $id===false?null:(string)$id;
    }
    public function companyLabel(string $id): ?string
    {
        $s=$this->pdo->prepare('SELECT legal_name FROM b2b_companies WHERE company_uuid=?');
        $s->execute([$id]); $label=$s->fetchColumn(); return $label===false?null:(string)$label;
    }
}
