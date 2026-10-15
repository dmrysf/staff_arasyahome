import { useRef, useState } from "react";
import type { StaffOrder, StaffServiceError } from "../../domain/models";
import type { OrderService } from "../../services/contracts";
import { ErrorState } from "../../components/ErrorState";
import { toServiceError } from "../../services/errors";
import { createIdempotencyKey } from "../../services/idempotency";
import { useI18n } from "../../i18n";

/** A physical line/quantity acknowledgement by the authenticated current owner, never an optimistic success. */
export function LineCheckPanel({order,service,onUpdated,onSessionExpired}:{order:StaffOrder;service:OrderService;onUpdated:(order:StaffOrder)=>void;onSessionExpired:()=>void}) {
  const {t}=useI18n();const flight=useRef(false);const retry=useRef<{id:string;qty:number;version:number;key:string}|null>(null);
  const [busy,setBusy]=useState(false);const [error,setError]=useState<StaffServiceError|null>(null);const [quantities,setQuantities]=useState<Record<string,number>>({});
  const view=order.lineReadiness;if(!view) return null;
  const owner=order.employeeAllowedAction?.id==='complete_stage'||order.employeeAllowedAction?.id==='complete_production';
  const stage=order.productionStageId;
  async function confirm(id:string,qty:number) {
    if(flight.current||!service.confirmLines) return;
    const pending=retry.current??{id,qty,version:order.productionVersion,key:createIdempotencyKey()};retry.current=pending;
    flight.current=true;setBusy(true);setError(null);
    try{onUpdated(await service.confirmLines(order.id,{expectedVersion:pending.version,items:[{itemId:pending.id,completedQuantity:pending.qty}],idempotencyKey:pending.key}));retry.current=null;}
    catch(e){const caught=toServiceError(e);setError(caught);if(['SESSION_EXPIRED','NO_SESSION'].includes(caught.code)) onSessionExpired();if(!['NETWORK_UNAVAILABLE','REQUEST_TIMEOUT','SERVER_ERROR','SERVICE_UNAVAILABLE'].includes(caught.code)) retry.current=null;}
    finally{flight.current=false;setBusy(false);}
  }
  return <section className="detail-section" data-testid="line-checks"><h2>{t('lines.title')}</h2><p role="status">{t(view.readyForPacking?'lines.ready':'lines.unfinished')}</p>
    {view.lines.map(line=>{const item=order.products.find(p=>p.id===line.itemId);const done=line.completedQuantities[stage]??0;
      return <div className="product-detail" key={line.itemId}><strong>{item?.name}</strong><p>{t(line.manufacturingType==='ready_made'?'lines.standard':'lines.fabric')} · {done}/{line.quantity} · {line.packed?t('lines.packed'):line.readyForPacking?t('lines.ready'):t('lines.unfinished')}</p>
        {owner&&line.requiredAtCurrentStage&&service.confirmLines&&done<line.quantity&&<div><label>{t('lines.quantity')}<input aria-label={`${t('lines.quantity')} ${item?.name}`} type="number" min={1} max={line.quantity} value={quantities[line.itemId]??line.quantity} disabled={busy||Boolean(error)} onChange={e=>setQuantities({...quantities,[line.itemId]:Number(e.target.value)})}/></label><button className="button button-secondary" disabled={busy||Boolean(error)} onClick={()=>void confirm(line.itemId,quantities[line.itemId]??line.quantity)}>{t(stage==='packing'?'lines.pack':'lines.confirm')}</button></div>}
      </div>;})}
    {error&&<ErrorState error={error} compact onAction={()=>{const p=retry.current;if(p) void confirm(p.id,p.qty);else location.reload();}}/>}
  </section>;
}
