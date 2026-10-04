import { StaffServiceError, type ServiceErrorCode } from "../domain/models";

export type ErrorPresentation = { title: string; message: string; action: string };

const presentations: Record<ServiceErrorCode, ErrorPresentation> = {
  CAMERA_PERMISSION_DENIED: { title: "Acces la cameră blocat", message: "Permite accesul la cameră din setările browserului, apoi încearcă din nou.", action: "Încearcă din nou" },
  CAMERA_UNAVAILABLE: { title: "Camera nu este disponibilă", message: "Închide altă aplicație care folosește camera sau introdu codul manual.", action: "Introdu codul" },
  NO_CAMERA_DEVICE: { title: "Nu am găsit o cameră", message: "Poți continua introducând numărul sau codul comenzii.", action: "Introdu codul" },
  INVALID_QR: { title: "Cod QR invalid", message: "Codul scanat nu este un cod de comandă recunoscut.", action: "Scanează din nou" },
  UNKNOWN_QR: { title: "Cod QR necunoscut", message: "Nu am putut asocia acest cod cu o comandă.", action: "Scanează din nou" },
  EXPIRED_QR: { title: "Cod QR expirat", message: "Folosește codul actual al comenzii sau caută manual.", action: "Caută manual" },
  ORDER_NOT_FOUND: { title: "Comanda nu a fost găsită", message: "Comanda nu există sau nu este la etapele tale. Verifică numărul și încearcă din nou.", action: "Încearcă din nou" },
  ORDER_ALREADY_CLAIMED: { title: "Comanda este preluată", message: "Un coleg lucrează deja la această comandă.", action: "Scanează altă comandă" },
  ORDER_AMBIGUOUS: { title: "Mai multe comenzi", message: "Mai multe comenzi au acest număr. Scanează codul QR sau scrie și sursa, de exemplu trendhome:61833.", action: "Încearcă din nou" },
  INVALID_ORDER_CODE: { title: "Cod invalid", message: "Folosește doar numărul comenzii, fără spații sau simboluri speciale.", action: "Încearcă din nou" },
  INVALID_STAGE_TRANSITION: { title: "Etapa nu poate fi finalizată", message: "Comanda trebuie preluată la etapa curentă sau este deja finalizată.", action: "Reîncarcă" },
  IDEMPOTENCY_CONFLICT: { title: "Acțiune neconfirmată", message: "Cererea nu a putut fi confirmată în siguranță. Reîncarcă și încearcă din nou.", action: "Reîncarcă" },
  ORDER_UNAVAILABLE: { title: "Comanda nu este disponibilă", message: "Comanda a fost anulată sau nu mai poate fi procesată.", action: "Scanează altă comandă" },
  ORDER_PRODUCTS_UNAVAILABLE: { title: "Produse indisponibile", message: "Comanda nu conține produse disponibile pentru producție.", action: "Scanează din nou" },
  ORDER_CHANGED: { title: "Comanda s-a modificat", message: "Alt coleg a actualizat comanda între timp. Reîncarcă înainte de a continua.", action: "Reîncarcă" },
  AUTOMATIC_SCAN_UNAVAILABLE: { title: "Scanare automată indisponibilă", message: "Scanarea automată nu este disponibilă pe acest dispozitiv.", action: "Introdu codul comenzii" },
  NETWORK_UNAVAILABLE: { title: "Nu există conexiune", message: "Acțiunea nu poate fi finalizată offline. Verifică rețeaua și încearcă din nou.", action: "Încearcă din nou" },
  REQUEST_TIMEOUT: { title: "Răspunsul întârzie", message: "Nu am primit confirmarea serverului. Starea comenzii nu a fost schimbată.", action: "Încearcă din nou" },
  SERVER_ERROR: { title: "Ceva nu a funcționat", message: "Starea comenzii nu a fost schimbată. Încearcă din nou peste câteva momente.", action: "Încearcă din nou" },
  NO_SESSION: { title: "Autentificare necesară", message: "Autentifică-te pentru a continua.", action: "Autentificare" },
  SESSION_EXPIRED: { title: "Sesiunea a expirat", message: "Autentifică-te din nou pentru a continua în siguranță.", action: "Autentificare" },
  UNAUTHORIZED_ACTION: { title: "Acțiune indisponibilă", message: "Nu ai permisiunea necesară pentru această etapă.", action: "Înapoi" },
  INVALID_CREDENTIALS: { title: "Autentificare nereușită", message: "Nu am putut autentifica acest cont.", action: "Încearcă din nou" },
  ACCOUNT_INACTIVE: { title: "Cont inactiv", message: "Contul nu este activ. Contactează managerul.", action: "Înapoi" },
  RATE_LIMITED: { title: "Prea multe încercări", message: "Încearcă din nou puțin mai târziu.", action: "Încearcă mai târziu" },
  SERVICE_UNAVAILABLE: { title: "Serviciu indisponibil", message: "Serviciul nu este disponibil momentan.", action: "Reîncearcă" },
  CSRF_INVALID: { title: "Acțiune neconfirmată", message: "Nu am putut confirma acțiunea în siguranță. Încearcă din nou.", action: "Încearcă din nou" },
  CONFIGURATION_ERROR: { title: "Serviciul nu este configurat", message: "Conexiunea cu serviciul Staff lipsește. Contactează administratorul.", action: "Reîncearcă" },
  WORKFLOW_UNAVAILABLE: { title: "Flux indisponibil", message: "Catalogul etapelor de producție nu poate fi încărcat momentan. Starea comenzii nu a fost schimbată.", action: "Reîncearcă" },
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
