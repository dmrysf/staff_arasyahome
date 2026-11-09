import type { AwbErrorCode } from "../../domain/awb";

/**
 * Text of the Trendyol AWB station (Staff 2.47.0). The station has its own language switch, Romanian by default and
 * Turkish on demand, stored only in this browser: it never changes any account's Staff language.
 */
export type AwbLocale = "ro" | "tr";
export const AWB_LOCALE_KEY = "arasya.awb.locale";

const ro = {
  title: "AWB Trendyol",
  subtitle: "Etichete de transport pentru comenzile din împachetare și livrare",
  languageLabel: "Limba interfeței",
  online: "Conectat",
  offline: "Fără conexiune",
  searchLabel: "Caută comanda sau scanează codul QR",
  searchPlaceholder: "Caută comanda sau scanează codul QR",
  searchButton: "Caută",
  clearSearch: "Șterge căutarea",
  queueTitle: "Comenzi pregătite pentru etichetare",
  queueCount: "{count} comenzi Trendyol",
  queueCountOne: "1 comandă Trendyol",
  filterLabel: "Filtrează după etapă",
  filterAll: "Toate",
  filter13: "Etapa 13 · Împachetare",
  filter14: "Etapa 14 · Livrare",
  order: "Comanda #{number}",
  stage: "Împachetare · Etapa 13",
  stageDelivery: "Livrare · Etapa 14",
  stageOther: "Etapa {ordinal}",
  stageDone: "Producție finalizată",
  print: "Imprimă AWB",
  reprint: "Reimprimă",
  printedBefore: "Imprimată anterior",
  printedBeforeTitle: "Etichetă imprimată anterior",
  printedTimes: "Cerută de {count} ori",
  preparing: "Se pregătește eticheta…",
  loading: "Se încarcă comenzile…",
  empty: "Nu există comenzi Trendyol la etapele 13 și 14",
  emptyHint: "Comenzile Trendyol apar aici automat la etapa 13 (Împachetare) și rămân până la finalizarea etapei 14 (Livrare).",
  more: "Arată mai multe comenzi",
  refreshing: "Se actualizează…",
  updatedAt: "Actualizat la {time}",
  retry: "Încearcă din nou",
  searchResults: "Rezultatul căutării",
  closeResults: "Închide rezultatul",
  notInQueue: "Nu mai este în etapele 13–14",
  stopped: "Producție oprită",
  packages: "{count} pachete Trendyol pentru această comandă",
  packageLabel: "Pachet {id}",
  previewTitle: "Eticheta Trendyol",
  previewHint: "Fereastra de imprimare se deschide automat. Alege imprimanta de etichete și setările de mai jos.",
  printSettingsTitle: "Setări de imprimare pentru eticheta Trendyol",
  printPaper: "Hârtie: A6 · 105 × 148 mm, portret",
  printScale: "Scală: 100% / Dimensiune reală (nu „Potrivire în pagină”)",
  printMargins: "Margini: niciuna · Antet și subsol: dezactivate",
  printQuality: "Calitate: cea mai bună, fără economisire de toner, pentru un cod de bare clar",
  printNow: "Imprimă",
  printAgain: "Imprimă din nou",
  openPdf: "Deschide PDF-ul",
  close: "Închide",
  parcel: "Colet {number} din {count}",
  parcels: "Această comandă are {count} colete. Imprimă eticheta fiecărui colet.",
  requested: "Cererea de imprimare a fost trimisă.",
  requestedNote: "Verifică eticheta pe imprimantă înainte de a o lipi.",
  recordFailed: "Eticheta s-a deschis, dar istoricul nu a putut fi salvat.",
  logout: "Ieșire",
  back: "Înapoi la Staff",
  station: "Stație",
  sessionExpired: "Sesiunea a expirat. Autentifică-te din nou; lista comenzilor se păstrează.",
  permissionDenied: "Acest cont nu are acces la stația AWB.",
  printDenied: "Acest cont poate vedea lista, dar nu poate imprima etichete.",
  connection: "Nu există conexiune cu serverul. Verifică rețeaua și încearcă din nou.",
  generic: "Ceva nu a funcționat. Încearcă din nou.",
  invalidQr: "Codul scanat nu este valid.",
  history: "Istoric",
  historyTitle: "Istoricul etichetei",
  historyEmpty: "Nicio imprimare încă.",
  historyRetrieved: "Etichetă descărcată",
  historyFailed: "Etichetă indisponibilă",
  historyInitial: "Imprimare cerută",
  historyReprint: "Reimprimare cerută",
} as const;

