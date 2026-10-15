import { physicalText as pt } from "../i18n/physical";
import { useEffect, useRef, useState } from "react";
import { isAmbiguous, physicalError, planDecimal, type ManufacturingPlan, type PhysicalApi, type PhysicalHistory, type PlanConfirmation, type PlanLine, type ProductType, type RouteEvidence, type RouteEvidenceInput } from "../domain/physical";

/** Source evidence is displayed for human confirmation. It never sets a piece count or divides fabric. */
export function ManufacturingPanel({ orderId, service, revision, newKey, stageName, onChanged }: {
  orderId: string; service: PhysicalApi; revision: number; newKey: () => string; stageName: (id: string) => string; onChanged: () => void;
}) {
  const [plan, setPlan] = useState<ManufacturingPlan | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refresh, setRefresh] = useState(0);
  const [editing, setEditing] = useState<PlanLine | null>(null);
  const [selected, setSelected] = useState<{ id: string; label: string } | null>(null);
  const [verifying, setVerifying] = useState<{ id: string; label: string; evidence: RouteEvidence } | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    service.plan(orderId, controller.signal).then(value => { setPlan(value); setError(null); }, caught => {
      if (!controller.signal.aborted) setError(physicalError(caught));
    });
    return () => controller.abort();
  }, [orderId, service, revision, refresh]);
  return <section className="detail-section card manufacturing-panel" data-testid="manufacturing-plan">
    <h2>{pt("physical.plan_fizic_si_progres_pe_bucati")}</h2>
    <p>{pt("physical.cantitatea_comerciala_si_numarul_fizic_de_bucati")}</p>
    <button className="button button-secondary" type="button" onClick={() => { setSelected(null); setRefresh(v => v + 1); onChanged(); }}>{pt("physical.reincarca_starea_salvata")}</button>
    {error && <p role="alert">{error}</p>}
    {!plan && !error && <p role="status">{pt("physical.se_incarca_planul")}</p>}
    {plan && !plan.allPlansKnown && <p className="order-notice notice notice-warning" role="note">{pt("physical.numar_de_bucati_neconfirmat_ambalarea_necesita_verificarea")}</p>}
    {plan?.lines.map(line => <article key={line.itemId} className="piece-track card" data-testid="plan-line">
      <h3>{[line.code, line.color, line.name].filter(Boolean).join(" · ")}</h3>
      <dl className="fault-facts facts">
        <dt>{pt("physical.cantitate_comerciala")}</dt><dd>{line.commercialQuantity}</dd>
        <dt>{pt("physical.bucati_fizice")}</dt><dd>{line.planKnown ? line.plan.pieceCount : pt("physical.neconfirmat")}</dd>
        <dt>{pt("physical.tip")}</dt><dd>{line.plan.productType === "unknown" ? pt("physical.necunoscut_necesita_verificare") : line.plan.productType.toUpperCase()}{!line.typeVerified ? " · neconfirmat" : ""}</dd>
        <dt>{pt("physical.masuri_sursa")}</dt><dd>{line.lineMeasurements.width ?? pt("physical.necunoscut")} × {line.lineMeasurements.height ?? pt("physical.necunoscut")} {line.lineMeasurements.unit ?? ""}</dd>
        <dt>{pt("physical.metraj_total_sursa")}</dt><dd>{line.lineMeters === null ? pt("physical.necunoscut") : `${line.lineMeters} m`}</dd>
        <dt>{pt("physical.etape_aplicabile")}</dt><dd>{line.route.map(stageName).join(" · ")}</dd>
        {(line.assistedStages?.length ?? 0) > 0 && <><dt>{pt("physical.history.assisted")}</dt><dd>{line.assistedStages!.map(stageName).join(" · ")}</dd></>}
        {line.notApplicableStages.length > 0 && <><dt>{pt("physical.nu_se_aplica")}</dt><dd>{line.notApplicableStages.map(stageName).join(" · ")}{pt("physical.fara_timp_sau_puncte_de_productie")}</dd></>}
      </dl>
      {line.staleAfterSourceChange && <p role="note">{pt("physical.datele_sursei_sau_schimbat_verifica_din_nou")}</p>}
      {line.proposal.evidence.length > 0 && <ul>{line.proposal.evidence.map((item, i) => <li key={i}>{item.value}</li>)}</ul>}
      {line.plan.pieces.map(piece => <p key={piece.index}>{pt("physical.bucata")}{piece.index}/{line.plan.pieceCount}: {piece.width ?? pt("physical.necunoscut")} × {piece.height ?? pt("physical.necunoscut")} {piece.unit ?? ""} · {(piece.fabricMeters ?? piece.fabric) == null ? pt("physical.metraj_necunoscut") : `${piece.fabricMeters ?? piece.fabric} m`}</p>)}
      {line.pieces.map(piece => <PiecePosition key={piece.id} label={`${pt("physical.bucata")}${piece.index}/${piece.count}`} unitId={piece.id} service={service} revision={revision + refresh} stageName={stageName} />)}
      {line.pieces.length === 0 && <p>{pt("physical.identitati_fizice_neconfirmate_lista_goala_nu_inseamna")}</p>}
      {line.pieces.map(piece => piece.routeEvidence && piece.routeEvidence.missingStages.length > 0 && <div key={`evidence-${piece.id}`} className="order-notice notice notice-warning" role="note" data-testid="route-evidence-missing">
        <p>{pt("physical.evidence.missing", { piece: `${piece.index}/${piece.count}`, stages: piece.routeEvidence.missingStages.map(stageName).join(" · ") })}</p>
        {piece.routeEvidence.uncertainStages.length > 0 && <p>{pt("physical.evidence.uncertain", { stages: piece.routeEvidence.uncertainStages.map(stageName).join(" · ") })}</p>}
        {plan.canConfirm === true && service.reconcileRoute && <button className="button button-secondary" type="button" onClick={() => { setVerifying({ id: piece.id, label: `${piece.index}/${piece.count} · ${line.code ?? line.name}`, evidence: piece.routeEvidence as RouteEvidence }); setError(null); }}>{pt("physical.evidence.open")}</button>}
      </div>)}
      <div className="button-row piece-actions">{line.pieces.map(piece => <button key={piece.id} className="button button-secondary" type="button" onClick={() => setSelected({ id: piece.id, label: pt("physical.bucata_20", { p0: piece.index, p1: piece.count, p2: line.code ?? line.name }) })}>{pt("physical.bucata")}{piece.index}/{piece.count}{pt("physical.pozitie_si_istoric")}</button>)}</div>
      {plan.canConfirm === true && <button className="button button-primary" type="button" onClick={() => { setEditing(line); setError(null); }}>{pt("physical.verifica_si_confirma_planul")}</button>}
    </article>)}
    {selected && <PhysicalHistoryView key={selected.id} service={service} unitId={selected.id} label={selected.label} stageName={stageName} />}
    {verifying && service.reconcileRoute && <RouteEvidenceEditor key={verifying.id} label={verifying.label} evidence={verifying.evidence} stageName={stageName} newKey={newKey}
      submit={(body, key) => (service.reconcileRoute as NonNullable<PhysicalApi["reconcileRoute"]>)(verifying.id, body, key)}
      onCancel={() => { setVerifying(null); setRefresh(v => v + 1); onChanged(); }} onDone={value => { setPlan(value); setVerifying(null); onChanged(); }} />}
    {editing && <PlanEditor key={editing.itemId} line={editing} newKey={newKey} submit={(body, key) => service.confirmPlan(orderId, editing.itemId, body, key)}
      onCancel={() => { setEditing(null); setRefresh(v => v + 1); onChanged(); }} onDone={value => { setPlan(value); setEditing(null); onChanged(); }} />}
  </section>;
}

