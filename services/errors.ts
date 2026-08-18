import { StaffServiceError, type ServiceErrorCode } from "../domain/models";

export type ErrorPresentation = { title: string; message: string; action: string };

const presentations: Record<ServiceErrorCode, ErrorPresentation> = {
  CAMERA_PERMISSION_DENIED: { title: "Acces la cameră blocat", message: "Permite accesul la cameră din setările browserului, apoi încearcă din nou.", action: "Încearcă din nou" },
  CAMERA_UNAVAILABLE: { title: "Camera nu este disponibilă", message: "Închide altă aplicație care folosește camera sau introdu codul manual.", action: "Introdu codul" },
  NO_CAMERA_DEVICE: { title: "Nu am găsit o cameră", message: "Poți continua introducând numărul sau codul comenzii.", action: "Introdu codul" },
  INVALID_QR: { title: "Cod QR invalid", message: "Codul scanat nu este un cod de comandă recunoscut.", action: "Scanează din nou" },
  UNKNOWN_QR: { title: "Cod QR necunoscut", message: "Nu am putut asocia acest cod cu o comandă.", action: "Scanează din nou" },
  EXPIRED_QR: { title: "Cod QR expirat", message: "Folosește codul actual al comenzii sau caută manual.", action: "Caută manual" },
  ORDER_NOT_FOUND: { title: "Comanda nu a fost găsită", message: "Verifică numărul introdus și încearcă din nou.", action: "Încearcă din nou" },
  ORDER_UNAVAILABLE: { title: "Comanda nu este disponibilă", message: "Comanda a fost anulată sau nu mai poate fi procesată.", action: "Scanează altă comandă" },
  ORDER_CHANGED: { title: "Comanda s-a modificat", message: "Alt coleg a actualizat comanda între timp. Reîncarcă înainte de a continua.", action: "Reîncarcă" },
  NETWORK_UNAVAILABLE: { title: "Nu există conexiune", message: "Acțiunea nu poate fi finalizată offline. Verifică rețeaua și încearcă din nou.", action: "Încearcă din nou" },
  REQUEST_TIMEOUT: { title: "Răspunsul întârzie", message: "Nu am primit confirmarea serverului. Starea comenzii nu a fost schimbată.", action: "Încearcă din nou" },
  SERVER_ERROR: { title: "Ceva nu a funcționat", message: "Starea comenzii nu a fost schimbată. Încearcă din nou peste câteva momente.", action: "Încearcă din nou" },
  SESSION_EXPIRED: { title: "Sesiunea a expirat", message: "Autentifică-te din nou pentru a continua în siguranță.", action: "Autentificare" },
  UNAUTHORIZED_ACTION: { title: "Acțiune indisponibilă", message: "Nu ai permisiunea necesară pentru această etapă.", action: "Înapoi" },
  CONFIGURATION_ERROR: { title: "Serviciul nu este configurat", message: "Conexiunea cu serviciul Staff lipsește. Contactează administratorul.", action: "Reîncearcă" },
};

export function getErrorPresentation(error: unknown): ErrorPresentation {
  if (error instanceof StaffServiceError) return presentations[error.code];
  return presentations.SERVER_ERROR;
}

export function toServiceError(error: unknown): StaffServiceError {
  if (error instanceof StaffServiceError) return error;
  if (error instanceof DOMException && error.name === "NotAllowedError") return new StaffServiceError("CAMERA_PERMISSION_DENIED");
  if (error instanceof DOMException && error.name === "NotFoundError") return new StaffServiceError("NO_CAMERA_DEVICE");
  if (error instanceof DOMException && (error.name === "NotReadableError" || error.name === "AbortError")) return new StaffServiceError("CAMERA_UNAVAILABLE");
  return new StaffServiceError("SERVER_ERROR");
}

export function mapCameraError(error: { name?: string }): StaffServiceError {
  if (error.name === "NotAllowedError" || error.name === "SecurityError") return new StaffServiceError("CAMERA_PERMISSION_DENIED");
  if (error.name === "NotFoundError" || error.name === "DevicesNotFoundError") return new StaffServiceError("NO_CAMERA_DEVICE");
  return new StaffServiceError("CAMERA_UNAVAILABLE");
}