export type AwbMessageKey = keyof typeof ro;

const tr: Record<AwbMessageKey, string> = {
  title: "AWB Trendyol",
  subtitle: "Paketleme ve teslimat aşamasındaki siparişlerin kargo etiketleri",
  languageLabel: "Arayüz dili",
  online: "Bağlı",
  offline: "Bağlantı yok",
  searchLabel: "Sipariş ara veya QR kodunu okut",
  searchPlaceholder: "Sipariş ara veya QR kodunu okut",
  searchButton: "Ara",
  clearSearch: "Aramayı temizle",
  queueTitle: "Etiket bekleyen siparişler",
  queueCount: "{count} Trendyol siparişi",
  queueCountOne: "1 Trendyol siparişi",
  filterLabel: "Aşamaya göre filtrele",
  filterAll: "Tümü",
  filter13: "13. Aşama · Paketleme",
  filter14: "14. Aşama · Teslimat",
  order: "Sipariş #{number}",
  stage: "Paketleme · 13. Aşama",
  stageDelivery: "Teslimat · 14. Aşama",
  stageOther: "{ordinal}. Aşama",
  stageDone: "Üretim tamamlandı",
  print: "AWB Yazdır",
  reprint: "Tekrar Yazdır",
  printedBefore: "Daha önce yazdırıldı",
  printedBeforeTitle: "Bu etiket daha önce yazdırıldı",
  printedTimes: "{count} kez istendi",
  preparing: "Etiket hazırlanıyor…",
  loading: "Siparişler yükleniyor…",
  empty: "13. ve 14. aşamada Trendyol siparişi yok",
  emptyHint: "Trendyol siparişleri 13. aşamada (Paketleme) burada otomatik görünür ve 14. aşama (Teslimat) tamamlanana kadar kalır.",
  more: "Daha fazla sipariş göster",
  refreshing: "Güncelleniyor…",
  updatedAt: "Son güncelleme {time}",
  retry: "Tekrar Dene",
  searchResults: "Arama sonucu",
  closeResults: "Sonucu kapat",
  notInQueue: "Artık 13–14. aşamada değil",
  stopped: "Üretim durduruldu",
  packages: "Bu sipariş için {count} Trendyol paketi var",
  packageLabel: "Paket {id}",
  previewTitle: "Trendyol etiketi",
  previewHint: "Yazdırma penceresi otomatik açılır. Etiket yazıcısını seçin ve aşağıdaki ayarları kullanın.",
  printSettingsTitle: "Trendyol etiketi için yazdırma ayarları",
  printPaper: "Kağıt: A6 · 105 × 148 mm, dikey",
  printScale: "Ölçek: %100 / Gerçek boyut (\"Sayfaya sığdır\" değil)",
  printMargins: "Kenar boşluğu: yok · Üst bilgi ve alt bilgi: kapalı",
  printQuality: "Kalite: en yüksek, toner tasarrufu kapalı, barkod net çıksın",
  printNow: "Yazdır",
  printAgain: "Tekrar yazdır",
  openPdf: "PDF'yi aç",
  close: "Kapat",
  parcel: "Koli {number} / {count}",
  parcels: "Bu siparişte {count} koli var. Her kolinin etiketini yazdırın.",
  requested: "Yazdırma isteği gönderildi.",
  requestedNote: "Etiketi yapıştırmadan önce yazıcıdan çıktığını kontrol edin.",
  recordFailed: "Etiket açıldı, ancak geçmiş kaydedilemedi.",
  logout: "Çıkış",
  back: "Staff'a dön",
  station: "İstasyon",
  sessionExpired: "Oturum sona erdi. Tekrar giriş yapın; sipariş listesi korunur.",
  permissionDenied: "Bu hesabın AWB istasyonuna erişimi yok.",
  printDenied: "Bu hesap listeyi görebilir, ancak etiket yazdıramaz.",
  connection: "Sunucuya bağlantı yok. Ağı kontrol edip tekrar deneyin.",
  generic: "Bir sorun oluştu. Tekrar deneyin.",
  invalidQr: "Okutulan kod geçerli değil.",
  history: "Geçmiş",
  historyTitle: "Etiket geçmişi",
  historyEmpty: "Henüz yazdırma yok.",
  historyRetrieved: "Etiket indirildi",
  historyFailed: "Etiket alınamadı",
  historyInitial: "Yazdırma istendi",
  historyReprint: "Tekrar yazdırma istendi",
};

