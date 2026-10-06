<?php
declare(strict_types=1);
namespace Arasya\Operations\B2B;

use Arasya\Operations\Document\DeliveryContext;
use PDO;

/**
 * B2B source adapter of the canonical production ticket: the printable customer/delivery identity,
 * read only from the commercial snapshots frozen at finalization (legal name, company code, contact
 * name, delivery address, masked contact phone). Never email, prices, payment or balances.
 * It is frozen into the operational order at handoff; older handoffs are read through this adapter.
 */
final class ProductionDocumentIdentity
{
    /** @param array<string,mixed> $order b2b_orders row with its snapshot columns */
    public static function fromCommercialOrder(array $order): ?array
    {
        $company=OrderStore::decode($order['company_snapshot']??null)??[];
        $contact=OrderStore::decode($order['contact_snapshot']??null)??[];
        $address=OrderStore::decode($order['delivery_address_snapshot']??null)??[];
        $text=static fn(mixed $v): ?string=>is_string($v) && trim($v)!==''?trim($v):null;
        $postalCity=trim(implode(' ',array_filter([$text($address['postalCode']??null),$text($address['city']??null)])));
        $context=DeliveryContext::build($text($company['legalName']??null),$text($company['companyCode']??null),array_values(array_filter([
            $text($address['addressLine1']??null),$text($address['addressLine2']??null),$postalCity===''?null:$postalCity,
            $text($address['countyRegion']??null),$text($address['countryCode']??null),
        ])),DeliveryContext::maskPhone($contact['phone']??null));
        return $context===null?null:$context+['contact'=>$text($contact['name']??null)];
    }

    /** Identity of an operational order created by a B2B handoff (null when it is not one). */
    public static function forOperationalOrder(PDO $pdo,string $operationalOrderUuid): ?array
    {
        $s=$pdo->prepare('SELECT c.company_snapshot,c.contact_snapshot,c.delivery_address_snapshot FROM b2b_production_handoffs h
            INNER JOIN b2b_orders c ON c.order_uuid=h.b2b_order_uuid WHERE h.operational_order_uuid=?');
        $s->execute([$operationalOrderUuid]); $row=$s->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?self::fromCommercialOrder($row):null;
    }
}
