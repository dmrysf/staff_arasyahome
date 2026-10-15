import { t } from "../i18n";
import type { FaultException, OrderPieces, PieceTrack, ProductionPiece, StaffOrder } from "./models";

/**
 * Physical pieces of an order (API 2.31.0). A line of quantity 2 is two pieces, "Bucata 1/2" and "Bucata 2/2". Pieces
 * reported defective alone travel on their own track (own stage, owner, rework cycle) and rejoin the order at packing.
 * Every action on a track names the track explicitly; nothing here guesses which piece is in the employee's hand.
 */

export type PieceLabel = { unitId: string; piece: string; name: string; code: string | null; color: string | null; payload: string | null; svg: string | null };
export type PieceLabels = { trackId: string; orderId: string; orderNumber: string; reworkCycle: number; labels: PieceLabel[] };
/** The committed state after a claim or completion on a track: the order's pieces as the employee sees them now. */
export type PieceActionResult = { orderId: string; trackId: string; trackStatus: "active" | "merged" | null; stageId: string | null; pieces: OrderPieces };

/** Work on a piece in rework. Each mutation is server-confirmed; a retry after a network failure reuses the same key. */
export interface PieceApi {
  claim(trackId: string, input: { expectedVersion: number; qrToken: string; idempotencyKey: string }): Promise<PieceActionResult>;
  complete(trackId: string, input: { expectedVersion: number; defectResolved?: true; idempotencyKey: string }): Promise<PieceActionResult>;
  labels(trackId: string, signal?: AbortSignal): Promise<PieceLabels>;
}

/** "Bucata 1/2" in the active language. */
export function pieceName(piece: Pick<ProductionPiece, "index" | "count">): string {
  return t("piece.label", { index: piece.index, count: piece.count });
}

/** The pieces of one track, in line and index order. */
export function trackPieces(pieces: OrderPieces, track: Pick<PieceTrack, "id">): ProductionPiece[] {
  return pieces.units.filter((unit) => unit.trackId === track.id);
}

/** Pieces that travel with the order (the order's own stage applies to them). */
export function orderPieces(pieces: OrderPieces): ProductionPiece[] {
  return pieces.units.filter((unit) => unit.trackId === null);
}

/** Status words of a track, from its open request or its position; never a guess about what will happen next. */
export function trackStatusLabel(track: PieceTrack): string {
  const status = track.openException?.status;
  if (status === "awaiting_supervisor_review") return t("piece.status.inReview");
  if (status === "in_supervisor_custody" || status === "return_rejected" || status === "awaiting_restore_receipt") return t("piece.status.custody");
  if (status === "awaiting_acknowledgment") return t("piece.status.pendingReceipt");
  if (status === "awaiting_approval") return t("piece.status.pendingApproval");
  if (status) return t("piece.status.blocked");
  if (track.waitingForOrder) return t("piece.status.waitingForOrder");
  if (track.reworked && track.stage.id === "material-preparation") return t("piece.status.reworkCutting");
  return track.reworked ? t("piece.cycle", { cycle: track.reworkCycle }) : t("piece.separated");
}

/**
 * The approved return the employee now holds: a reworked track at cutting owned by them. Shown only from the server's
 * committed state (the order reloaded after the approval), never before.
 */
export function approvedReturnFor(order: Pick<StaffOrder, "pieces">): PieceTrack | null {
  return order.pieces?.tracks.find((track) => track.owner?.isMe === true && track.reworked && track.stage.id === "material-preparation" && !track.openException) ?? null;
}

/**
 * Tracks the scanned order offers to this employee: tracks with an action for them or an open request addressed to
 * them. With a scanned piece label only that piece's track is offered.
 */
export function actionableTracks(order: Pick<StaffOrder, "pieces" | "scannedPiece">): PieceTrack[] {
  const tracks = order.pieces?.tracks ?? [];
  const scanned = order.scannedPiece?.trackId;
  return tracks.filter((track) => (scanned ? track.id === scanned : true)
    && (track.allowedAction !== null || Boolean(track.openException?.actions && Object.values(track.openException.actions).some(Boolean))));
}

/**
 * Whether a scan of this order is ambiguous for production work: some pieces travel apart and the scan did not name
 * one. The scanner then asks which piece is in the hand instead of acting on the order (fail closed).
 */
export function scanNeedsPieceChoice(order: Pick<StaffOrder, "pieces" | "scannedPiece">): boolean {
  return Boolean(order.pieces?.mixedProgress) && !order.scannedPiece;
}

/** Partial selection helpers for the defect report: k of n pieces of each line, never a silent default. */
export function selectionSummary(pieces: ProductionPiece[], selected: ReadonlySet<string>): { selected: number; total: number; partial: boolean } {
  const chosen = pieces.filter((piece) => selected.has(piece.id)).length;
  return { selected: chosen, total: pieces.length, partial: chosen > 0 && chosen < pieces.length };
}

/** Whether a request moves only the reported pieces (they travel on their own track). */
export function isPartialScope(fault: Pick<FaultException, "orderScope">): boolean {
  return fault.orderScope?.partial === true;
}

/** "1 buc. defectă · 1 buc. conformă" for a request that named exact pieces; null for older line reports. */
export function scopeCounts(fault: Pick<FaultException, "orderScope">): { defective: string; healthy: string } | null {
  const scope = fault.orderScope;
  if (!scope?.piecesRecorded || scope.unaffectedQuantity === undefined) return null;
  return { defective: t("piece.defective", { count: scope.affectedQuantity }), healthy: t("piece.healthy", { count: scope.unaffectedQuantity }) };
}

/** "ARASYA-PIECE-11709027615-1-2.svg": no customer data in file names. */
export function pieceLabelFilename(orderNumber: string, piece: string): string {
  return `ARASYA-PIECE-${orderNumber.replace(/[^A-Za-z0-9-]/g, "")}-${piece.replace("/", "-")}.svg`;
}

/** A scanned code that is a piece label (ARASYA:U1:…): resolved directly, never sent to the cutting intake. */
export function isPieceLabel(token: string): boolean {
  return /^ARASYA:U1:[A-Z2-7]{26}$/i.test(token.trim());
}