/** Station-specific refusals, in the employee's words. Backend codes never appear as the main message. */
const errorsRo: Record<AwbErrorCode, string> = {
  AWB_LABEL_NOT_READY: "Eticheta nu este disponibilă momentan. Trendyol nu a atribuit încă numărul AWB.",
  AWB_LABEL_NOT_AVAILABLE: "Eticheta nu este disponibilă momentan",
  AWB_LABEL_EXPIRED: "Linkul etichetei a expirat. Încearcă din nou pentru unul nou.",
  AWB_TRENDYOL_AUTH: "Trendyol a refuzat accesul. Anunță responsabilul tehnic.",
  AWB_TRENDYOL_RATE_LIMITED: "Prea multe cereri către Trendyol. Încearcă din nou peste un minut.",
  AWB_TRENDYOL_UNAVAILABLE: "Trendyol nu răspunde momentan. Încearcă din nou.",
  AWB_TRENDYOL_INVALID: "Trendyol a trimis un răspuns neașteptat. Încearcă din nou.",
  AWB_LABEL_INVALID: "Eticheta primită nu este validă și nu a fost afișată.",
  AWB_LABEL_FORMAT: "Trendyol oferă eticheta într-un format care nu se poate imprima din browser.",
  AWB_PACKAGE_UNAVAILABLE: "Trendyol nu mai returnează acest pachet.",
  AWB_PACKAGE_MISMATCH: "Pachetul Trendyol nu corespunde comenzii. Eticheta nu a fost imprimată.",
  AWB_PACKAGE_CLOSED: "Pachetul este anulat sau returnat în Trendyol. Nu există etichetă de trimis.",
  AWB_ORDER_STOPPED: "Producția acestei comenzi este oprită (anulare). Eticheta nu se imprimă.",
  AWB_LABELS_UNAVAILABLE: "Serviciul de etichete Trendyol nu este configurat.",
  AWB_ORDER_NOT_FOUND: "Nu există nicio comandă Trendyol cu acest cod.",
  AWB_QR_INACTIVE: "Acest cod QR nu mai este activ. Scanează eticheta de producție curentă.",
  AWB_CODE_INVALID: "Codul scanat nu este valid.",
  AWB_PARCEL_NOT_FOUND: "Acest colet nu are etichetă.",
  AWB_RETRIEVAL_UNKNOWN: "Descarcă din nou eticheta, apoi imprimă.",
};

