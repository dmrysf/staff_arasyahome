import { expect, test, type Page, type Browser, type Locator, request } from "@playwright/test";
import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { translate, type Locale } from "../../i18n";
const file=process.env.ARASYA_SIMULATION_MANIFEST!;
const manifest=JSON.parse(readFileSync(file,"utf8")) as {accounts:Record<string,{username:string;password:string;locale:Locale;employeeUuid:string}>;root:{username:string;password:string}};
const API=process.env.ARASYA_SIMULATION_API_URL??"http://127.0.0.1:8788";
const STAFF=process.env.ARASYA_SIMULATION_STAFF_URL??"http://127.0.0.1:4175";
// The real Dashboard, built in its `simulation-e2e` mode (simulation banner, loopback simulation API only).
const DASHBOARD=process.env.ARASYA_SIMULATION_DASHBOARD_URL??"http://127.0.0.1:4173";
async function dashboardLogin(page:Page,account:{username:string;password:string}){await page.goto(`${DASHBOARD}/`);await page.getByLabel('Nume utilizator').fill(account.username);await page.getByLabel('Parolă',{exact:true}).fill(account.password);await page.getByRole('button',{name:'Intră în cont'}).click();}
/** The simulation banner is visible, first in the page and never covered by (or covering) the sticky layout. */
async function simulationBanner(page:Page){const banner=page.locator('[data-arasya-simulation="banner"]');await expect(banner).toBeVisible();await expect(banner).toHaveAttribute('role','note');
 const box=(await banner.boundingBox())!;expect(box.y).toBe(0);expect(box.height).toBeGreaterThan(20);expect(await page.title()).toMatch(/^(SIMULARE|SİMÜLASYON) · /);
 for(const selector of ['.topbar','.sidebar']){const layer=page.locator(selector);if(await layer.count()&&await layer.isVisible()){const other=(await layer.boundingBox())!;expect(other.y,selector).toBeGreaterThanOrEqual(box.y+box.height-0.5);}}
 return banner;}
async function resetFixture(scenario:string) {
 const ctx=await request.newContext({baseURL:API,extraHTTPHeaders:{Origin:STAFF}});const a=manifest.accounts.controller;
 const login=await ctx.post('/auth/login',{data:{username:a.username,password:a.password}});expect(login.status()).toBe(200);const session=await login.json();
 const overview=await (await ctx.get('/simulation/overview')).json();const replay=overview.runs.find((r:{scenario_id:string;replay_number:number})=>r.scenario_id===scenario).replay_number;
 const reset=await ctx.post(`/simulation/scenarios/${scenario}/reset`,{data:{expectedReplay:replay},headers:{'X-CSRF-Token':session.csrfToken,'Idempotency-Key':`browser-reset-${scenario}-${replay}`}});expect(reset.status()).toBe(200);await ctx.dispose();
}
const fixture=()=>JSON.parse(execFileSync("php",["simulation/browser-fixtures.php",file],{encoding:"utf8",stdio:["ignore","pipe","pipe"]})) as Record<string,{id:string;number:string;qr:string}>;
async function worker(browser:Browser,name:string,width=390) {
  const context=await browser.newContext({viewport:{width,height:844}});context.setDefaultTimeout(15000);const page=await context.newPage();const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(`${STAFF}/login`);await page.getByLabel("Nume utilizator").fill(manifest.accounts[name].username);await page.getByLabel("Parolă").fill(manifest.accounts[name].password);await page.getByRole('button',{name:/Autentificare/}).click();await expect(page.locator('.app-main')).toBeVisible();
  return {context,page,errors,locale:manifest.accounts[name].locale};
}
async function capture(section:Locator,token:string,locale:Locale) {
  await section.getByRole('button',{name:translate(locale,'qr.manual'),exact:true}).click();await section.locator('.qr-manual input').fill(token);await section.getByRole('button',{name:translate(locale,'qr.verify'),exact:true}).click();
}
async function order(page:Page,id:string){await page.goto(`${STAFF}/orders/${encodeURIComponent(id)}`);await expect(page.locator('.detail-hero')).toBeVisible();}
async function action(page:Page,qr:string,locale:Locale){await page.locator('.order-action-panel > button').click();const dialog=page.getByRole('dialog');if(await dialog.locator('.qr-capture').count()) await capture(dialog,qr,locale);if(await dialog.getByRole('checkbox').count()) await dialog.getByRole('checkbox').check();await dialog.locator('.confirmation-actions .button-primary').click();await expect(dialog).toHaveCount(0);}
async function checks(page:Page){const panel=page.getByTestId('line-checks');while(await panel.getByRole('button').count()){const before=await panel.getByRole('button').count();await panel.getByRole('button').first().click();await expect(panel.getByRole('button')).toHaveCount(before-1);}}
async function finishStage(page:Page,qr:string,locale:Locale){await checks(page);await action(page,qr,locale);}
const stages=['waiting','material-preparation','workshop-receiving','labeling','material-straightening','bottom-hem','side-hem','ironing','height','header-tape','sewing-finishing','quality-control','packing','delivery'];

