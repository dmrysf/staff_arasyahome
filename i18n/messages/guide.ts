/**
 * Contextual employee guide (Staff 2.38.0). Romanian and Turkish only, chosen by the employee inside the guide and
 * independent of the Staff interface language. Every text describes a screen, a button or a rule that exists in the
 * code of this release; a line with `need` is shown only to an employee who holds one of those capabilities (central
 * IAM, read from the session), so nobody is told about a button they do not see. The guide explains; it never acts.
 */
export const GUIDE_LANGS = ["ro", "tr"] as const;
export type GuideLang = (typeof GUIDE_LANGS)[number];
export type GuideText = Readonly<Record<GuideLang, string>>;

/** What the signed-in employee may do, derived from the session (domain/guide.ts). */
export type GuideNeed =
  | "scan" | "claim" | "advance" | "reportFault" | "cuttingIntake"
  | "cfOperate" | "cfSupervise" | "finRecord" | "finApprove" | "finAny" | "invIssue" | "invManage" | "shopTasks" | "health" | "handoff"
  | "docGenerate" | "docRevision" | "docReprint" | "authority" | "dashboardLink";

export type GuideLine = Readonly<{ text: GuideText; need?: readonly GuideNeed[] }>;
export type GuideTopic = Readonly<{
  title: GuideText;
  /** Two short lines for the home card. */
  summary: GuideText;
  purpose: GuideText;
  steps: readonly GuideLine[];
  after: readonly GuideLine[];
  caution?: readonly GuideLine[];
  details?: readonly GuideLine[];
}>;

const line = (ro: string, tr: string, need?: readonly GuideNeed[]): GuideLine => need ? { text: { ro, tr }, need } : { text: { ro, tr } };
const text = (ro: string, tr: string): GuideText => ({ ro, tr });

/** Each language names itself, so it can be found whatever is shown now. */
export const guideLangNames: GuideText = { ro: "Română", tr: "Türkçe" };

export const guideChrome = {
  open: text("Cum funcționează?", "Nasıl çalışır?"),
  dialogTitle: text("Cum funcționează această secțiune?", "Bu bölüm nasıl çalışır?"),
  section: text("Secțiunea", "Bölüm"),
  purpose: text("La ce folosește", "Burası ne işe yarıyor?"),
  steps: text("Ce ai de făcut", "Senin yapacağın iş"),
  after: text("Ce se întâmplă apoi", "Sonra ne olur?"),
  caution: text("Atenție", "Dikkat etmen gereken"),
  showDetails: text("Vezi detalii", "Ayrıntıları göster"),
  hideDetails: text("Ascunde detaliile", "Ayrıntıları gizle"),
  close: text("Închide", "Kapat"),
  language: text("Limba ghidului", "Rehber dili"),
  languageNote: text("Schimbă doar limba ghidului. Limba aplicației Staff rămâne cea din contul tău.", "Yalnız rehberin dilini değiştirir. Staff uygulamasının dili hesabındaki gibi kalır."),
  scopes: text("Drepturile tale aici", "Buradaki yetkilerin"),
  stages: text("Etapele tale", "Senin aşamaların"),
  explainsOnly: text("Ghidul doar explică. Nu face nicio operațiune.", "Rehber yalnız açıklar. Hiçbir işlem yapmaz."),
  workspace: text("Spațiul tău de lucru", "Çalışma alanın"),
  cardShow: text("Arată explicația spațiului de lucru", "Çalışma alanı açıklamasını göster"),
  cardHide: text("Ascunde explicația", "Açıklamayı gizle"),
  overviewTitle: text("ARASYA — Sistem central de lucru", "ARASYA — Merkezi Çalışma Sistemi"),
  overviewBody: text(
    "Arasya este sistemul central în care comenzile sunt verificate, plata lor este urmărită, trec prin etapele de producție și sunt pregătite de livrare. Vezi și lucrezi doar la sarcinile pentru care ai drepturi.",
    "Arasya, siparişlerin kontrol edildiği, ödemelerinin takip edildiği, üretim aşamalarından geçtiği ve teslimata hazırlandığı merkezi çalışma sistemidir. Yalnızca yetkili olduğun görevleri görür ve yönetirsin.",
  ),
  overviewNote: text(
    "Nu orice comandă trece prin fiecare pas: comenzile Trendyol sunt pregătite în spațiul Trendyol, nu în Confirmare, iar o comandă plătită online nu are avans.",
    "Her sipariş her adımdan geçmez: Trendyol siparişleri Confirmare yerine Trendyol alanında hazırlanır; online ödenmiş siparişte avans adımı yoktur.",
  ),
  yourArea: text("zona ta", "senin alanın"),
  flowLabel: text("Drumul unei comenzi", "Bir siparişin yolu"),
  metricsHelp: text("Ce înseamnă aceste cifre?", "Bu sayılar ne anlama geliyor?"),
} as const;

export const FLOW_STEPS = ["order", "confirmare", "finance", "production", "quality", "packing", "delivery"] as const;
export type FlowStep = (typeof FLOW_STEPS)[number];
export const flowLabels: Readonly<Record<FlowStep, GuideText>> = {
  order: text("Comandă", "Sipariş"),
  confirmare: text("Confirmare", "Confirmare"),
  finance: text("Control financiar", "Finans kontrolü"),
  production: text("Producție", "Üretim"),
  quality: text("Control calitate", "Kalite kontrol"),
  packing: text("Ambalare", "Paketleme"),
  delivery: text("Livrare", "Teslimat"),
};

/** Scope lines, filled with the shop names the employee holds ({stores}). */
export const scopeLabels = {
  cfOperate: text("Confirmi comenzi: {stores}", "Sipariş onaylarsın: {stores}"),
  cfSupervise: text("Supraveghezi echipa Confirmare: {stores}", "Confirmare ekibini denetlersin: {stores}"),
  finRecord: text("Înregistrezi plăți (Finance Record): {stores}", "Ödeme kaydedersin (Finance Record): {stores}"),
  finApprove: text("Verifici plăți (Finance Approve): {stores}", "Ödeme doğrularsın (Finance Approve): {stores}"),
  finView: text("Vezi situația financiară: {stores}", "Finansal durumu görürsün: {stores}"),
  invIssue: text("Emiți factura finală: {stores}", "Nihai faturayı kesersin: {stores}"),
  invManage: text("Manager facturi (Invoice Manager): {stores}", "Fatura yöneticisi (Invoice Manager): {stores}"),
} as const;

/** One short line per canonical stage, shown only for the stages the employee holds. */
export const stageLines: Readonly<Record<string, GuideText>> = {
  "waiting": text("Pornire: comanda așteaptă documentul de producție și predarea la tăiere.", "Başlangıç: sipariş üretim fişini ve kesime teslimi bekler."),
  "material-preparation": text("Tăiere: scanezi QR-ul original o dată, tai după document și închizi etapa din comandă.", "Kesim: orijinal QR'ı bir kez okutursun, fişe göre keser ve aşamayı siparişten tamamlarsın."),
  "workshop-receiving": text("Recepție atelier: compari produsele primite cu documentul de producție.", "Atölye teslim alma: gelen ürünleri üretim fişiyle karşılaştırırsın."),
  "labeling": text("Etichetare: verifici produsele și cantitățile, apoi etichetezi după document.", "Etiketleme: ürünleri ve adetleri kontrol eder, fişe göre etiketlersin."),
  "material-straightening": text("Îndreptarea materialului: verifici dimensiunile din document înainte de lucru.", "Kumaş düzeltme: çalışmadan önce fişteki ölçüleri kontrol edersin."),
  "bottom-hem": text("Tiv jos: respecți înălțimea aprobată în documentul de producție.", "Alt etek: üretim fişinde onaylanan boya uyarsın."),
  "side-hem": text("Tiv lateral: respecți lățimea aprobată în document.", "Yan etek: fişte onaylanan ene uyarsın."),
  "ironing": text("Călcare: verifici notele de producție înainte de călcare.", "Ütüleme: ütülemeden önce üretim notlarını kontrol edersin."),
  "height": text("Înălțime: folosești doar înălțimea aprobată în document; dimensiunile nu se schimbă în aplicație.", "Yükseklik: yalnız fişte onaylanan boyu kullanırsın; ölçüler uygulamada değişmez."),
  "header-tape": text("Rejansă: verifici tipul de rejansă din notele de producție.", "Rejansa: büzgü bandı türünü üretim notlarından kontrol edersin."),
  "sewing-finishing": text("Finisare cusătură: urmezi instrucțiunile de finisare din document.", "Dikiş bitirme: fişteki bitirme talimatlarına uyarsın."),
  "quality-control": text("Control calitate: compari produsul cu documentul (dimensiuni, cantitate, culoare).", "Kalite kontrol: ürünü fişle karşılaştırırsın (ölçü, adet, renk)."),
  "packing": text("Ambalare: verifici toate produsele și cantitățile înainte de ambalare.", "Paketleme: paketlemeden önce tüm ürünleri ve adetleri kontrol edersin."),
  "delivery": text("Livrare: ultima etapă internă; închiderea ei încheie producția în Arasya.", "Teslimat: son iç aşama; tamamlanması Arasya'da üretimi bitirir."),
};