const errorsTr: Record<AwbErrorCode, string> = {
  AWB_LABEL_NOT_READY: "Etiket şu anda alınamıyor. Trendyol henüz AWB numarası atamadı.",
  AWB_LABEL_NOT_AVAILABLE: "Etiket şu anda alınamıyor",
  AWB_LABEL_EXPIRED: "Etiket bağlantısının süresi doldu. Yenisi için tekrar deneyin.",
  AWB_TRENDYOL_AUTH: "Trendyol erişimi reddetti. Teknik sorumluya haber verin.",
  AWB_TRENDYOL_RATE_LIMITED: "Trendyol'a çok fazla istek gönderildi. Bir dakika sonra tekrar deneyin.",
  AWB_TRENDYOL_UNAVAILABLE: "Trendyol şu anda yanıt vermiyor. Tekrar deneyin.",
  AWB_TRENDYOL_INVALID: "Trendyol beklenmeyen bir yanıt gönderdi. Tekrar deneyin.",
  AWB_LABEL_INVALID: "Gelen etiket geçerli değil ve gösterilmedi.",
  AWB_LABEL_FORMAT: "Trendyol etiketi tarayıcıdan yazdırılamayan bir biçimde veriyor.",
  AWB_PACKAGE_UNAVAILABLE: "Trendyol bu paketi artık döndürmüyor.",
  AWB_PACKAGE_MISMATCH: "Trendyol paketi siparişle eşleşmiyor. Etiket yazdırılmadı.",
  AWB_PACKAGE_CLOSED: "Paket Trendyol'da iptal edilmiş veya iade edilmiş. Gönderilecek etiket yok.",
  AWB_ORDER_STOPPED: "Bu siparişin üretimi durduruldu (iptal). Etiket yazdırılmaz.",
  AWB_LABELS_UNAVAILABLE: "Trendyol etiket servisi yapılandırılmamış.",
  AWB_ORDER_NOT_FOUND: "Bu koda ait Trendyol siparişi yok.",
  AWB_QR_INACTIVE: "Bu QR kodu artık geçerli değil. Güncel üretim etiketini okutun.",
  AWB_CODE_INVALID: "Okutulan kod geçerli değil.",
  AWB_PARCEL_NOT_FOUND: "Bu kolinin etiketi yok.",
  AWB_RETRIEVAL_UNKNOWN: "Etiketi yeniden indirin, sonra yazdırın.",
};

const catalogs: Record<AwbLocale, Record<AwbMessageKey, string>> = { ro, tr };
const errorCatalogs: Record<AwbLocale, Record<AwbErrorCode, string>> = { ro: errorsRo, tr: errorsTr };

export function awbText(locale: AwbLocale, key: AwbMessageKey, params?: Record<string, string | number>): string {
  const text = catalogs[locale][key] ?? ro[key];
  return params ? text.replace(/\{(\w+)\}/g, (match, name: string) => Object.hasOwn(params, name) ? String(params[name]) : match) : text;
}

export function awbErrorText(locale: AwbLocale, code: AwbErrorCode): string {
  return errorCatalogs[locale][code];
}

export function readAwbLocale(): AwbLocale {
  try { return globalThis.localStorage?.getItem(AWB_LOCALE_KEY) === "tr" ? "tr" : "ro"; }
  catch { return "ro"; }
}

export type AwbStageFilterChoice = "all" | "13" | "14";
export const AWB_STAGE_FILTER_KEY = "arasya.awb.stage";

/** The stage filter of this browser (a per-station convenience); every stage is shown by default. */
export function readAwbStageFilter(): AwbStageFilterChoice {
  try { const value = globalThis.localStorage?.getItem(AWB_STAGE_FILTER_KEY); return value === "13" || value === "14" ? value : "all"; }
  catch { return "all"; }
}

export function saveAwbStageFilter(filter: AwbStageFilterChoice): void {
  try { globalThis.localStorage?.setItem(AWB_STAGE_FILTER_KEY, filter); }
  catch { /* Without browser storage the next visit shows every stage. */ }
}

export function saveAwbLocale(locale: AwbLocale): void {
  try { globalThis.localStorage?.setItem(AWB_LOCALE_KEY, locale); }
  catch { /* The station works without browser storage; the next visit starts in Romanian. */ }
}

export const awbCatalogs = catalogs;
export const awbErrorCatalogs = errorCatalogs;