test('real Staff workers → stage-5 defect → appointed supervisor custody → cutter QR receipt → real Dashboard manager → rework → checked mixed packing',async({browser})=>{
 test.skip(process.env.ARASYA_SIMULATION_DASHBOARD!=="enabled","Real Dashboard checkout/runtime is required; do not substitute controller approvals.");
 test.setTimeout(180000);
 await resetFixture('WOO-TH-002');const f=fixture()['WOO-TH-002'];const users=new Map<string,Awaited<ReturnType<typeof worker>>>();
 for(const name of [...stages,'tailoring-supervisor']) users.set(name,await worker(browser,name));
 for(const name of stages.slice(0,5)) {const u=users.get(name)!;await order(u.page,f.id);await action(u.page,f.qr,u.locale);if(name!=='material-straightening') await finishStage(u.page,f.qr,u.locale);}
 const tailor=users.get('material-straightening')!;await tailor.page.getByRole('button',{name:'Raportează o problemă',exact:true}).click();const dialog=tailor.page.getByRole('dialog');await dialog.getByRole('checkbox').first().check();await dialog.getByLabel('Problema observată').selectOption('cut-meterage');await dialog.getByLabel(/Descriere/).fill('TEST stage 5 defect, real people chain');await dialog.getByRole('button',{name:'Raportează șefului de croitorie'}).click();await expect(tailor.page.getByTestId('exception-status')).toContainText('Așteaptă verificarea');
 const exceptionId=decodeURIComponent(new URL(tailor.page.url()).pathname.split('/').pop()!);const sup=users.get('tailoring-supervisor')!;await sup.page.goto(`${STAFF}/exceptions/${exceptionId}`);const review=sup.page.getByTestId('supervisor-review');await review.getByLabel('Motivul confirmat').selectOption('cut-meterage');await capture(review,f.qr,'ro');await expect(sup.page.getByTestId('exception-status')).toHaveText('În grija șefului de croitorie');
 await sup.page.getByTestId('supervisor-return').getByRole('button',{name:'Trimite la tăiere'}).click();
 const cutter=users.get('material-preparation')!;await cutter.page.goto(`${STAFF}/exceptions/${exceptionId}`);const receipt=cutter.page.getByTestId('cutter-receipt');await receipt.getByRole('checkbox').check();await receipt.getByRole('radio').first().check();await capture(receipt,f.qr,cutter.locale);await expect(receipt).toHaveCount(0);
 // A worker signed in to the same simulation Dashboard gets no management screen and cannot decide.
 const workerDash=await browser.newContext({viewport:{width:390,height:844}});const workerPage=await workerDash.newPage();await dashboardLogin(workerPage,manifest.accounts['labeling']);await expect(workerPage.getByRole('heading',{name:'Nu ai acces la Panoul de control.'})).toBeVisible();await simulationBanner(workerPage);
 await workerPage.goto(`${DASHBOARD}/aprobari/cerere/${exceptionId}`);await expect(workerPage.getByRole('button',{name:'Aprobă returnarea la tăiere',exact:true})).toHaveCount(0);await workerDash.close();
 const dashContext=await browser.newContext({viewport:{width:390,height:844}});dashContext.setDefaultTimeout(15000);const manager=await dashContext.newPage();await dashboardLogin(manager,manifest.accounts.manager);await expect(manager.getByRole('button',{name:'Ieși din cont'})).toBeVisible();await simulationBanner(manager);
 await manager.goto(`${DASHBOARD}/aprobari/cerere/${exceptionId}`);await simulationBanner(manager);await manager.getByRole('button',{name:'Aprobă returnarea la tăiere',exact:true}).click();const decision=manager.getByRole('dialog');await decision.getByRole('radio',{name:/Eroare de tăiere stabilită/}).check();await decision.getByRole('button',{name:'Aprobă returnarea la tăiere',exact:true}).click();await expect(decision).toHaveCount(0);
 // The decision is audited against the manager's own identity.
 const audit=await request.newContext({baseURL:API,extraHTTPHeaders:{Origin:STAFF}});expect((await audit.post('/auth/login',{data:manifest.root})).status()).toBe(200);
 const approved=await (await audit.get(`/management/audit?action=production.exception.approved&targetId=${exceptionId}`)).json() as {items:{actorId:string;targetId:string}[]};expect(approved.items.map(i=>[i.actorId,i.targetId])).toEqual([[manifest.accounts.manager.employeeUuid,exceptionId]]);await audit.dispose();
 await manager.screenshot({path:'work/organization-dashboard-mobile.png',fullPage:true});expect(await manager.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
 await order(cutter.page,f.id);await expect(cutter.page.getByTestId('line-checks')).toContainText(translate(cutter.locale,'lines.unfinished'));await finishStage(cutter.page,f.qr,cutter.locale);
 for(const name of stages.slice(2)) {const u=users.get(name)!;await order(u.page,f.id);await action(u.page,f.qr,u.locale);if(name==='packing') {await expect(u.page.getByTestId('line-checks').locator('.product-detail')).toHaveCount(2);await u.page.screenshot({path:'work/organization-mixed-packing-mobile.png',fullPage:true});}await finishStage(u.page,f.qr,u.locale);expect(await u.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);}
 const completed=await users.get('delivery')!.page.request.get(`${API}/orders/${encodeURIComponent(f.id)}`);const final=await completed.json();expect(final.productionCompletedAt).toBeTruthy();expect(final.lineReadiness.lines.every((l:{packed:boolean})=>l.packed)).toBe(true);
 for(const u of users.values()){expect(u.errors).toEqual([]);await u.context.close();}await dashContext.close();
});

test('simulation Dashboard: banner on phone and desktop, in the interface language, worker refused',async({browser})=>{
 test.skip(process.env.ARASYA_SIMULATION_DASHBOARD!=="enabled","Real Dashboard checkout/runtime is required.");
 for(const width of [360,1280]){const context=await browser.newContext({viewport:{width,height:800}});const page=await context.newPage();const errors:string[]=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(`${DASHBOARD}/`);const banner=await simulationBanner(page);await expect(banner).toContainText('MEDIU DE SIMULARE');await expect(banner).toContainText('Date de test');
  await page.getByRole('button',{name:'TR — Türkçe'}).click();await expect(banner).toContainText('SİMÜLASYON ORTAMI');await expect(banner).toContainText('Test Verileri');await page.getByRole('button',{name:'RO — Română'}).click();
  await dashboardLogin(page,manifest.accounts.manager);await expect(page.getByRole('button',{name:'Ieși din cont'})).toBeVisible();await simulationBanner(page);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.screenshot({path:`work/simulation-dashboard-banner-${width}.png`});
  await page.goto(`${DASHBOARD}/?simulation=0`);await simulationBanner(page);expect(errors).toEqual([]);await context.close();}
});

test('ready-made-only products skip irrelevant manufacturing and cannot leave packing without every product check',async({browser})=>{
 test.setTimeout(90000);await resetFixture('WOO-OP-002');const f=fixture()['WOO-OP-002'];const wait=await worker(browser,'waiting');await order(wait.page,f.id);await action(wait.page,f.qr,'ro');await action(wait.page,f.qr,'ro');const pack=await worker(browser,'packing');await order(pack.page,f.id);await action(pack.page,f.qr,pack.locale);await expect(pack.page.getByTestId('line-checks').locator('.product-detail')).toHaveCount(3);await action(pack.page,f.qr,pack.locale);await expect(pack.page.getByRole('alert')).toContainText(translate(pack.locale,'error.LINES_UNFINISHED.message'));await pack.page.reload();await expect(pack.page.locator('.detail-hero')).toBeVisible();await finishStage(pack.page,f.qr,pack.locale);await wait.context.close();await pack.context.close();
});
