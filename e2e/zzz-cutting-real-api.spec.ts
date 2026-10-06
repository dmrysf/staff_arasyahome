import { expect, request, test, type Browser, type Page } from "@playwright/test";
import { readFileSync } from "node:fs";
import path from "node:path";
type Fixture={password:string;qr:Record<string,string>;exceptions:{manager:string};cutting:{order:string;source:string;owner:string;target:string;root:{username:string;password:string}}};
const fixture=JSON.parse(readFileSync(path.join(import.meta.dirname,".real-api-fixture.json"),"utf8")) as Fixture;
const API="http://127.0.0.1:8787",ORIGIN="http://127.0.0.1:4174";
async function apiUser(username:string,password=fixture.password){
  const api=await request.newContext({baseURL:API,extraHTTPHeaders:{Origin:ORIGIN}});
  const response=await api.post("/auth/login",{data:{username,password}});expect(response.status()).toBe(200);const csrf=(await response.json()).csrfToken;
  const mutate=async(method:"post"|"put",url:string,body:unknown)=>{const r=await api[method](url,{data:body,headers:{"X-CSRF-Token":csrf,"Idempotency-Key":`cutting-e2e-${Date.now()}-${Math.random().toString(16).slice(2)}`}});expect(r.status()).toBeLessThan(300);return r.json();};
  return {api,mutate};
}
async function employee(browser:Browser,username:string){const context=await browser.newContext({viewport:{width:390,height:844},reducedMotion:"reduce"});const page=await context.newPage();await page.goto("/login");await page.getByLabel("Nume utilizator").fill(username);await page.getByLabel("Parolă").fill(fixture.password);await page.getByRole("button",{name:/Autentificare/}).click();await expect(page.getByRole("heading",{name:/^Bună,/})).toBeVisible();return {context,page};}
const overflow=(page:Page)=>page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth);

