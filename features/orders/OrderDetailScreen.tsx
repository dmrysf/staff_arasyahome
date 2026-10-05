import { useEffect, useState } from "react";
import type { ProductionWorkflow, StaffOrder, StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { SourceBadge } from "../../components/SourceBadge";
import { StageLabel } from "../../components/StageLabel";
import { ErrorState } from "../../components/ErrorState";
import { OrderNotices } from "../../components/OrderNotices";
import { toServiceError } from "../../services/errors";
import { requireProductionProducts } from "../../domain/orderValidation";
import { AppIcon } from "../../components/icons/AppIcon";
import { getEmployeeRelationLabel, getEmployeeRelationTime } from "../../domain/orderRelation";
import { getStageById } from "../../domain/productionWorkflow";
import { formatMeasurements, formatMeters } from "../../domain/productFormat";
import { ProductionRoadmap } from "../../components/ProductionRoadmap";
import { OrderActionPanel } from "./OrderActionPanel";

const relationTime = new Intl.DateTimeFormat("ro-RO", { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });

export function OrderDetailScreen({ orderId, service, workflow, navigate, onSessionExpired }: { orderId: string; service: OrderService; workflow: ProductionWorkflow; navigate: (path: string) => void; onSessionExpired: () => void }) {
  const [order, setOrder] = useState<StaffOrder | null>(null);
  const [error, setError] = useState<StaffServiceError | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    service.getById(orderId, { signal: controller.signal })
      .then(requireProductionProducts)
      .then((loaded) => { setError(null); setOrder(loaded); })
      .catch((caught) => { if (!controller.signal.aborted) setError(toServiceError(caught)); });
    return () => controller.abort();
  }, [orderId, reloadKey, service]);
  if (error) return <ErrorState error={error} onAction={() => { setError(null); setOrder(null); if (error.code === "ORDER_NOT_FOUND") navigate("/orders"); else setReloadKey((value) => value + 1); }} />;
  if (!order) return <div className="inline-loading"><span /> Se încarcă detaliile…</div>;
  const completed = Boolean(order.productionCompletedAt);
  return (
    <article className="screen-stack detail-screen">
      <button className="back-link" type="button" onClick={() => navigate("/orders")}><AppIcon name="back" size={20} /> Comenzile mele</button>
      <section className="detail-hero"><div><SourceBadge source={order.source} /><h1>Comanda<br />#{order.orderNumber}</h1></div>{completed ? <span className="stage-label"><span>✓</span>Producție finalizată</span> : <StageLabel stage={getStageById(workflow, order.productionStageId)} />}</section>
      <OrderNotices order={order} />
      {order.productionContext && <section className="detail-section"><p className="eyebrow">Companie B2B · date istorice</p>
        <h2>{order.productionContext.company.legalName}</h2><p>{order.productionContext.company.companyCode} · {order.productionContext.company.countryCode} · {order.productionContext.company.taxIdentifier}</p>
        {projectsOf(order).map(project => <p className="project-reference" key={project}>Proiect {project}</p>)}
      </section>}
      <OrderActionPanel order={order} workflow={workflow} service={service} onUpdated={(updated) => setOrder(requireProductionProducts(updated))} onReload={() => { setOrder(null); setReloadKey((value) => value + 1); }} onSessionExpired={onSessionExpired} />
      <ProductionRoadmap workflow={workflow} currentStageId={order.productionStageId} />
      <section className="detail-section detail-products"><div className="section-title"><p className="eyebrow">Producție</p><h2>{order.products.length === 1 ? "1 produs" : `${order.products.length} produse`}</h2></div>{order.products.map((item) => { const size = formatMeasurements(item); const location = item.productionContext?.project; return <div className="product-detail" key={item.id}>{location && <p className="project-location" data-testid="project-location">{projectPlace(location)}</p>}<div><strong>{item.name}</strong>{item.code && <span>{item.code}</span>}</div><dl>{item.color && <div><dt>Culoare</dt><dd>{item.color}</dd></div>}{item.variant && <div><dt>Variantă</dt><dd>{item.variant}</dd></div>}{size && <div><dt>Dimensiune</dt><dd>{size}</dd></div>}{item.meters != null && <div><dt>Metri</dt><dd>{formatMeters(item.meters)}</dd></div>}<div><dt>Cantitate</dt><dd>{item.quantity}</dd></div></dl></div>; })}</section>
      {order.productionNotes && <section className="production-note"><p className="eyebrow">Notă de producție</p><p>{order.productionNotes}</p></section>}
      {order.products.filter(item => item.productionContext).map(item => <section className="production-note" key={item.id}>
        <p className="eyebrow">{item.name} · {item.productionContext!.project ? projectTreatment(item.productionContext!.project) : { curtain: 'Perdea', drapery: 'Draperie', other: 'Alt produs' }[item.productionContext!.kind]}</p>
        {item.productionContext!.project && <p className="project-opening">{projectOpening(item.productionContext!.project)}</p>}
        {item.productionContext!.notes && <p>{item.productionContext!.notes}</p>}
        {item.productionContext!.productionNotes && <p>{item.productionContext!.productionNotes}</p>}
      </section>)}
      {order.sourceCommerceStatus && <p className="commerce-status">{order.source === 'b2b' ? 'Stare comercială' : 'Stare magazin'}: <b>{order.sourceCommerceStatus.label}</b> <small>(nu schimbă etapa de producție)</small></p>}
      <section className="detail-section"><p className="eyebrow">Implicarea mea</p><div className="mini-timeline"><span /><div><strong>{order.employeeRelation ? getEmployeeRelationLabel(order) : "Încă nu ai lucrat la această comandă"}</strong>{order.employeeRelation && <time dateTime={getEmployeeRelationTime(order)}>{relationTime.format(new Date(getEmployeeRelationTime(order)))}</time>}</div></div></section>
    </article>
  );
}