/** The management metric names exactly as the Staff screen shows them, for the glossary in the guide. */
export const managementLabels: Readonly<Record<"active" | "waiting" | "inWork" | "unassigned" | "completedToday", GuideText>> = {
  active: text("Comenzi active", "Aktif siparişler"),
  waiting: text("În așteptare", "Beklemede"),
  inWork: text("În lucru", "Devam eden"),
  unassigned: text("Nepreluate", "Üstlenilmemiş"),
  completedToday: text("Finalizate azi", "Bugün tamamlanan"),
};

/** Management overview: what each number of GET /management/production-overview counts (ProductionOverviewService). */
export const managementTerms: Readonly<Record<"active" | "waiting" | "inWork" | "unassigned" | "completedToday" | "stageActive" | "stageUnassigned" | "stageOldest" | "legacy", GuideText>> = {
  active: text("Comenzi noi cu producția neterminată, neanulate și neoprite.", "Üretimi bitmemiş, iptal edilmemiş ve durdurulmamış yeni sistem siparişleri."),
  waiting: text("Încă la etapa 1: așteaptă confirmarea, plata, documentul sau scanarea la tăiere.", "Hâlâ 1. aşamada: onay, ödeme, fiş veya kesimin QR okutmasını bekler."),
  inWork: text("Au trecut de etapa 1 și sunt de la tăiere încolo.", "1. aşamayı geçmiş, kesimden sonraki aşamalardaki siparişler."),
  unassigned: text("Nu sunt acum la niciun angajat. La etapa 1 e normal.", "Şu an hiçbir çalışanın üzerinde değil. 1. aşamada bu normaldir."),
  completedToday: text("Producție încheiată azi (ora României), chiar dacă au fost anulate apoi.", "Bugün (Romanya saati) üretimi biten siparişler; sonradan iptal edilse de sayılır."),
  stageActive: text("Activ: comenzile aflate acum la etapă.", "Aktif: şu an o aşamadaki siparişler."),
  stageUnassigned: text("Nepreluate: comenzile etapei pe care nu le-a preluat nimeni.", "Üstlenilmemiş: o aşamada kimsenin üstlenmediği siparişler."),
  stageOldest: text("Cea mai veche: când a ajuns la etapă comanda care așteaptă de cel mai mult timp.", "En eski: o aşamada en uzun bekleyen siparişin aşamaya geldiği zaman."),
  legacy: text("Comenzile vechi Trend Home / Outlet Perdele gestionate încă în WordPress nu intră în cifre; sunt numărate separat, sub tabel.", "WordPress'te yönetilmeye devam eden eski Trend Home / Outlet Perdele siparişleri sayılara dahil değildir; ayrıca, alt notta sayılır."),
};

const PRODUCTION_RULES: readonly GuideLine[] = [
  line("Etapele merg în ordine. Etapa următoare o stabilește sistemul, nu tu.", "Aşamalar sırayla ilerler. Sonraki aşamayı sen değil, sistem belirler."),
  line("Poți lucra doar la etapele tale. O comandă preluată de un coleg o termină el.", "Yalnız kendi aşamalarında çalışabilirsin. Bir arkadaşının üstlendiği siparişi o bitirir."),
  line("În comandă vezi doar operațiunea pe care serverul ți-o permite acum.", "Siparişte yalnız sunucunun şu an izin verdiği işlemi görürsün."),
];
const COMPLETE_STAGE = line("Când ai terminat, deschide comanda și apasă „Finalizează etapa”: comanda trece singură la etapa următoare.", "İşin bitince siparişi aç ve „Aşamayı tamamla”ya bas: sipariş kendiliğinden sonraki aşamaya geçer.", ["advance"]);
const CLAIM = line("Deschide comanda (din listă sau scanând QR-ul) și preia-o: „Preia comanda”.", "Siparişi aç (listeden veya QR okutarak) ve üstlen: „Siparişi üstlen”.", ["claim"]);
const REPORT_FAULT = line("Dacă găsești o problemă, folosește în comandă „Raportează o problemă”: supervizorul croitoriei verifică și decide.", "Bir sorun bulursan siparişte „Sorun bildir”i kullan: terzi şefi kontrol eder ve karar verir.", ["reportFault"]);

const CONFIRMARE_PAYMENT_RULE = line(
  "Confirmarea telefonică NU înseamnă că plata e verificată. Producția pornește doar când și condiția de plată e îndeplinită: plata online verificată sau avansul de 30% verificat de finanțe.",
  "Telefonla onay, ödemenin doğrulandığı anlamına GELMEZ. Üretim ancak ödeme koşulu da sağlanınca başlar: doğrulanmış online ödeme veya finansın doğruladığı %30 avans.",
);
const FOUR_EYES = line(
  "Dacă un coleg înregistrează o plată, plata NU este încă verificată. Un alt angajat o verifică în extrasul bancar; propria înregistrare nu o poți verifica.",
  "Bir kişinin ödeme kaydetmesi, ödemenin doğrulandığı anlamına GELMEZ. Başka bir çalışan banka ekstresinde doğrular; kendi kaydını kendin onaylayamazsın.",
);
const NO_DUPLICATE_INVOICE = line(
  "Dacă rezultatul unei facturi sau proforme este „necunoscut”, nu o emite din nou: s-ar crea un document dublu. Managerul de facturi verifică în SmartBill și închide rezultatul.",
  "Bir faturanın veya proformanın sonucu „bilinmiyor” ise tekrar kesme: mükerrer belge oluşur. Fatura yöneticisi SmartBill'de kontrol eder ve sonucu kapatır.",
);
const INVOICE_MANAGER = line(
  "Ca manager de facturi închizi în comandă rezultatele necunoscute („Închide rezultatul necunoscut”), după ce ai verificat în SmartBill, și aprobi reemiterea când e nevoie.",
  "Fatura yöneticisi olarak sonucu bilinmeyen işlemleri SmartBill'de kontrol ettikten sonra siparişte „Bilinmeyen sonucu kapat” ile kapatırsın; gerektiğinde yeniden düzenlemeyi onaylarsın.",
  ["invManage"],
);
const FINAL_INVOICE = line(
  "Când avansul e verificat, comanda e direcționată la tine: scanezi QR-ul documentului de producție, apoi emiți o singură factură finală SmartBill din comandă.",
  "Avans doğrulanınca sipariş sana yönlendirilir: üretim fişinin QR'ını okutur, sonra siparişten tek bir nihai SmartBill faturası kesersin.",
  ["invIssue"],
);
const MANAGER_NOT_FINANCE = line(
  "Managerul de facturi și aprobarea financiară sunt drepturi separate: verificarea unei plăți nu emite factura, iar factura nu verifică plata.",
  "Fatura yöneticiliği ve finans onayı ayrı yetkilerdir: ödemeyi doğrulamak fatura kesmez, fatura da ödemeyi doğrulamaz.",
  ["invManage"],
);