/** Current production and physical custody are read separately for each known identity. */
function PiecePosition({ label, unitId, service, revision, stageName }: { label: string; unitId: string; service: PhysicalApi; revision: number; stageName: (id: string) => string }) {
  const [state, setState] = useState<PhysicalHistory | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    service.history(unitId, controller.signal).then(value => { setState(value); setError(null); }, caught => { if (!controller.signal.aborted) setError(physicalError(caught)); });
    return () => controller.abort();
  }, [service, unitId, revision]);
  return <p data-testid="piece-position"><strong>{label}</strong> · {error ?? (!state ? pt("physical.se_incarca_istoricul") : `${state.productionStageId ? stageName(state.productionStageId) : pt("physical.retrasa")} · ${state.custody.custodian?.displayName ?? pt("physical.neconfirmat")} · ${state.custody.location?.label ?? pt("physical.neconfirmat")} · ${holdLabel(state.custody.hold)} · ${state.reworkCycle}${state.replaces ? pt("physical.inlocuire_cu_eticheta_noua") : ""}`)}</p>;
}

export function PhysicalHistoryView({ service, unitId, label, stageName }: { service: PhysicalApi; unitId: string; label: string; stageName: (id: string) => string }) {
  const [data, setData] = useState<PhysicalHistory | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    service.history(unitId, controller.signal).then(setData, caught => { if (!controller.signal.aborted) setError(physicalError(caught)); });
    return () => controller.abort();
  }, [service, unitId]);
  return <article className="piece-track card" data-testid="physical-history"><h3>{label}</h3>
    {error && <p role="alert">{error}</p>}
    {!data && !error && <p role="status">{pt("physical.se_incarca_istoricul")}</p>}
    {data && <>
      <p>{data.status === "active" ? pt("physical.activa") : pt("physical.retrasa")}{pt("physical.ciclul")}{data.reworkCycle}{data.replaces ? pt("physical.inlocuire_cu_eticheta_noua") : ""}{data.replacedBy ? pt("physical.inlocuita_eticheta_veche_invalida") : ""}</p>
      <dl className="facts fault-facts"><dt>{pt("physical.etapa")}</dt><dd>{data.productionStageId ? stageName(data.productionStageId) : pt("physical.retrasa")}</dd>
        <dt>{pt("physical.custode_fizic")}</dt><dd>{data.custody.custodian?.displayName ?? pt("physical.neconfirmat")}</dd>
        <dt>{pt("physical.loc_confirmat")}</dt><dd>{data.custody.location?.label ?? pt("physical.neconfirmat")}</dd>
        <dt>{pt("physical.blocaj_fizic")}</dt><dd>{holdLabel(data.custody.hold)}</dd></dl>
      <p>{pt("physical.pozitia_fizica_nu_schimba_etapa_sau_responsabilul")}</p>
      <ol>{data.stageLedger.map((entry, i) => <li key={i}>{stageName(entry.stageId)} · {entry.outcome === "not_applicable" ? pt("physical.nu_se_aplica_fara_timp_sau_puncte") : pt("physical.lucrare_inregistrata")}{pt("physical.ciclul")}{entry.reworkCycle}{entry.employee ? ` · ${entry.employee}` : ""}</li>)}</ol>
      {(data.assistedCheckpoints?.length ?? 0) > 0 && <><h4>{pt("physical.history.assisted")}</h4><ol data-testid="assisted-history">{data.assistedCheckpoints!.map((entry, i) => <li key={i}>{pt("physical.history.assistedEntry", { stage: stageName(entry.stageId), cycle: entry.reworkCycle, status: assistedStatusLabel(entry.action), by: entry.by })}</li>)}</ol></>}
      {data.material.length > 0 && <><h4>{pt("physical.material_inregistrat")}</h4><ul>{data.material.map((entry, i) => <li key={i}>{materialLabel(entry.kind)} · {entry.meters === null ? pt("physical.metraj_necunoscut") : `${entry.meters} m`}</li>)}</ul><p>{pt("physical.fara_scadere_de_stoc_pretul_clientului_ramane")}</p></>}
    </>}
  </article>;
}
const assistedStatusLabel = (action: string) => ({ taken_for_work: pt("physical.assisted.status.taken_for_work"), work_returned: pt("physical.assisted.status.work_returned"), verified: pt("physical.assisted.status.verified"), not_performed: pt("physical.assisted.status.not_performed") }[action] ?? action);
export const holdLabel = (value: string | null) => ({ companion_rework: pt("physical.asteapta_bucata_in_refacere"), inspection: pt("physical.verificare"), reunion_wait: pt("physical.asteapta_reunirea"), quarantine: pt("physical.carantina") }[value ?? ""] ?? pt("physical.fara_blocaj"));
export const materialLabel = (value: string) => ({ planned: pt("physical.planificat"), actual: pt("physical.consum_efectiv"), replacement_actual: pt("physical.consum_pentru_inlocuire"), waste: pt("physical.pierdere"), remnant: pt("physical.rest"), correction: pt("physical.corectie") }[value] ?? pt("physical.inregistrare_material"));

