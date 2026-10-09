import { StaffServiceError, type ServiceErrorCode } from "../domain/models";
import { invalidDocumentMessage } from "../domain/documents";

export type ErrorPresentation = { title: string; message: string; action: string };

const presentations: Record<ServiceErrorCode, ErrorPresentation> = {
  TRENDYOL_PACKAGE_NOT_FOUND: { title: "Pachet inexistent", message: "Pachetul Trendyol nu există sau nu mai este disponibil.", action: "Înapoi" },
  TRENDYOL_PACKAGE_CHANGED: { title: "Pachetul s-a modificat", message: "Un coleg sau Trendyol a actualizat pachetul între timp. Reîncarcă înainte de a continua.", action: "Reîncarcă" },
  TRENDYOL_PACKAGE_NOT_PENDING: { title: "Pachetul nu mai este în lucru", message: "Pachetul a fost deja aprobat, scos din lucru sau anulat în Trendyol.", action: "Reîncarcă" },
  TRENDYOL_PACKAGE_NOT_PREPARED: { title: "Date de producție incomplete", message: "Completează tipul produsului și, pentru perdele și draperii, lățimea și înălțimea fiecărei linii.", action: "Completează" },
  TRENDYOL_STATUS_NOT_RELEASABLE: { title: "Status Trendyol incompatibil", message: "Statusul actual din Trendyol, verificat chiar acum, nu permite intrarea în producție. Pagina arată statusul actualizat.", action: "Reîncarcă" },
  TRENDYOL_LINES_CHANGED: { title: "Produsele s-au schimbat în Trendyol", message: "Produsele, codurile sau cantitățile s-au schimbat în Trendyol. Verifică liniile actualizate și completează din nou datele de producție.", action: "Verifică" },
  TRENDYOL_PACKAGE_UNAVAILABLE: { title: "Pachet indisponibil în Trendyol", message: "Trendyol nu mai returnează acest pachet. Verifică în Seller Panel; comanda nu a intrat în producție.", action: "Reîncarcă" },
  TRENDYOL_VERIFICATION_FAILED: { title: "Verificarea Trendyol nu a reușit", message: "Nu am putut verifica acum comanda în Trendyol (rețea, limită de cereri sau răspuns invalid). Comanda nu a intrat în producție. Încearcă din nou peste câteva minute.", action: "Încearcă din nou" },
  TRENDYOL_INPUT_INVALID: { title: "Date invalide", message: "Verifică valorile introduse (măsuri pozitive în cm, motiv obligatoriu, text de maxim 1000 de caractere).", action: "Corectează" },
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
  PASSWORD_CHANGE_REQUIRED: { title: "Schimbă parola", message: "Contul folosește o parolă temporară. Alege o parolă nouă pentru a continua.", action: "Schimbă parola" },
  APPLICATION_ACCESS_DENIED: { title: "Fără acces la Staff", message: "Contul tău nu are acces la aplicația Staff. Contactează managerul.", action: "Ieși din cont" },
  CURRENT_PASSWORD_INVALID: { title: "Parola actuală nu este corectă", message: "Verifică parola actuală și încearcă din nou.", action: "Încearcă din nou" },
  PASSWORD_POLICY: { title: "Parolă prea slabă", message: "Parola nouă trebuie să aibă cel puțin 12 caractere, să fie diferită de cea actuală și să nu conțină numele de utilizator.", action: "Încearcă din nou" },
  ORDER_BLOCKED_BY_EXCEPTION: { title: "Comanda este blocată", message: "O cerere de returnare la tăiere este deschisă. Comanda nu poate avansa până la decizia managerului.", action: "Reîncarcă" },
  EXCEPTION_ALREADY_OPEN: { title: "Există deja o cerere", message: "Pentru această comandă există deja o cerere de returnare deschisă.", action: "Reîncarcă" },
  EXCEPTION_STAGE_INVALID: { title: "Etapă nepotrivită", message: "Returnarea la tăiere se face numai de la Primire Croitorie.", action: "Reîncarcă" },
  FAULT_REPORT_NOT_ALLOWED: { title: "Returnare indisponibilă", message: "Doar angajatul de la Primire Croitorie care a preluat comanda o poate returna la tăiere.", action: "Înapoi" },
  SELF_FAULT_REPORT_DENIED: { title: "Returnare indisponibilă", message: "Nu poți raporta o eroare atribuită ție.", action: "Înapoi" },
  RESPONSIBLE_EMPLOYEE_UNKNOWN: { title: "Responsabil necunoscut", message: "Comanda nu are o predare înregistrată de la tăiere. Contactează managerul operațional.", action: "Înapoi" },
  FAULT_LINES_INVALID: { title: "Produse invalide", message: "Selectează doar produse din această comandă.", action: "Reîncarcă" },
  FAULT_LINE_WITHOUT_METERS: { title: "Metraj lipsă", message: "Un produs selectat nu are metraj înregistrat.", action: "Înapoi" },
  REASON_INVALID: { title: "Motiv indisponibil", message: "Motivul ales nu mai este disponibil. Alege alt motiv.", action: "Reîncarcă" },
  COMMENT_REQUIRED: { title: "Comentariu obligatoriu", message: "Scrie un comentariu pentru a continua.", action: "Înapoi" },
  CONFIRMATION_REQUIRED: { title: "Confirmare necesară", message: "Bifează confirmarea pentru a continua.", action: "Înapoi" },
  QR_REQUIRED: { title: "Scanare necesară", message: "Scanează codul QR al comenzii.", action: "Scanează din nou" },
  QR_ORDER_MISMATCH: { title: "Altă comandă", message: "Codul QR scanat aparține altei comenzi. Scanează eticheta comenzii returnate.", action: "Scanează din nou" },
  EXCEPTION_NOT_FOUND: { title: "Cererea nu a fost găsită", message: "Cererea nu există sau nu este adresată ție.", action: "Înapoi" },
  EXCEPTION_NOT_ASSIGNED: { title: "Cerere neatribuită ție", message: "Această cerere nu îți este adresată.", action: "Înapoi" },
  EXCEPTION_CHANGED: { title: "Cererea s-a modificat", message: "Cererea a fost actualizată între timp. Reîncarcă înainte de a continua.", action: "Reîncarcă" },
  EXCEPTION_STATE_INVALID: { title: "Acțiune indisponibilă", message: "Cererea nu mai permite această acțiune.", action: "Reîncarcă" },
  EXCEPTION_ALREADY_RESOLVED: { title: "Cerere rezolvată", message: "Cererea a fost deja rezolvată.", action: "Reîncarcă" },
  ORDER_BLOCKED_BY_DOCUMENT: { title: "Document blocat", message: "Comanda are o revizie de document în curs. Așteaptă aprobarea și documentul nou.", action: "Reîncarcă" },
  DOCUMENT_SUPERSEDED: { title: "Document invalid", message: "Acest document a fost înlocuit. Folosește revizia activă a documentului.", action: "Scanează din nou" },
  DOCUMENT_REVOKED: { title: "Document invalid", message: "Acest document a fost anulat. Așteaptă documentul nou.", action: "Scanează din nou" },
  DOCUMENT_NOT_GENERATED: { title: "Document negenerat", message: "Comanda nu are încă un document de producție.", action: "Reîncarcă" },
  DOCUMENT_ALREADY_ACTIVE: { title: "Documentul există", message: "Documentul activ există deja. Pentru hârtie pierdută sau deteriorată folosește retipărirea.", action: "Reîncarcă" },
  DOCUMENT_NOT_STALE: { title: "Revizie inutilă", message: "Documentul activ corespunde comenzii. Nu este necesară o revizie.", action: "Reîncarcă" },
  DOCUMENT_REQUEST_OPEN: { title: "Cerere deja deschisă", message: "Există deja o cerere de revizie pentru această comandă.", action: "Reîncarcă" },
  DOCUMENT_APPROVAL_REQUIRED: { title: "Aprobare necesară", message: "Revizia nouă necesită aprobare înainte de generare.", action: "Reîncarcă" },
  DOCUMENT_CONTENT_CHANGED: { title: "Comanda s-a schimbat din nou", message: "Conținutul comenzii s-a schimbat după aprobare. Este necesară o nouă cerere de revizie.", action: "Reîncarcă" },
  DOCUMENT_CHANGED: { title: "Documentul s-a schimbat", message: "Starea documentului a fost actualizată între timp. Reîncarcă înainte de a continua.", action: "Reîncarcă" },
  DOCUMENT_ORDER_COMPLETED: { title: "Comandă finalizată", message: "Revizia documentului după finalizare nu face parte din acest flux.", action: "Înapoi" },
  DOCUMENT_AUTHORITY_SOURCE: { title: "Fișa rămâne în magazin", message: "Fișa de producție a acestei comenzi este încă emisă de magazinul sursă. Documentul Arasya devine disponibil după activarea autorității de documente.", action: "Înapoi" },
  DOCUMENT_NOT_FOUND: { title: "Document negăsit", message: "Revizia cerută nu există sau nu poate fi afișată.", action: "Reîncarcă" },
  DOCUMENT_REVISION_NOT_ACTIVE: { title: "Revizie înlocuită", message: "Această revizie nu mai este documentul activ. Reîncarcă pagina.", action: "Reîncarcă" },
  PRODUCTION_AUTHORITY_SOURCE: { title: "Comanda nu este preluată în Arasya", message: "Producția acestei comenzi este încă gestionată în YD SOFT. Un manager trebuie să o preia în Arasya înainte de a lucra la ea.", action: "Înapoi" },
  AUTHORITY_CUTOVER_DISABLED: { title: "Preluare neactivată", message: "Preluarea autorității de producție nu este activată pentru această sursă.", action: "Înapoi" },
  AUTHORITY_NOT_SUPPORTED: { title: "Preluare indisponibilă", message: "Autoritatea de producție a acestei comenzi nu poate fi schimbată.", action: "Înapoi" },
  AUTHORITY_ALREADY_OPERATIONS: { title: "Comanda este deja în Arasya", message: "Arasya gestionează deja producția acestei comenzi. Etapele se schimbă numai prin fluxul de producție.", action: "Reîncarcă" },
  AUTHORITY_NOT_OPERATIONS: { title: "Comanda nu este în Arasya", message: "Producția acestei comenzi nu este gestionată în Arasya.", action: "Reîncarcă" },
  AUTHORITY_RELEASE_NOT_ALLOWED: { title: "Anulare imposibilă", message: "Preluarea poate fi anulată numai dacă nimeni nu a lucrat la comandă după preluare.", action: "Reîncarcă" },
  INVALID_STAGE: { title: "Etapă invalidă", message: "Alege o etapă din fluxul de producție Arasya.", action: "Înapoi" },
  WORKFLOW_MISMATCH: { title: "Flux schimbat", message: "Fluxul de producție s-a schimbat. Reîncarcă și alege din nou etapa.", action: "Reîncarcă" },
  PRODUCTION_COMPLETED: { title: "Producție finalizată", message: "Producția acestei comenzi este finalizată.", action: "Înapoi" },
  WORKFLOW_UNAVAILABLE: { title: "Flux indisponibil", message: "Catalogul etapelor de producție nu poate fi încărcat momentan. Starea comenzii nu a fost schimbată.", action: "Reîncearcă" },
  QR_SUPERSEDED: { title: "COD QR ÎNLOCUIT", message: "Acest cod nu mai este valabil pentru producție. Folosește eticheta cu codul QR activ.", action: "Scanează din nou" },
  QR_REVOKED: { title: "COD QR ANULAT", message: "Acest cod a fost anulat și nu mai poate fi folosit pentru producție. Cere managerului eticheta nouă.", action: "Scanează din nou" },
  QR_CHANGED: { title: "Codul QR s-a schimbat", message: "Codul QR al comenzii a fost schimbat între timp. Reîncarcă înainte de a continua.", action: "Reîncarcă" },
  QR_CUTOVER_DISABLED: { title: "Autoritate QR neactivată", message: "Codul QR Arasya nu este încă autoritatea de producție pentru această sursă.", action: "Înapoi" },
  QR_NOT_ARASYA: { title: "Comanda nu este în Arasya", message: "Producția acestei comenzi nu este gestionată în Arasya, deci codul QR nu poate fi schimbat aici.", action: "Înapoi" },
  QR_NOT_SUPPORTED: { title: "Schimbare indisponibilă", message: "Codul QR al acestei comenzi se schimbă doar prin revizia documentului de producție.", action: "Înapoi" },
  QR_DOCUMENT_CONTROLLED: { title: "Cod legat de document", message: "Comanda are un document de producție: codul QR se schimbă doar printr-o revizie aprobată a documentului.", action: "Înapoi" },
};

export function getErrorPresentation(error: unknown): ErrorPresentation {
  // An old or blocked production document is shown in strong Romanian words, with the active revision.
  const document = invalidDocumentMessage(error);
  if (document && error instanceof StaffServiceError) return { title: document.title, message: document.lines.join(" "), action: presentations[error.code].action };
  // A replaced production QR names the active QR revision when the employee may see the order.
  if (error instanceof StaffServiceError && error.code === "QR_SUPERSEDED" && typeof error.details?.activeQrRevision === "number") {
    return { ...presentations.QR_SUPERSEDED, message: `Acest cod nu mai este valabil pentru producție. Folosește eticheta cu codul QR activ (revizia ${error.details.activeQrRevision}).` };
  }
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