export const guideTopics = {
  "overview": {
    title: guideChrome.overviewTitle,
    summary: text("Comenzi, verificări, producție și livrare într-un singur sistem.", "Siparişler, kontroller, üretim ve teslimat tek sistemde."),
    purpose: guideChrome.overviewBody,
    steps: [line("Lucrează din spațiul tău de lucru: acolo vezi doar comenzile și sarcinile tale.", "Çalışma alanından çalış: orada yalnız kendi siparişlerini ve görevlerini görürsün.")],
    after: [line("Fiecare operațiune confirmată de server apare în istoricul tău.", "Sunucunun onayladığı her işlem geçmişinde görünür.")],
  },

  "ws.confirmare": {
    title: text("Confirmare comenzi", "Confirmare — sipariş onayı"),
    summary: text("Confirmi telefonic comenzile noi Trend Home și Outlet Perdele înainte de producție.", "Yeni Trend Home ve Outlet Perdele siparişlerini üretimden önce telefonla doğrularsın."),
    purpose: text(
      "Aici confirmi telefonic, cu clientul, comenzile noi Trend Home și Outlet Perdele înainte de producție. Serverul împarte singur comenzile, după data de livrare.",
      "Burada yeni Trend Home ve Outlet Perdele siparişlerini üretimden önce müşteriyle telefonda doğrularsın. Siparişleri sunucu, teslim tarihine göre kendisi dağıtır.",
    ),
    steps: [
      line("Deschide comanda atribuită (din „Comenzile mele”) și sună clientul: confirmă fiecare produs, dimensiunile și cantitatea și verifică prețurile în comanda din magazin.", "Sana atanan siparişi („Siparişlerim”) aç ve müşteriyi ara: her ürünü, ölçüleri ve adedi teyit et, fiyatları mağazadaki siparişte kontrol et.", ["cfOperate"]),
      line("Înregistrează rezultatul apelului: Confirmat, Nu răspunde, De revenit sau Necesită corectură.", "Arama sonucunu kaydet: Onaylandı, Cevap vermiyor, Tekrar aranacak veya Düzeltme gerekiyor.", ["cfOperate"]),
      line("Când totul e bifat, apasă „Aprobă pentru producție”; o comandă greșită o respingi cu motivul potrivit.", "Her şey işaretlenince „Üretim için onayla”ya bas; geçersiz siparişi uygun nedenle reddet.", ["cfOperate"]),
      line("Urmărești echipa în „Echipa și alertele”: distribuirea, concediile și întârzierile; poți reatribui o comandă.", "„Ekip ve uyarılar” ekranında dağıtımı, izinleri ve gecikmeleri izlersin; siparişi başka çalışana atayabilirsin.", ["cfSupervise"]),
    ],
    after: [
      line("Comanda aprobată merge mai departe la verificarea plății și la documentul de producție; nu intră direct la tăiere.", "Onaylanan sipariş ödeme kontrolüne ve üretim fişine geçer; doğrudan kesime girmez."),
    ],
    caution: [CONFIRMARE_PAYMENT_RULE],
    details: [
      line("Un client care nu răspunde nu înseamnă anulare. După al treilea apel fără răspuns, proforma de avans pleacă automat pe e-mail la client (comenzi cu plata la livrare).", "Cevap vermeyen müşteri iptal anlamına gelmez. Üçüncü cevapsız aramadan sonra avans proforması müşteriye otomatik e-postayla gider (kapıda ödemeli siparişler).", ["cfOperate"]),
      line("Dacă clientul acceptă avansul la telefon, emiți și trimiți proforma de avans din comandă. Proforma nu este plată și nu este factură fiscală.", "Müşteri avansı telefonda kabul ederse avans proformasını siparişten keser ve gönderirsin. Proforma ödeme değildir, mali fatura da değildir.", ["cfOperate"]),
      line("„Necesită corectură”: comanda se corectează în magazin, apoi suni din nou clientul.", "„Düzeltme gerekiyor”: sipariş mağazada düzeltilir, sonra müşteriyi tekrar ararsın.", ["cfOperate"]),
      line("Deschiderea comenzii nu este un apel. Înregistrează doar apelul pe care l-ai făcut cu adevărat.", "Siparişi açmak arama sayılmaz. Yalnız gerçekten yaptığın aramayı kaydet.", ["cfOperate"]),
      line("O comandă respinsă o redeschide doar un supervizor.", "Reddedilen siparişi yalnız bir süpervizör yeniden açar."),
    ],
  },

  "ws.confirmare.finance": {
    title: text("Finanțe Confirmare", "Confirmare finans"),
    summary: text("Înregistrezi și verifici plățile prin bancă ale comenzilor Confirmare.", "Confirmare siparişlerinin banka ödemelerini kaydeder ve doğrularsın."),
    purpose: text(
      "Aici se iau deciziile despre bani ale comenzilor Confirmare: transferuri bancare, avansuri pentru plata la livrare, plăți suplimentare și rambursări. Sumele se citesc din magazin (WooCommerce) la fiecare deschidere.",
      "Confirmare siparişlerinin para kararları burada verilir: banka havaleleri, kapıda ödeme avansları, ek ödemeler ve iadeler. Tutarlar her açılışta mağazadan (WooCommerce) okunur.",
    ),
    steps: [
      line("Apasă „Deschide finanțele” și începe cu sarcinile de sus: întâi operațiunile neîncheiate și reconcilierile.", "„Finansı aç” ve listenin en üstündeki görevlerden başla: önce sonuçlanmamış işlemler ve mutabakat."),
      line("Înregistrare (Finance Record): când transferul apare în extrasul bancar, îl înregistrezi în comandă cu suma și referința.", "Kaydetme (Finance Record): havale banka ekstresinde görününce siparişte tutar ve referansla kaydedersin.", ["finRecord"]),
      line("Verificare (Finance Approve): cauți în extras transferul înregistrat de un coleg; îl aprobi dacă e corect, altfel îl respingi cu motiv.", "Doğrulama (Finance Approve): bir arkadaşının kaydettiği havaleyi ekstrede bulursun; doğruysa onaylar, değilse nedeniyle reddedersin.", ["finApprove"]),
      line("Pentru proformele de avans expirate confirmi verificarea extrasului bancar; abia apoi sunt anulate.", "Süresi dolan avans proformaları için banka ekstresi kontrolünü onaylarsın; ancak ondan sonra iptal edilirler.", ["finRecord", "finApprove"]),
    ],
    after: [
      line("Plata contează ca primită doar după verificarea celui de-al doilea angajat. Când avansul este complet, comanda e liberă financiar și merge la angajatul magazinului care emite factura finală.", "Ödeme ancak ikinci çalışan doğrulayınca alınmış sayılır. Avans tamamlanınca sipariş finans açısından serbest kalır ve nihai faturayı kesen mağaza çalışanına yönlendirilir."),
    ],
    caution: [FOUR_EYES],
    details: [
      line("Avans incomplet: comanda rămâne la „Avans parțial” sau „În așteptarea avansului”, iar producția așteaptă.", "Eksik avans: sipariş „Kısmi avans” veya „Avans bekleniyor” görevinde kalır, üretim bekler."),
      line("Un transfer care nu se potrivește cu nicio comandă se înregistrează la „Transferuri neidentificate”; îl închide un alt angajat de la finanțe.", "Hiçbir siparişle eşleşmeyen havale „Eşleşmeyen havaleler” bölümüne kaydedilir; başka bir finans çalışanı kapatır.", ["finRecord", "finApprove"]),
      line("Rambursare: dacă clientul a plătit mai mult, obligația se confirmă, iar rambursarea se face prin transfer abia după terminarea producției și se verifică tot de alt angajat. Nu se fac rambursări electronice.", "İade: müşteri fazla ödediyse yükümlülük onaylanır; iade üretim bittikten sonra banka havalesiyle yapılır ve yine başka bir çalışan doğrular. Elektronik iade yapılmaz."),
      line("Starea comenzii în magazin nu este dovadă de plată.", "Mağazadaki sipariş durumu ödeme kanıtı değildir."),
      MANAGER_NOT_FINANCE,
    ],
  },

  "ws.trendyol": {
    title: text("Operațiuni Trendyol", "Trendyol işlemleri"),
    summary: text("Pregătești comenzile Trendyol (dimensiuni, denumire) înainte de intrarea în producție.", "Trendyol siparişlerini üretime girmeden önce hazırlarsın (ölçü, ad)."),
    purpose: text(
      "Comenzile noi Trendyol ajung aici, nu direct în fabrică. Le pregătești pentru producție; statusurile, curierul și facturile rămân în Trendyol Seller Panel.",
      "Yeni Trendyol siparişleri doğrudan fabrikaya değil, buraya gelir. Onları üretime hazırlarsın; durumlar, kargo ve faturalar Trendyol Seller Panel'de kalır.",
    ),
    steps: [
      line("Deschide comanda din „Comenzi de pregătit”.", "Siparişi „Hazırlanacak siparişler” listesinden aç."),
      line("Introdu dimensiunile și scrie denumirea de producție în română.", "Ölçüleri gir ve üretim adını Rumence yaz."),
      line("Când toate rândurile sunt confirmate, o persoană cu drept de aprobare Trendyol trimite comanda în producție.", "Tüm satırlar onaylanınca Trendyol onay yetkisi olan kişi siparişi üretime gönderir."),
    ],
    after: [line("Comanda aprobată intră în producție la etapa 1 și urmează etapele obișnuite.", "Onaylanan sipariş 1. aşamadan üretime girer ve normal aşamaları izler.")],
    caution: [line("Comenzile din „Necesită atenție” nu pot intra în producție cu statusul lor actual din Trendyol (plată neconfirmată, gata de expediere sau status necunoscut).", "„Dikkat gerekli” bölümündeki siparişler Trendyol'daki mevcut durumlarıyla üretime giremez (ödeme onaylanmadı, kargoya hazır veya bilinmeyen durum).")],
  },

  "ws.documente": {
    title: text("Documente de producție", "Üretim belgeleri"),
    summary: text("Generezi, tipărești și revizuiești documentul de producție cu QR.", "QR kodlu üretim fişini oluşturur, yazdırır ve revize edersin."),
    purpose: text(
      "Aici pregătești documentul de producție cu QR: fără el comanda nu poate fi preluată la tăiere.",
      "Burada QR kodlu üretim fişini hazırlarsın: fiş olmadan sipariş kesimde alınamaz.",
    ),
    steps: [
      line("Caută comanda și generează REVIZIA 1 a documentului.", "Siparişi bul ve fişin REVİZYON 1'ini oluştur.", ["docGenerate"]),
      line("Descarcă PDF-ul și tipărește-l la imprimanta atelierului; documentul merge împreună cu produsul.", "PDF'i indir, atölye yazıcısından yazdır; fiş ürünle birlikte gider.", ["docGenerate", "docReprint"]),
      line("Dacă documentul s-a schimbat, ceri o revizie nouă; ea trebuie aprobată înainte de tipărire.", "Fiş değiştiyse yeni revizyon istersin; yazdırmadan önce onaylanmalıdır.", ["docRevision"]),
      line("În „Predare în producție” vezi comenzile Confirmare aprobate și plătite care au nevoie de document.", "„Üretime devir” listesinde fiş bekleyen onaylı ve ödenmiş Confirmare siparişlerini görürsün.", ["handoff"]),
    ],
    after: [line("Tăierea scanează QR-ul de pe hârtie și preia comanda; atunci începe producția.", "Kesim kâğıttaki QR'ı okutup siparişi alır; üretim o zaman başlar.")],
    caution: [line("Previzualizarea nu are QR și nu se folosește în atelier. Retipărirea cere mereu un motiv.", "Önizlemede QR yoktur ve atölyede kullanılmaz. Yeniden yazdırma her zaman bir neden ister.")],
    details: [FINAL_INVOICE, line("De aici deschizi și lista AWB și urmărirea e-Factura ale magazinului tău.", "Mağazanın AWB listesini ve e-Factura takibini de buradan açarsın.", ["shopTasks"])],
  },

  "ws.management": {
    title: text("Management producție", "Üretim yönetimi"),
    summary: text("Imagine de ansamblu a producției, doar citire.", "Üretimin genel görünümü, yalnızca okuma."),
    purpose: text(
      "Aici vezi cifrele întregii producții, pe etape. Ecranul este doar pentru citire: cifrele nu sunt butoane și nu schimbă nimic.",
      "Burada tüm üretimin aşama bazında sayılarını görürsün. Ekran yalnızca okumadır: sayılar düğme değildir ve hiçbir şeyi değiştirmez.",
    ),
    steps: [
      line("Urmărește „În așteptare” și „Nepreluate”: arată unde stau comenzile.", "„Beklemede” ve „Üstlenilmemiş” sayılarını izle: siparişlerin nerede beklediğini gösterir."),
      line("În tabelul pe etape, coloana „Cea mai veche” arată etapa în care o comandă așteaptă de mult.", "Aşama tablosundaki „En eski” sütunu, bir siparişin uzun süredir beklediği aşamayı gösterir."),
      line("Pentru detalii despre comenzi, angajați și excepții deschide Dashboard.", "Sipariş, çalışan ve istisna ayrıntıları için Dashboard'u aç.", ["dashboardLink"]),
      line("„Autoritate producție” mută o singură comandă din YD SOFT în Arasya, la etapa aleasă de tine.", "„Üretim yetkisi”, tek bir siparişi senin seçtiğin aşamayla YD SOFT'tan Arasya'ya alır.", ["authority"]),
    ],
    after: [line("Cifrele se actualizează la „Actualizează” și când sistemul anunță o schimbare.", "Sayılar „Güncelle” ile ve sistem bir değişiklik bildirdiğinde yenilenir.")],
    caution: [line("Comenzile vechi gestionate în WordPress nu intră în cifre; sunt numărate separat.", "WordPress'te yönetilen eski siparişler sayılara dahil değildir; ayrıca sayılır.")],
  },

  "ws.general": {
    title: text("Panoul meu", "Panelim"),
    summary: text("Contul tău nu are încă un spațiu de lucru de producție.", "Hesabının henüz bir üretim çalışma alanı yok."),
    purpose: text(
      "Contului tău nu i s-a atribuit încă nicio etapă de producție sau alt departament.",
      "Hesabına henüz bir üretim aşaması veya başka bir departman atanmadı.",
    ),
    steps: [
      line("Dacă ai primit o comandă pe hârtie, scanează QR-ul: vezi ce poți face cu ea.", "Elinde kâğıt fiş varsa QR'ı okut: onunla ne yapabileceğini görürsün.", ["scan"]),
      line("Pentru acces, cere-i managerului tău drepturile în Dashboard.", "Erişim için yöneticinden Dashboard üzerinden yetki iste."),
    ],
    after: [line("După ce primești drepturi, spațiul tău de lucru apare automat la următoarea deschidere.", "Yetki verildikten sonra çalışma alanın bir sonraki açılışta kendiliğinden görünür.")],
  },

  "ws.pornire": {
    title: text("Pornire producție", "Üretim başlangıcı"),
    summary: text("Comenzi intrate în producție care așteaptă predarea la tăiere.", "Üretime girmiş, kesime teslim edilmeyi bekleyen siparişler."),
    purpose: text("Etapa 1: comenzile intrate în producție așteaptă aici documentul de producție și predarea la tăiere.", "1. aşama: üretime giren siparişler burada üretim fişini ve kesime teslimi bekler."),
    steps: [
      line("Verifică sursa comenzii și documentul de producție activ.", "Siparişin kaynağını ve aktif üretim fişini kontrol et."),
      CLAIM,
      line("Închide etapa ca să trimiți comanda la tăiere; un angajat de la tăiere o poate prelua și direct, scanând QR-ul.", "Siparişi kesime göndermek için aşamayı tamamla; kesimdeki çalışan QR'ı okutarak siparişi doğrudan da alabilir.", ["advance"]),
    ],
    after: [line("Comanda trece la Tăiere.", "Sipariş Kesim aşamasına geçer.")],
    caution: [line("O comandă Confirmare nu trece mai departe fără document de producție tipărit și fără condiția de plată îndeplinită.", "Confirmare siparişi, yazdırılmış üretim fişi ve sağlanmış ödeme koşulu olmadan ilerlemez.")],
    details: PRODUCTION_RULES,
  },

  "ws.taiere": {
    title: text("Tăiere", "Kesim"),
    summary: text("Preiei comanda scanând QR-ul original, tai după document și o predai atelierului.", "Siparişi orijinal QR'la alır, fişe göre keser ve atölyeye teslim edersin."),
    purpose: text("Aici sunt comenzile gata de tăiere. Le preiei doar scanând QR-ul original de pe documentul de producție.", "Kesime hazır siparişler buradadır. Onları yalnız üretim fişindeki orijinal QR'ı okutarak alırsın."),
    steps: [
      line("Scanează o singură dată QR-ul original: comanda îți este atribuită și tăierea începe.", "Orijinal QR'ı bir kez okut: sipariş sana atanır ve kesim başlar.", ["scan"]),
      line("Verifică dimensiunile și consumul în documentul de producție.", "Ölçüleri ve tüketimi üretim fişinden kontrol et."),
      line("După tăiere, deschide comanda și apasă „Finalizează etapa”. Scanarea singură nu încheie tăierea.", "Kesimden sonra siparişi aç ve „Aşamayı tamamla”ya bas. Okutmak tek başına kesimi bitirmez.", ["advance"]),
      line("Dacă ești bolnav sau indisponibil, predă întreaga comandă prin „Transfer de tăiere”.", "Hastalık veya müsait olmama halinde siparişin tamamını „Kesim devri” ile devret.", ["cuttingIntake"]),
    ],
    after: [line("Comanda trece la Recepție atelier.", "Sipariş Atölye teslim alma aşamasına geçer.")],
    details: PRODUCTION_RULES,
  },

  "ws.atelier": {
    title: text("Atelier · pregătire", "Atölye · hazırlık"),
    summary: text("Recepție, etichetare și îndreptarea materialului înainte de croitorie.", "Terzihaneden önce teslim alma, etiketleme ve kumaş düzeltme."),
    purpose: text("Aici primești produsele tăiate, le etichetezi și pregătești materialul pentru croitorie.", "Burada kesilen ürünleri teslim alır, etiketler ve kumaşı terzihaneye hazırlarsın."),
    steps: [
      CLAIM,
      line("Compară produsele cu documentul de producție.", "Ürünleri üretim fişiyle karşılaştır."),
      line("La recepție, dacă tăierea e greșită, folosește în comandă „Returnează la Tăiere” sau „Raportează o problemă”.", "Teslim almada kesim yanlışsa siparişte „Kesime iade et” veya „Sorun bildir”i kullan.", ["reportFault"]),
      COMPLETE_STAGE,
    ],
    after: [line("Comanda trece la etapa următoare din atelier, apoi la croitorie.", "Sipariş atölyedeki sonraki aşamaya, ardından terzihaneye geçer.")],
    details: PRODUCTION_RULES,
  },

  "ws.croitorie": {
    title: text("Croitorie", "Terzihane"),
    summary: text("Tivuri, călcare, înălțime, rejansă și finisare, după documentul aprobat.", "Etekler, ütü, boy, rejansa ve bitirme; onaylı fişe göre."),
    purpose: text("Aici se coase perdeaua după documentul de producție aprobat, etapă cu etapă.", "Perde burada onaylı üretim fişine göre, aşama aşama dikilir."),
    steps: [CLAIM, line("Lucrează doar după dimensiunile aprobate în document; ele nu se schimbă în aplicație.", "Yalnız fişte onaylanan ölçülerle çalış; ölçüler uygulamada değişmez."), REPORT_FAULT, COMPLETE_STAGE],
    after: [line("După finisare, comanda merge la Control calitate.", "Bitirmeden sonra sipariş Kalite kontrole gider.")],
    details: PRODUCTION_RULES,
  },

  "ws.control-calitate": {
    title: text("Control calitate", "Kalite kontrol"),
    summary: text("Verifici produsul finit înainte de ambalare.", "Bitmiş ürünü paketlemeden önce kontrol edersin."),
    purpose: text("Ultima verificare înainte de ambalare: produsul trebuie să corespundă documentului de producție.", "Paketlemeden önceki son kontrol: ürün üretim fişine uymalıdır."),
    steps: [
      CLAIM,
      line("Compară produsul cu documentul: dimensiuni, cantitate, culoare.", "Ürünü fişle karşılaştır: ölçü, adet, renk."),
      REPORT_FAULT,
      line("Dacă produsul e conform, apasă „Finalizează etapa”. Predai la ambalare doar produse conforme.", "Ürün uygunsa „Aşamayı tamamla”ya bas. Paketlemeye yalnız uygun ürünleri teslim et.", ["advance"]),
    ],
    after: [line("Comanda trece la Ambalare. Pentru comenzile magazinelor, AWB-ul FAN Courier se poate crea de la această etapă.", "Sipariş Paketlemeye geçer. Mağaza siparişlerinde FAN Courier AWB'si bu aşamadan itibaren oluşturulabilir.")],
    details: PRODUCTION_RULES,
  },

  "ws.ambalare": {
    title: text("Ambalare", "Paketleme"),
    summary: text("Verifici că toate produsele sunt complete și ambalezi comanda.", "Tüm ürünlerin tam olduğunu kontrol eder ve siparişi paketlersin."),
    purpose: text("Aici ambalezi comenzile verificate la calitate și le pregătești de livrare.", "Burada kalite kontrolden geçen siparişleri paketler ve teslimata hazırlarsın."),
    steps: [
      CLAIM,
      line("Înainte de ambalare verifică toate produsele și cantitățile cu documentul.", "Paketlemeden önce tüm ürünleri ve adetleri fişle kontrol et."),
      COMPLETE_STAGE,
    ],
    after: [line("Comanda trece la Livrare.", "Sipariş Teslimat aşamasına geçer.")],
    caution: [
      line("O comandă cu piese încă în refacere nu poate fi finalizată: sistemul blochează etapa până se întorc toate piesele.", "Parçaları hâlâ yeniden işlemede olan sipariş tamamlanamaz: tüm parçalar dönene kadar sistem aşamayı engeller."),
      line("Ambalarea în Arasya nu creează etichetă de curier în Trendyol sau în magazine.", "Arasya'da paketleme Trendyol'da veya mağazalarda kargo etiketi oluşturmaz."),
    ],
    details: PRODUCTION_RULES,
  },

  "ws.livrare": {
    title: text("Livrare", "Teslimat"),
    summary: text("Ultima etapă internă: închiderea ei încheie producția.", "Son iç aşama: tamamlanması üretimi bitirir."),
    purpose: text("Ultima etapă internă a producției.", "Üretimin son iç aşaması."),
    steps: [CLAIM, line("Când comanda pleacă, apasă „Finalizează producția”.", "Sipariş çıkınca „Üretimi tamamla”ya bas.", ["advance"])],
    after: [line("Producția comenzii se încheie în Arasya.", "Siparişin üretimi Arasya'da tamamlanır.")],
    caution: [line("Statusurile din Trendyol și din magazine nu se schimbă din Arasya.", "Trendyol'daki ve mağazalardaki durumlar Arasya'dan değişmez.")],
    details: PRODUCTION_RULES,
  },

  "scr.orders": {
    title: text("Comenzile mele", "Siparişlerim"),
    summary: text("Comenzile legate direct de munca ta.", "Doğrudan senin işinle ilgili siparişler."),
    purpose: text("Lista comenzilor legate direct de munca ta: preluate, atribuite sau predate de tine.", "Doğrudan senin işinle ilgili siparişlerin listesi: üstlendiğin, sana atanan veya teslim ettiğin."),
    steps: [line("Deschide o comandă ca să vezi operațiunea pe care o poți face acum.", "Şu an yapabileceğin işlemi görmek için bir siparişi aç."), line("Pentru o comandă nouă scanează QR-ul de pe document.", "Yeni bir sipariş için fişteki QR'ı okut.", ["scan"])],
    after: [line("Când închizi o etapă, comanda trece singură mai departe și apare la „Predate”.", "Aşamayı tamamlayınca sipariş kendiliğinden ilerler ve „Teslim edilen”de görünür.")],
  },

  "scr.order": {
    title: text("Comanda de producție", "Üretim siparişi"),
    summary: text("Detaliile comenzii și singura operațiune permisă acum.", "Siparişin ayrıntıları ve şu an izin verilen tek işlem."),
    purpose: text("Aici vezi comanda, etapa ei și singura operațiune pe care serverul ți-o permite acum.", "Burada siparişi, aşamasını ve sunucunun şu an sana izin verdiği tek işlemi görürsün."),
    steps: [
      line("„Preia comanda”: comanda trece pe numele tău la etapa ta.", "„Siparişi üstlen”: sipariş kendi aşamanda senin üzerine geçer.", ["claim"]),
      line("„Finalizează etapa”: comanda trece singură la etapa următoare.", "„Aşamayı tamamla”: sipariş kendiliğinden sonraki aşamaya geçer.", ["advance"]),
      line("Dacă vezi „Raportează o problemă” sau „Returnează la Tăiere”, folosește-le pentru greșeli reale de producție.", "„Sorun bildir” veya „Kesime iade et” görünüyorsa gerçek üretim hataları için kullan.", ["reportFault"]),
    ],
    after: [line("Serverul verifică din nou fiecare operațiune; rezultatul apare în istoricul tău.", "Sunucu her işlemi yeniden kontrol eder; sonuç geçmişinde görünür.")],
    caution: [line("Dacă nu vezi niciun buton, comanda nu este acum la etapa ta, este preluată de un coleg sau este blocată (de exemplu plata sau o problemă deschisă).", "Hiç düğme görmüyorsan sipariş şu an senin aşamanda değil, bir arkadaşın üzerinde veya engelli (örneğin ödeme veya açık bir sorun).")],
    details: PRODUCTION_RULES,
  },

  "scr.history": {
    title: text("Istoric", "Geçmiş"),
    summary: text("Operațiunile tale confirmate de server.", "Sunucunun onayladığı işlemlerin."),
    purpose: text("Aici vezi operațiunile tale de producție confirmate de server. Doar citire.", "Burada sunucunun onayladığı üretim işlemlerini görürsün. Yalnız okuma."),
    steps: [line("Alege intervalul de timp ca să vezi ce ai preluat și predat.", "Neyi üstlenip teslim ettiğini görmek için zaman aralığını seç.")],
    after: [line("Istoricul nu se modifică: o operațiune greșită se corectează prin fluxul de producție, nu aici.", "Geçmiş değişmez: yanlış bir işlem burada değil, üretim akışında düzeltilir.")],
  },

  "scr.profile": {
    title: text("Profil", "Profil"),
    summary: text("Contul tău, parola și ieșirea din aplicație.", "Hesabın, şifren ve uygulamadan çıkış."),
    purpose: text("Datele contului tău, așa cum sunt în sistemul central de conturi.", "Hesap bilgilerin, merkezi hesap sistemindeki haliyle."),
    steps: [line("Schimbă-ți parola când ai nevoie.", "Gerektiğinde şifreni değiştir."), line("Pe un telefon comun, ieși mereu din cont la final.", "Ortak telefonda iş bitince her zaman çıkış yap.")],
    after: [line("Departamentul, drepturile și limba aplicației le schimbă doar administratorul, în Dashboard.", "Departmanını, yetkilerini ve uygulama dilini yalnız yönetici Dashboard'da değiştirir.")],
  },

  "scr.problems": {
    title: text("Comenzi cu probleme", "Sorunlu siparişler"),
    summary: text("Verifici problemele de croitorie raportate și decizi pasul următor.", "Bildirilen terzihane sorunlarını inceler ve sonraki adıma karar verirsin."),
    purpose: text("Aici supervizorul croitoriei verifică problemele raportate de angajați.", "Terzi şefi burada çalışanların bildirdiği sorunları inceler."),
    steps: [
      line("La „De verificat”: verifică produsul și decide: îl preiei, îl trimiți la tăiere sau închizi problema.", "„Kontrol edilecek”: ürünü kontrol et ve karar ver: üzerine alırsın, kesime gönderirsin veya sorunu kapatırsın."),
      line("La „În grija mea”: comenzile pe care le ai fizic; le predai înapoi scanând eticheta.", "„Bende olanlar”: fiziksel olarak sende olan siparişler; etiketi okutarak geri teslim edersin."),
      line("La „De returnat la tăiere”: urmărești returnările la tăiere în curs.", "„Kesime iade edilecek”: devam eden kesime iadeleri izlersin."),
    ],
    after: [line("Comanda revine la etapa și la angajatul stabilite de decizia ta, după scanarea etichetei.", "Sipariş, etiket okutulduktan sonra kararının belirlediği aşamaya ve çalışana döner.")],
  },

  "scr.exception": {
    title: text("Problemă de producție", "Üretim sorunu"),
    summary: text("Detaliile unei probleme și pasul care îți revine.", "Bir sorunun ayrıntısı ve sana düşen adım."),
    purpose: text("Aici vezi o problemă de producție, piesele afectate și pasul următor.", "Burada bir üretim sorununu, etkilenen parçaları ve sonraki adımı görürsün."),
    steps: [line("Fă doar pasul care apare ca buton pentru tine.", "Yalnız senin için düğme olarak görünen adımı yap."), line("Scanarea etichetei confirmă doar că ai primit produsul, nu că accepți greșeala.", "Etiketi okutmak yalnız ürünü teslim aldığını onaylar, hatayı kabul ettiğini değil.")],
    after: [line("Când toți pașii sunt gata, comanda revine în producție.", "Tüm adımlar bitince sipariş üretime geri döner.")],
  },

  "scr.authority": {
    title: text("Preluare în Arasya", "Siparişi Arasya'ya al"),
    summary: text("Muți o comandă din YD SOFT în Arasya.", "Bir siparişi YD SOFT'tan Arasya'ya alırsın."),
    purpose: text("Mută gestionarea producției unei singure comenzi din YD SOFT în Arasya.", "Tek bir siparişin üretim yönetimini YD SOFT'tan Arasya'ya taşır."),
    steps: [line("Caută comanda și alege explicit etapa în care se află fizic acum.", "Siparişi bul ve şu an fiziksel olarak bulunduğu aşamayı açıkça seç."), line("Confirmă preluarea.", "Devralmayı onayla.")],
    after: [line("De acum comanda avansează doar în Arasya; etapa veche din YD SOFT nu se folosește.", "Sipariş artık yalnız Arasya'da ilerler; YD SOFT'taki eski aşama kullanılmaz.")],
  },

  "scr.trendyol.package": {
    title: text("Comandă Trendyol", "Trendyol siparişi"),
    summary: text("Pregătești o comandă Trendyol pentru producție.", "Bir Trendyol siparişini üretime hazırlarsın."),
    purpose: text("Aici completezi datele de producție ale unei comenzi Trendyol.", "Burada bir Trendyol siparişinin üretim bilgilerini tamamlarsın."),
    steps: [
      line("Pentru fiecare produs introdu dimensiunile și denumirea de producție în română (3–255 caractere).", "Her ürün için ölçüleri ve Rumence üretim adını gir (3–255 karakter)."),
      line("Verifică denumirea aprobată, dacă există, înainte de salvare.", "Kaydetmeden önce, varsa onaylı adı kontrol et."),
      line("Aprobarea „Aprobă și trimite în producție” o dă o persoană cu drept de aprobare Trendyol.", "„Onayla ve üretime gönder” onayını Trendyol onay yetkisi olan kişi verir."),
    ],
    after: [line("Comanda aprobată intră în producție; denumirea apare pe documentul de producție.", "Onaylanan sipariş üretime girer; ad üretim fişinde görünür.")],
  },

  "scr.document": {
    title: text("Document de producție", "Üretim fişi"),
    summary: text("Generezi, tipărești sau ceri revizia documentului unei comenzi.", "Bir siparişin fişini oluşturur, yazdırır veya revizyon istersin."),
    purpose: text("Documentul de producție cu QR al unei comenzi: atelierul lucrează după el.", "Bir siparişin QR kodlu üretim fişi: atölye ona göre çalışır."),
    steps: [
      line("Dacă nu există, generează REVIZIA 1.", "Yoksa REVİZYON 1'i oluştur.", ["docGenerate"]),
      line("Tipărește PDF-ul și pune documentul lângă produs.", "PDF'i yazdır ve fişi ürünün yanına koy.", ["docGenerate", "docReprint"]),
      line("Dacă ceva s-a schimbat, cere o revizie nouă.", "Bir şey değiştiyse yeni revizyon iste.", ["docRevision"]),
    ],
    after: [line("Atelierul scanează QR-ul de pe hârtie. Retipărirea păstrează aceeași revizie și același QR.", "Atölye kâğıttaki QR'ı okutur. Yeniden yazdırma aynı revizyonu ve aynı QR'ı korur.")],
    caution: [line("Descărcarea nu dovedește că hârtia a ieșit din imprimantă: verifică foaia tipărită.", "İndirme, kâğıdın yazıcıdan çıktığını kanıtlamaz: yazdırılan kâğıdı kontrol et.")],
  },

  "scr.cf.order": {
    title: text("Comandă Confirmare", "Confirmare siparişi"),
    summary: text("O comandă de magazin în confirmare: apel, plată, documente.", "Onaydaki bir mağaza siparişi: arama, ödeme, belgeler."),
    purpose: text("Aici este o comandă Trend Home sau Outlet Perdele: confirmarea, situația plății și documentele ei.", "Burada bir Trend Home veya Outlet Perdele siparişi var: onayı, ödeme durumu ve belgeleri."),
    steps: [
      line("Comanda are cinci pași; ecranul deschide doar pasul și sarcina de acum („Ce trebuie să fac acum?”).", "Siparişin beş adımı var; ekran yalnız şu anki adımı ve görevi açar („Şimdi ne yapmalıyım?”).", ["cfOperate"]),
      line("1. Preluarea comenzii: preia comanda dacă nu are operator.", "1. Siparişi alma: operatörü yoksa siparişi üstlen.", ["cfOperate"]),
      line("2. Verificarea cu clientul: deschide telefonul din magazin, sună, înregistrează rezultatul, apoi verifică produsele unul câte unul.", "2. Müşteriyle kontrol: telefonu mağazadan aç, ara, sonucu kaydet, sonra ürünleri tek tek kontrol et.", ["cfOperate"]),
      line("3. Verificarea finală: citește prețul actual, confirmă-l cu clientul și apasă „Aprobă pentru producție”. Aprobarea nu este plata.", "3. Son kontrol: güncel fiyatı oku, müşteriyle onayla ve „Üretim için onayla”ya bas. Onay ödeme değildir.", ["cfOperate"]),
      line("4. Plata și avansul, 5. Predarea comenzii: faci doar ce îți arată ecranul; când alt departament lucrează, ecranul spune cine și ce așteaptă.", "4. Ödeme ve avans, 5. Siparişi teslim: yalnız ekranın gösterdiğini yaparsın; başka bir bölüm çalışırken ekran kimin neyi beklediğini söyler.", ["cfOperate"]),
      line("Restul (istoric, bani, documente, respingere) este în „Detalii suplimentare” și „Comanda nu poate continua?”.", "Gerisi (geçmiş, para, belgeler, ret) „Ek ayrıntılar” ve „Sipariş devam edemiyor mu?” bölümlerindedir.", ["cfOperate"]),
      line("În secțiunea financiară faci doar pasul tău: înregistrare sau verificare.", "Finans bölümünde yalnız kendi adımını yaparsın: kayıt veya doğrulama.", ["finRecord", "finApprove"]),
      FINAL_INVOICE,
    ],
    after: [CONFIRMARE_PAYMENT_RULE],
    caution: [NO_DUPLICATE_INVOICE],
    details: [
      line("Proforma de avans: o emiți și o trimiți după ce clientul acceptă avansul la telefon. Clientul plătește prin transfer cu referința de plată.", "Avans proforması: müşteri avansı telefonda kabul edince keser ve gönderirsin. Müşteri ödeme referansıyla havale yapar.", ["cfOperate"]),
      INVOICE_MANAGER,
      line("Dacă nu ai drept de confirmare, comanda se deschide pentru tine doar pentru citire și pentru pasul tău financiar.", "Onay yetkin yoksa sipariş sana yalnız okuma ve kendi finans adımın için açılır.", ["finAny"]),
      line("Dacă comanda s-a schimbat în magazin, verificările produselor se șterg și ecranul te întoarce la apel: verifici din nou cu clientul.", "Sipariş mağazada değişirse ürün kontrolleri silinir ve ekran seni aramaya geri götürür: müşteriyle yeniden kontrol edersin.", ["cfOperate"]),
      line("Verificările produselor rămân doar pe ecran până la aprobare; apelurile și aprobarea sunt salvate pe server.", "Ürün kontrolleri onaya kadar yalnız ekranda kalır; aramalar ve onay sunucuda kaydedilir.", ["cfOperate"]),
    ],
  },

  "scr.cf.team": {
    title: text("Echipa Confirmare", "Confirmare ekibi"),
    summary: text("Distribuirea, concediile și întârzierile echipei.", "Ekibin dağıtımı, izinleri ve gecikmeleri."),
    purpose: text("Pentru supervizori: cine primește comenzi, cine e în concediu și ce comenzi întârzie.", "Süpervizörler için: kim sipariş alıyor, kim izinde ve hangi siparişler gecikiyor."),
    steps: [
      line("Marchează concediul unui angajat: comenzile lui se redistribuie imediat.", "Bir çalışanı izinli işaretle: siparişleri hemen yeniden dağıtılır."),
      line("Urmărește alertele de întârziere; comanda nu se mută singură.", "Gecikme uyarılarını izle; sipariş kendiliğinden taşınmaz."),
      line("Verifică comenzile „Neclare”: decizia îți aparține.", "„Belirsiz” siparişleri kontrol et: karar sana aittir."),
    ],
    after: [line("Serverul distribuie comenzile echilibrat, după data de livrare.", "Sunucu siparişleri teslim tarihine göre dengeli dağıtır.")],
  },

  "scr.cf.finance": {
    title: text("Finanțe Confirmare", "Confirmare finans"),
    summary: text("Sarcinile financiare ale comenzilor Confirmare.", "Confirmare siparişlerinin finans görevleri."),
    purpose: text("Lista comenzilor care așteaptă o decizie financiară. Sumele se citesc din magazin, la fiecare comandă.", "Finansal karar bekleyen siparişlerin listesi. Tutarlar her siparişte mağazadan okunur."),
    steps: [
      line("Ca operator vezi aici avansurile comenzilor tale: așteptat, parțial, plătit.", "Operatör olarak burada kendi siparişlerinin avanslarını görürsün: bekleniyor, kısmi, ödendi.", ["cfOperate"]),
      line("Înregistrezi transferul doar când apare în extrasul bancar.", "Havaleyi yalnız banka ekstresinde görününce kaydedersin.", ["finRecord"]),
      line("Verifici în extras transferurile înregistrate de colegi: aprobi sau respingi cu motiv.", "Arkadaşlarının kaydettiği havaleleri ekstrede doğrularsın: onaylar veya nedeniyle reddedersin.", ["finApprove"]),
      line("Confirmi verificarea extrasului bancar pentru proformele expirate.", "Süresi dolan proformalar için banka ekstresi kontrolünü onaylarsın.", ["finRecord", "finApprove"]),
    ],
    after: [line("După verificarea a doi angajați, avansul e complet și comanda merge la factura finală și la producție.", "İki çalışanın doğrulamasından sonra avans tamamlanır; sipariş nihai faturaya ve üretime ilerler.")],
    caution: [FOUR_EYES],
    details: [MANAGER_NOT_FINANCE, line("Nu se face nicio rambursare electronică; rambursările se fac prin transfer și se verifică de alt angajat.", "Elektronik iade yapılmaz; iadeler havaleyle yapılır ve başka bir çalışan doğrular.")],
  },

  "scr.cf.handoff": {
    title: text("Predare în producție", "Üretime devir"),
    summary: text("Comenzile aprobate și plătite care au nevoie de document de producție.", "Üretim fişi bekleyen onaylı ve ödenmiş siparişler."),
    purpose: text("Confirmare nu este o etapă de producție. Comanda aprobată și plătită rămâne la etapa 1 până când tăierea scanează QR-ul documentului.", "Confirmare bir üretim aşaması değildir. Onaylanmış ve ödenmiş sipariş, kesim fişin QR'ını okutana kadar 1. aşamada kalır."),
    steps: [
      line("„Gata pentru fișă”: generează fișa de producție (revizia 1).", "„Fişe hazır”: üretim fişini (revizyon 1) oluştur.", ["handoff"]),
      line("„De tipărit”: descarcă și tipărește fișa pentru tăiere.", "„Yazdırılacak”: fişi indir ve kesim için yazdır.", ["handoff"]),
      line("Dă fișa împreună cu produsul la tăiere.", "Fişi ürünle birlikte kesime ver.", ["handoff"]),
      FINAL_INVOICE,
    ],
    after: [line("Când tăierea scanează QR-ul, comanda intră în producție și iese din listă.", "Kesim QR'ı okutunca sipariş üretime girer ve listeden çıkar.")],
    caution: [line("Pentru o comandă cu condiții lipsă (aprobare, plată, o schimbare în magazin) documentul nu se poate genera.", "Eksik koşulu olan sipariş için (onay, ödeme, mağazadaki bir değişiklik) fiş oluşturulamaz.")],
  },

  "scr.cf.awb": {
    title: text("AWB de făcut", "Oluşturulacak AWB'ler"),
    summary: text("Comenzile magazinului tău care au nevoie de AWB FAN Courier.", "Mağazanın FAN Courier AWB'si bekleyen siparişleri."),
    purpose: text("Aici sunt comenzile aprobate ale magazinului tău, de la etapa 12 (Control calitate), fără AWB. FAN Courier este singurul curier.", "Burada mağazanın 12. aşamadan (Kalite kontrol) itibaren AWB'si olmayan onaylı siparişleri var. Tek kargo firması FAN Courier'dir."),
    steps: [
      line("Deschide comanda din listă.", "Siparişi listeden aç."),
      line("Introdu numărul de colete și verifică suma de încasat la livrare; bifează verificarea.", "Koli sayısını gir ve kapıda tahsil edilecek tutarı kontrol et; kontrolü işaretle."),
      line("Apasă „Generează AWB”, apoi descarcă eticheta AWB.", "„AWB oluştur”a bas, sonra AWB etiketini indir."),
    ],
    after: [line("Numărul AWB se salvează la comandă. Ramburs = restul de plată după avansul verificat, o singură sumă pentru tot AWB-ul.", "AWB numarası siparişe kaydedilir. Kapıda tahsilat, doğrulanmış avanstan sonra kalan tutardır; tüm AWB için tek tutar.")],
    caution: [
      line("Creează AWB-ul doar din Staff. Butonul CurieRO din WordPress nu scade avansul și încasează totalul: nu-l folosi pentru comenzile Confirmare.", "AWB'yi yalnız Staff'tan oluştur. WordPress'teki CurieRO düğmesi avansı düşmez ve tüm tutarı tahsil eder: Confirmare siparişlerinde kullanma."),
      line("Dacă rezultatul unei încercări AWB este incert, nu crea alt AWB până nu se clarifică.", "Bir AWB denemesinin sonucu belirsizse netleşene kadar yeni AWB oluşturma."),
    ],
    details: [line("După crearea AWB-ului, o schimbare urgentă de adresă se trimite ca sarcină pentru panoul FAN Courier, din comandă.", "AWB oluşturulduktan sonra acil adres değişikliği siparişten FAN Courier paneli için görev olarak gönderilir.")],
  },

  "scr.cf.efactura": {
    title: text("Urmărire e-Factura", "e-Factura takibi"),
    summary: text("Urmărești ca facturile finale să ajungă la ANAF în 5 zile lucrătoare.", "Nihai faturaların 5 iş gününde ANAF'a ulaşmasını takip edersin."),
    purpose: text("Fiecare factură finală trebuie să ajungă la ANAF în 5 zile lucrătoare. O trimite SmartBill; Arasya nu citește singură starea din SPV.", "Her nihai fatura 5 iş günü içinde ANAF'a ulaşmalıdır. Faturayı SmartBill gönderir; Arasya SPV durumunu kendisi okumaz."),
    steps: [
      line("Verifică factura în SmartBill Cloud (Facturi emise › e-Factura) sau în SPV.", "Faturayı SmartBill Cloud'da (Kesilen faturalar › e-Factura) veya SPV'de kontrol et."),
      line("Înregistrează aici ce ai văzut: „Transmisă (aștept ANAF)”, „Validată de ANAF” sau „Respinsă de ANAF”.", "Gördüğünü buraya kaydet: „Gönderildi (ANAF bekleniyor)”, „ANAF onayladı” veya „ANAF reddetti”."),
      line("Începe cu facturile depășite.", "Süresi geçen faturalardan başla."),
    ],
    after: [line("Înregistrarea ține doar evidența: nu schimbă nimic în SmartBill sau la ANAF.", "Kayıt yalnız takip içindir: SmartBill'de veya ANAF'ta hiçbir şeyi değiştirmez.")],
    caution: [line("SPV și trimiterea e-Factura sunt responsabilitatea contabilului. Spune-i contabilului de orice factură respinsă.", "SPV ve e-Factura gönderimi muhasebecinin sorumluluğundadır. Reddedilen her faturayı muhasebeciye bildir.")],
  },

  "scr.cf.health": {
    title: text("Starea operațiunilor", "Operasyon durumu"),
    summary: text("Doar cifre pe magazin: ce așteaptă, ce întârzie, ce e incert.", "Mağaza başına yalnız sayılar: bekleyen, geciken, belirsiz."),
    purpose: text("Doar cifre pe magazin, fără comenzi, clienți sau sume. Doar citire, actualizat la fiecare minut.", "Mağaza başına yalnız sayılar; sipariş, müşteri veya tutar yok. Yalnız okuma, her dakika yenilenir."),
    steps: [line("Urmărește cifrele de rezultate incerte și erori: dacă nu sunt zero, anunță persoana responsabilă.", "Belirsiz sonuç ve hata sayılarını izle: sıfır değilse sorumlu kişiye bildir.")],
    after: [line("Ecranul nu face nicio operațiune; rezolvarea se face în comanda respectivă de cine are dreptul.", "Ekran hiçbir işlem yapmaz; çözüm, ilgili siparişte yetkili kişi tarafından yapılır.")],
  },
} as const satisfies Readonly<Record<string, GuideTopic>>;

export type GuideTopicId = keyof typeof guideTopics;
