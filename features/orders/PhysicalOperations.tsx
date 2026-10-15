import { physicalText as pt } from "../../i18n/physical";
import { useEffect, useRef, useState } from "react";
import type { StaffOrder } from "../../domain/models";
import { pieceName } from "../../domain/pieces";
import { isAmbiguous, physicalError, materialDecimal, type CustodyInput, type PhysicalHistory, type StaffPhysicalApi } from "../../domain/physical";
import { createIdempotencyKey } from "../../services/idempotency";
import { holdLabel, materialLabel } from "../../components/ManufacturingPanel";
import { QrCapture } from "../exceptions/QrCapture";

type Command = { key: string; run: () => Promise<unknown> };
/** Exact selection and explicit physical confirmation. Custody never changes production ownership. */
export function PhysicalOperations({ order, service, onChanged }: { order: StaffOrder; service: StaffPhysicalApi; onChanged: () => void }) {
  const [open, setOpen] = useState(false);
  const [unitId, setUnitId] = useState("");
  const [history, setHistory] = useState<PhysicalHistory | null>(null);
  const [places, setPlaces] = useState<{ id: string; label: string }[]>([]);
  const [action, setAction] = useState<CustodyInput["action"]>("take");
  const [locationId, setLocationId] = useState("");
  const [hold, setHold] = useState("inspection");
  const [qr, setQr] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [selected, setSelected] = useState<string[]>([]);
  const [kind, setKind] = useState("waste");
  const [meters, setMeters] = useState("");
  const [note, setNote] = useState("");
  const [corrects, setCorrects] = useState("");
  const [busy, setBusy] = useState(false);
  const [pending, setPending] = useState<Command | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const lock = useRef(false);
  const units = order.pieces?.units ?? [];
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    service.locations(controller.signal).then(answer => setPlaces(answer.locations), caught => { if (!controller.signal.aborted) setError(physicalError(caught)); });
    return () => controller.abort();
  }, [open, service]);
  useEffect(() => {
    if (!unitId) return;
    const controller = new AbortController();
    service.history(unitId, controller.signal).then(setHistory, caught => { if (!controller.signal.aborted) setError(physicalError(caught)); });
    return () => controller.abort();
  }, [unitId, service, order.productionVersion]);
  async function run(command: Command) {
    if (lock.current) return;
    lock.current = true; setBusy(true); setError(null); setDone(false); setPending(command);
    try { await command.run(); setPending(null); setDone(true); setConfirmed(false); setQr(""); onChanged(); if (unitId) setHistory(await service.history(unitId)); }
    catch (caught) { setError(physicalError(caught)); if (!isAmbiguous(caught)) setPending(null); }
    finally { lock.current = false; setBusy(false); }
  }
  function custody() {
    if (!history || !qr || !confirmed) return;
    const key = createIdempotencyKey();
    const body: CustodyInput = { action, expectedVersion: history.custody.version, qrToken: qr, ...(action === "locate" ? { locationId } : {}), ...(action === "hold" ? { holdReason: hold } : {}) };
    void run({ key, run: () => service.custody(unitId, body, key) });
  }
  function material() {
    try {
      const amount = materialDecimal(meters, kind === "correction");
      if (amount === null) return;
      const key = createIdempotencyKey();
      const body = { kind, meters: amount, ...(note.trim() ? { note: note.trim() } : {}), ...(kind === "correction" ? { correctsRecordId: corrects } : {}) };
      void run({ key, run: () => service.material(unitId, body, key) });
    } catch (caught) { setError(physicalError(caught)); }
  }
  if (units.length === 0) return null;
  return <section className="detail-section" data-testid="physical-operations"><h2>{pt("physical.custodie_pozitie_si_material")}</h2>
    <p>{pt("physical.o_verificare_fizica_a_unei_bucati_conforme")}</p>
    {!open ? <button type="button" className="button button-secondary" onClick={() => setOpen(true)}>{pt("physical.deschide_operatiunile_fizice")}</button> : <>
      {error && <p role="alert">{error}</p>}{done && <p role="status">{pt("physical.operatiune_salvata_de_server")}</p>}
      {pending && !busy && <div className="button-row"><button className="button button-primary" type="button" onClick={() => void run(pending)}>{pt("physical.reincearca_aceeasi_cerere")}</button><button type="button" className="button button-secondary" onClick={async () => { if (unitId) setHistory(await service.history(unitId)); setPending(null); setConfirmed(false); setQr(""); onChanged(); }}>{pt("physical.reincarca_starea_salvata")}</button></div>}
      <fieldset disabled={busy || pending !== null}>
        <label>{pt("physical.bucata_exacta")}<select value={unitId} onChange={e => { setUnitId(e.target.value); setHistory(null); setConfirmed(false); setQr(""); setDone(false); }}><option value="">{pt("physical.selecteaza_explicit_bucata")}</option>{units.map(unit => <option key={unit.id} value={unit.id}>{pieceName(unit)} · {order.products.find(p => p.id === unit.itemId)?.code ?? ""}</option>)}</select></label>
        {history && <><p>{pt("physical.custode")}{history.custody.custodian?.displayName ?? pt("physical.neconfirmat")}{pt("physical.loc")}{history.custody.location?.label ?? pt("physical.neconfirmat")} · {holdLabel(history.custody.hold)}</p>
          <label>{pt("physical.operatiune")}<select aria-label={pt("physical.operatiune")} value={action} onChange={e => { setAction(e.target.value as CustodyInput["action"]); setConfirmed(false); }}><option value="take">{pt("physical.confirm_primirea_fizica")}</option><option value="locate">{pt("physical.confirm_pozitia")}</option><option value="hold">{pt("physical.retin_fizic_bucata")}</option><option value="release">{pt("physical.eliberez_blocajul_fizic")}</option></select></label>
          {action === "locate" && <label>{pt("physical.loc_inregistrat")}<select value={locationId} onChange={e => setLocationId(e.target.value)}><option value="">{pt("physical.alege_locul_real")}</option>{places.map(place => <option key={place.id} value={place.id}>{place.label}</option>)}</select>{places.length === 0 && <span>{pt("physical.nu_exista_locuri_inregistrate_nu_se_poate")}</span>}</label>}
          {action === "hold" && <label>{pt("physical.motiv")}<select value={hold} onChange={e => setHold(e.target.value)}>{["inspection", "companion_rework", "reunion_wait", "quarantine"].map(v => <option key={v} value={v}>{holdLabel(v)}</option>)}</select></label>}
          {qr ? <p>{pt("physical.cod_scanat_operatiunea_asteapta_confirmarea_explicita")}</p> : <QrCapture disabled={busy} onToken={setQr} />}
          <label><input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />{pt("physical.confirm_bucata_si_operatiunea_fizica_selectata")}</label>
          <button className="button button-primary" type="button" disabled={!confirmed || !qr || (action === "locate" && !locationId)} onClick={custody}>{pt("physical.salveaza_operatiunea_fizica")}</button>
          <h3>{pt("physical.material_masurat")}</h3><p>{pt("physical.planificat_consum_pierdere_si_inlocuire_sunt_inregistrari")}</p>
          <label>{pt("physical.inregistrare")}<select value={kind} onChange={e => setKind(e.target.value)}>{["actual", "replacement_actual", "waste", "remnant", "correction"].map(v => <option key={v} value={v}>{materialLabel(v)}</option>)}</select></label>
          <label>{pt("physical.metri_verificati")}<input inputMode="decimal" value={meters} onChange={e => setMeters(e.target.value)} /></label>
          {kind === "correction" && <label>{pt("physical.inregistrarea_corectata")}<select value={corrects} onChange={e => setCorrects(e.target.value)}><option value="">{pt("physical.alege_inregistrarea")}</option>{history.material.filter(v => v.kind !== "correction").map((v, i) => <option key={i} value={v.id}>{materialLabel(v.kind)} · {v.meters ?? pt("physical.necunoscut")}{pt("physical.m")}</option>)}</select></label>}
          <label>{pt("physical.explicatie")}<textarea value={note} onChange={e => setNote(e.target.value)} maxLength={500} /></label>
          <button type="button" className="button button-primary" disabled={!meters.trim() || (kind === "correction" && (!corrects || note.trim().length < 3))} onClick={material}>{pt("physical.inregistreaza_materialul_masurat")}</button>
        </>}
        <h3>{pt("physical.reunire_fizica")}</h3><p>{pt("physical.confirma_bucatile_aflate_fizic_impreuna_etapele_neterminate")}</p>
        {units.map(unit => <label key={unit.id}><input type="checkbox" checked={selected.includes(unit.id)} onChange={e => setSelected(old => e.target.checked ? [...old, unit.id] : old.filter(v => v !== unit.id))} />{pieceName(unit)} · {order.products.find(p => p.id === unit.itemId)?.code ?? ""}</label>)}
        <button type="button" className="button button-primary" disabled={selected.length < 2 || selected.length > 50} onClick={() => { const key = createIdempotencyKey(); const body = { unitIds: [...selected], confirmed: true as const }; void run({ key, run: () => service.reunite(body, key) }); }}>{pt("physical.confirm_reunirea_bucatilor_selectate")}</button>
      </fieldset>
      {busy && <p role="status">{pt("physical.asteapta_confirmarea_serverului")}</p>}
    </>}
  </section>;
}