function PlanEditor({ line, newKey, submit, onCancel, onDone }: { line: PlanLine; newKey: () => string; submit: (body: PlanConfirmation, key: string) => Promise<ManufacturingPlan>; onCancel: () => void; onDone: (plan: ManufacturingPlan) => void }) {
  const [count, setCount] = useState(line.plan.pieceCount === null ? "" : String(line.plan.pieceCount));
  const [type, setType] = useState<ProductType>(line.plan.productType);
  const [total, setTotal] = useState(line.plan.materialTotal ?? "");
  const [note, setNote] = useState("");
  const [rows, setRows] = useState<{ width: string; height: string; unit: string; fabric: string }[]>(line.plan.pieces.map(p => ({ width: p.width ?? "", height: p.height ?? "", unit: p.unit ?? "cm", fabric: p.fabricMeters ?? p.fabric ?? "" })));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState<{ body: PlanConfirmation; key: string } | null>(null);
  const lock = useRef(false);
  const n = Number(count);
  const validCount = /^\d+$/.test(count) && n > 0 && n <= 200;
  const rowAt = (i: number) => rows[i] ?? { width: "", height: "", unit: "cm", fabric: "" };
  async function run() {
    if (lock.current || !validCount || note.trim().length < 3) return;
    lock.current = true; setBusy(true); setError(null);
    let command = pending;
    try {
      command ??= { key: newKey(), body: { expectedRevision: line.plan.revision, expectedSourceHash: line.sourcePlanHash, productType: type, pieceCount: n, materialTotalMeters: planDecimal(total),
        pieces: Array.from({ length: n }, (_, i) => { const row = rowAt(i); return { index: i + 1, width: planDecimal(row.width), height: planDecimal(row.height), unit: row.unit, fabricMeters: planDecimal(row.fabric) }; }), note: note.trim() } };
      setPending(command);
      onDone(await submit(command.body, command.key));
    } catch (caught) { setError(physicalError(caught)); if (!isAmbiguous(caught)) setPending(null); }
    finally { lock.current = false; setBusy(false); }
  }
  return <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="plan-confirm-title"><div className="confirmation-card dialog-body">
    <h2 id="plan-confirm-title">{pt("physical.confirma_planul_fizic")}</h2><p>{line.code ?? line.name}{pt("physical.cantitate_comerciala_51")}{line.commercialQuantity}</p>
    <p>{pt("physical.completeaza_numai_masurile_verificate_campurile_goale_raman")}</p>
    <fieldset disabled={busy || pending !== null}>
      <label>{pt("physical.tip_verificat")}<select value={type} disabled={line.plan.typeLocked} onChange={e => setType(e.target.value as ProductType)}><option value="unknown">{pt("physical.necunoscut")}</option><option value="perdea">{pt("physical.perdea")}</option><option value="draperii">{pt("physical.draperii")}</option><option value="other">{pt("physical.alt_produs")}</option></select></label>
      <label>{pt("physical.numar_fizic_de_bucati")}<input inputMode="numeric" value={count} onChange={e => setCount(e.target.value)} /></label>
      <label>{pt("physical.metraj_total_verificat_m")}<input inputMode="decimal" value={total} onChange={e => setTotal(e.target.value)} /></label>
      {validCount && Array.from({ length: n }, (_, i) => <fieldset key={i}><legend>{pt("physical.bucata")}{i + 1}/{n}</legend>
        {(["width", "height", "fabric"] as const).map((field, j) => <label key={field}>{[pt("physical.latime"), pt("physical.inaltime"), pt("physical.metraj_m")][j]}<input inputMode="decimal" value={rowAt(i)[field]} onChange={e => setRows(old => Array.from({ length: n }, (_, k) => ({ ...rowAt(k), ...(k === i ? { [field]: e.target.value } : old[k] ?? {}) })))} /></label>)}
        <label>{pt("physical.unitate")}<select value={rowAt(i).unit} onChange={e => setRows(Array.from({ length: n }, (_, k) => ({ ...rowAt(k), ...(k === i ? { unit: e.target.value } : {}) })))}><option value="cm">{pt("physical.cm")}</option><option value="mm">{pt("physical.mm")}</option><option value="m">{pt("physical.m")}</option></select></label>
      </fieldset>)}
      <label>{pt("physical.confirmarea_fizica_si_sursa_verificarii")}<textarea maxLength={500} value={note} onChange={e => setNote(e.target.value)} /></label>
    </fieldset>
    {error && <p role="alert">{error}</p>}
    <div className="confirmation-actions dialog-actions"><button type="button" className="button button-secondary" disabled={busy} onClick={onCancel}>{pt("physical.inchide_si_reincarca")}</button><button type="button" className="button button-primary" disabled={busy || !validCount || note.trim().length < 3} onClick={() => void run()}>{busy ? pt("physical.asteapta_confirmarea_serverului") : pending ? pt("physical.reincearca_aceeasi_cerere") : pt("physical.confirma_planul_verificat")}</button></div>
  </div></div>;
}