type Location = NonNullable<NonNullable<StaffOrder["products"][number]["productionContext"]>["project"]>;
const cm = (value: string | null) => value === null ? "—" : (value.includes(".") ? value.replace(/0+$/, "").replace(/\.$/, "") : value).replace(".", ",");
const ZONE = { floor: "Etaj", zone: "Zonă" } as const;
const TREATMENT = { sheer: "Perdea", drapery: "Draperie", blackout: "Draperie blackout", rail: "Șină / galerie", accessory: "Accesoriu", other: "Alt produs" } as const;
const LAYOUT = { single: "un panou", pair: "pereche", left: "stânga", right: "dreapta" } as const;
const MOUNTING = { ceiling: "montaj tavan", wall: "montaj perete", recess: "montaj în nișă" } as const;
/** "Etaj 1 · Camera 101 · Fereastra 2" — the level is added only when the zone name does not already carry it. */
function projectPlace(location: Location): string {
  const zone = location.zone.level !== null && !new RegExp(`(^|\\D)${location.zone.level}(\\D|$)`).test(location.zone.name)
    ? `${ZONE[location.zone.zoneType]} ${location.zone.level} · ${location.zone.name}` : location.zone.name;
  return [zone, location.zone.building, location.room.name, location.opening.name].filter(Boolean).join(" · ");
}
function projectTreatment(location: Location): string {
  return TREATMENT[location.treatment.treatmentType] + (location.treatment.panelLayout ? ` · ${LAYOUT[location.treatment.panelLayout]}` : "");
}
function projectOpening(location: Location): string {
  const o = location.opening;
  return [`${projectPlace(location)}`, `gol ${cm(o.width)} × ${cm(o.height)} cm`, o.sillHeight !== null ? `parapet ${cm(o.sillHeight)} cm` : null,
    o.mounting ? MOUNTING[o.mounting] : null, o.railType ? `șină ${o.railType}` : null].filter(Boolean).join(" · ");
}
function projectsOf(order: StaffOrder): string[] {
  return [...new Set(order.products.flatMap(item => item.productionContext?.project ? [`${item.productionContext.project.projectCode} · ${item.productionContext.project.projectName}`] : []))];
}