test("whole-order transfer: approval leaves owner, target accepts then same QR, Staff and TV converge without reload",async({browser})=>{
  test.setTimeout(150000);
  const root=await apiUser(fixture.cutting.root.username,fixture.cutting.root.password);
  const days=Array.from({length:7},(_,i)=>({weekday:i+1,isOpen:true,opensAt:"00:00",closesAt:"23:59"}));
  await root.mutate("put","/management/organization/working-hours",{days});
  const tvContext=await browser.newContext({viewport:{width:1920,height:1080},reducedMotion:"reduce"});const tv=await tvContext.newPage();const errors:string[]=[];tv.on("pageerror",e=>errors.push(e.message));
  await tv.goto("/cutting-board");await expect(tv.getByLabel("Cod de asociere")).toBeVisible();await expect(tv.getByRole("heading",{name:/Bine ai revenit/})).toHaveCount(0);
  const device=await root.mutate("post","/management/cutting/devices",{name:"TV test numai citire"});
  await tv.getByLabel("Cod de asociere").fill(device.pairingCode);await tv.getByRole("button",{name:"Asociază dispozitivul",exact:true}).click();
  await expect(tv.getByTestId("cutting-board")).toBeVisible();await expect(tv.locator(".display-overflow")).toContainText("comenzi în așteptare");
  const countersBefore=(await (await tv.request.get(`${API}/display/cutting/snapshot`)).json()).counters;
  expect(await tv.locator(".display-waiting-grid article").count()).toBe(20);
  await tv.evaluate(()=>{(window as unknown as {__cuttingNoReload:boolean}).__cuttingNoReload=true;});
  const owner=await employee(browser,fixture.cutting.owner),target=await employee(browser,fixture.cutting.target);
  const orderId=`${fixture.cutting.source}:${fixture.cutting.order}`;
  await owner.page.goto(`/orders/${encodeURIComponent(orderId)}`);await owner.page.getByRole("button",{name:"Solicită transferul întregii comenzi"}).click();
  await owner.page.getByLabel("Coleg din același departament").selectOption({label:"Andrea Tăiere"});
  await owner.page.getByLabel("Motiv",{exact:true}).selectOption("illness");await owner.page.getByRole("button",{name:"Confirm și trimit cererea"}).click();
  const manager=await apiUser(fixture.exceptions.manager);const pending=await (await manager.api.get("/management/cutting/transfers?view=pending")).json();const transfer=pending.items.find((t:{order:{id:string}})=>t.order.id===orderId);
  expect(transfer.from.name).toBe("Murat Tăiere");await manager.mutate("post",`/management/cutting/transfers/${transfer.id}/decision`,{expectedVersion:transfer.version,decision:"approve"});
  await expect(target.page.locator(".cutting-transfer-card")).toContainText("Aprobat",{timeout:15000});
  await expect(target.page.getByRole("button",{name:"Accept explicit transferul"})).toBeVisible();await target.page.getByRole("button",{name:"Accept explicit transferul"}).click();
  const card=target.page.locator(".cutting-transfer-card");await card.getByRole("button",{name:"Introdu codul de pe etichetă"}).click();await card.getByLabel(/Cod etichetă/).fill(fixture.qr[fixture.cutting.order]);await card.getByRole("button",{name:"Verifică codul",exact:true}).click();await card.getByRole("button",{name:"Verifică QR și preia comanda"}).click();
  await expect(card).toContainText("Transfer finalizat");
  // Target ownership and all old work remain visible, with automatic pagination instead of hidden cards.
  await tv.bringToFront();
  await expect(tv.locator(".display-order").filter({hasText:`#${fixture.cutting.order}`})).toContainText("Andrea Tăiere",{timeout:20000});
  await target.page.goto(`/orders/${encodeURIComponent(orderId)}`);await target.page.getByRole("button",{name:/Finalizează etapa/}).click();await target.page.getByRole("dialog").getByRole("button",{name:"Confirmă",exact:true}).click();await expect(target.page.getByText("✓ Comanda a fost predată")).toBeVisible();
  await tv.bringToFront();
  await expect(tv.locator(".display-counters strong").nth(0)).toHaveText(String(countersBefore.completedOrders+1),{timeout:15000});
  await expect(tv.locator(".display-counters")).toContainText("Metri finalizați astăzi");
  for(const [width,height] of [[1920,1080],[1280,720],[1024,768]]){
    await tv.setViewportSize({width,height});await tv.waitForTimeout(1100);expect(await overflow(tv)).toBeLessThanOrEqual(0);
    const geometry=await tv.evaluate(()=>({pageScroll:document.documentElement.scrollHeight-document.documentElement.clientHeight,cards:[...document.querySelectorAll<HTMLElement>(".display-order")].map(el=>el.scrollHeight-el.clientHeight)}));
    expect(geometry.pageScroll,`TV scroll at ${width}`).toBeLessThanOrEqual(0);for(const clipping of geometry.cards)expect(clipping,`card clipping at ${width}`).toBeLessThanOrEqual(1);
    await tv.screenshot({path:`e2e/.runtime/cutting-board-${width}.png`});
  }
  for(const width of [360,390,768,1024,1440]) {await owner.page.setViewportSize({width,height:900});expect(await overflow(owner.page)).toBeLessThanOrEqual(0);}
  expect(await tv.evaluate(()=>[Object.keys(localStorage).filter(k=>/token|session|csrf|pair/i.test(k)),Object.keys(sessionStorage).filter(k=>/token|session|csrf|pair/i.test(k)),document.querySelectorAll("audio").length])).toEqual([[],[],0]);
  expect(await tv.evaluate(()=>(window as unknown as {__cuttingNoReload:boolean}).__cuttingNoReload)).toBe(true);
  await root.mutate("put","/management/organization/working-hours",{days:days.map(d=>({weekday:d.weekday,isOpen:false}))});await expect(tv.getByRole("heading",{name:"În afara programului de lucru"})).toBeVisible({timeout:15000});
  await root.mutate("post",`/management/cutting/devices/${device.id}/revoke`,{confirmed:true});await expect(tv.getByLabel("Cod de asociere")).toBeVisible({timeout:15000});
  await root.mutate("put","/management/organization/working-hours",{days:days.map(d=>d.weekday===7?{weekday:7,isOpen:false}:{weekday:d.weekday,isOpen:true,opensAt:"05:00",closesAt:"20:00"})});
  expect(errors).toEqual([]);await tvContext.close();await owner.context.close();await target.context.close();await root.api.dispose();await manager.api.dispose();
});