type EvidenceChoice = "skip" | "physical" | "uncertain" | `activity:${string}`;
/** Historical evidence is chosen stage by stage by the verifier. Nothing is preselected; an unverifiable stage stays blocked. */
function RouteEvidenceEditor({ label, evidence, stageName, newKey, submit, onCancel, onDone }: { label: string; evidence: RouteEvidence; stageName: (id: string) => string; newKey: () => string;
  submit: (body: RouteEvidenceInput, key: string) => Promise<ManufacturingPlan>; onCancel: () => void; onDone: (plan: ManufacturingPlan) => void }) {
  const [choices, setChoices] = useState<Record<string, EvidenceChoice>>({});
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState<{ body: RouteEvidenceInput; key: string } | null>(null);
  const lock = useRef(false);
  const chosen = evidence.missingStages.filter(stage => (choices[stage] ?? "skip") !== "skip");
  const ready = chosen.length > 0 && note.trim().length >= 10;
  async function run() {
    if (lock.current || !ready) return;
    lock.current = true; setBusy(true); setError(null);
    let command = pending;
    try {
      command ??= { key: newKey(), body: {
        stages: chosen.filter(stage => choices[stage] !== "uncertain").map(stage => { const choice = choices[stage] as EvidenceChoice;
          return choice === "physical" ? { stageId: stage, evidence: "physical_inspection" as const, activityEventId: null } : { stageId: stage, evidence: "order_activity" as const, activityEventId: choice.slice("activity:".length) }; }),
        uncertainStages: chosen.filter(stage => choices[stage] === "uncertain"), note: note.trim() } };
      setPending(command);
      onDone(await submit(command.body, command.key));
    } catch (caught) { setError(physicalError(caught)); if (!isAmbiguous(caught)) setPending(null); }
    finally { lock.current = false; setBusy(false); }
  }
  return <div className="confirmation-layer" role="dialog" aria-modal="true" aria-labelledby="route-evidence-title"><div className="confirmation-card dialog-body" data-testid="route-evidence-editor">
    <h2 id="route-evidence-title">{pt("physical.evidence.title", { piece: label })}</h2>
    <p>{pt("physical.evidence.intro")}</p>
    <fieldset disabled={busy || pending !== null}>
      {evidence.missingStages.map(stage => <label key={stage}>{stageName(stage)}
        <select value={choices[stage] ?? "skip"} onChange={e => setChoices(old => ({ ...old, [stage]: e.target.value as EvidenceChoice }))}>
          <option value="skip">{pt("physical.evidence.skip")}</option>
          {evidence.candidates.filter(c => c.stageId === stage).map(c => <option key={c.activityEventId} value={`activity:${c.activityEventId}`}>{pt("physical.evidence.activity", { employee: c.employee, at: new Date(c.at).toLocaleString("ro-RO") })}</option>)}
          <option value="physical">{pt("physical.evidence.physical")}</option>
          <option value="uncertain">{pt("physical.evidence.uncertainOption")}</option>
        </select></label>)}
      <label>{pt("physical.evidence.note")}<textarea maxLength={500} value={note} onChange={e => setNote(e.target.value)} /></label>
    </fieldset>
    {error && <p role="alert">{error}</p>}
    <div className="confirmation-actions dialog-actions"><button type="button" className="button button-secondary" disabled={busy} onClick={onCancel}>{pt("physical.inchide_si_reincarca")}</button>
      <button type="button" className="button button-primary" disabled={busy || !ready} onClick={() => void run()}>{busy ? pt("physical.asteapta_confirmarea_serverului") : pending ? pt("physical.reincearca_aceeasi_cerere") : pt("physical.evidence.submit")}</button></div>
  </div></div>;
}
