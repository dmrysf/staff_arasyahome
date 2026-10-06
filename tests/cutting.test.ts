import assert from "node:assert/strict";
import { test } from "node:test";
import { boardPageSize, boardTone, visibleProductCodes } from "../domain/cuttingPresentation";
import { createProductionServices } from "../services/production/httpServices";
import { parseSse } from "../services/production/liveClient";
import { nextLiveNotice } from "../domain/faults";
test("generic pool invalidations never erase existing fault or transfer notices",()=>{
  const fault={seq:9,type:"exception.acknowledgment_required",orderNumber:"100",exceptionId:"fault"};
  assert.equal(nextLiveNotice(fault,{seq:10,type:"cutting.changed"}),fault);
  assert.equal(nextLiveNotice(null,{seq:10,type:"cutting.changed"}),null);
  const transfer={seq:11,type:"cutting.transfer.approved",orderNumber:"100",transferId:"transfer"};
  assert.equal(nextLiveNotice(fault,transfer),transfer);
});
test("sanitized empty-object invalidations are consumed by the accepted SSE parser",()=>{
  assert.deepEqual(parseSse('id: 9\nevent: cutting.changed\ndata: {}\n\n').map(f=>[f.id,f.event,f.data]),[[9,"cutting.changed",{}]]);
});

test("cutting time colors are neutral exact boundaries; a blocked worker never escalates", () => {
  assert.deepEqual([0,899,900,1799,1800,3599,3600].map(s => boardTone(s,[15,30,60],false)),["normal","normal","attention","attention","warning","warning","severe"]);
  for (const seconds of [0,900,3600,1000000]) assert.equal(boardTone(seconds,[15,30,60],true),"blocked");
});
test("all product codes and all active assignments have an automatic readable page", () => {
  const codes = Array.from({length:20},(_,i)=>`PRODUCT-${i}`);
  const seen = Array.from({length:10},(_,i)=>visibleProductCodes(codes.join(" · "),i*8000).codes.split(" · ")).flat();
  assert.deepEqual(seen,codes); assert.equal(visibleProductCodes("",0).pages,1);
  assert.equal(boardPageSize(1920,1080),8); assert.equal(boardPageSize(1280,720),4); assert.equal(boardPageSize(640,720),4);
});
test("cutting client preserves QR, exact count, explicit intent and idempotency, with no optimistic ownership", async () => {
  const calls: { path: string; init: RequestInit }[] = [];
  const fetchImpl = (async (url: string,init: RequestInit) => { calls.push({path:new URL(url).pathname,init}); return new Response(JSON.stringify({items:[],ownedCount:2,total:0}),{status:200,headers:{"Content-Type":"application/json"}}); }) as typeof fetch;
  const api = createProductionServices("https://api.arasyahome.ro",{fetchImpl,isOnline:()=>true}).cutting!;
  await api.pool(); await api.change("transfer","verify",{expectedVersion:3,qrToken:"ARASYA:Q1:LABEL",ownedCount:2,confirmedMultiple:true},"stable-network-retry-key");
  assert.equal(calls[1].path,"/cutting/transfers/transfer/verify");
  assert.equal(calls[1].init.credentials,"include");
  assert.deepEqual(JSON.parse(calls[1].init.body as string),{expectedVersion:3,qrToken:"ARASYA:Q1:LABEL",ownedCount:2,confirmedMultiple:true});
  assert.equal(new Headers(calls[1].init.headers).get("Idempotency-Key"),"stable-network-retry-key");
});
