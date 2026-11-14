/**
 * Staff 2.52.0: the employee's own line meters in the history (API 2.70.0 `/production-intelligence/mine`). Only the
 * signed-in employee's work; the meters are the order lines' source meters, never measured at the cutting table. Turkish
 * and Sinhala still need a native review.
 */
export const myMetersRO = {
  "history.meters.title": "Metrajul meu",
  "history.meters.hint": "Metrii liniilor comenzilor la care ați încheiat o etapă. Fiecare etapă se numără separat. Nu sunt metri măsurați la tăiere.",
  "history.meters.stage": "Etapa",
  "history.meters.completions": "Încheieri",
  "history.meters.cuts": "Tăieri",
  "history.meters.recuts": "Retăieri",
  "history.meters.meters": "Metri (linie)",
  "history.meters.withoutMeters": "{count} încheieri fără metri cunoscuți: nu sunt numărate ca zero.",
  "history.meters.empty": "Nicio etapă încheiată în această perioadă.",
  "history.meters.unavailable": "Metrajul nu este disponibil acum.",
  "history.meters.beforeStart": "Metrajul se calculează de la 4 octombrie 2026.",
  "history.metric.lineMeters": "Metri (linie)",
};

export const myMetersEN: Record<keyof typeof myMetersRO, string> = {
  "history.meters.title": "My metres",
  "history.meters.hint": "The order line metres of the orders where you completed a stage. Each stage counts on its own. These are not metres measured at the cutting table.",
  "history.meters.stage": "Stage",
  "history.meters.completions": "Completions",
  "history.meters.cuts": "Cuts",
  "history.meters.recuts": "Recuts",
  "history.meters.meters": "Metres (line)",
  "history.meters.withoutMeters": "{count} completions without known metres: they are not counted as zero.",
  "history.meters.empty": "No stage completed in this period.",
  "history.meters.unavailable": "Metres are not available right now.",
  "history.meters.beforeStart": "Metres are counted from 4 October 2026.",
  "history.metric.lineMeters": "Metres (line)",
};

export const myMetersTR: Record<keyof typeof myMetersRO, string> = {
  "history.meters.title": "Metrajım",
  "history.meters.hint": "Bir aşamasını tamamladığın siparişlerin satır metreleri. Her aşama ayrı sayılır. Kesim masasında ölçülmüş metre değildir.",
  "history.meters.stage": "Aşama",
  "history.meters.completions": "Tamamlama",
  "history.meters.cuts": "Kesim",
  "history.meters.recuts": "Yeniden kesim",
  "history.meters.meters": "Metre (satır)",
  "history.meters.withoutMeters": "Metresi bilinmeyen {count} tamamlama: sıfır sayılmaz.",
  "history.meters.empty": "Bu dönemde tamamlanan aşama yok.",
  "history.meters.unavailable": "Metraj şu anda gösterilemiyor.",
  "history.meters.beforeStart": "Metraj 4 Ekim 2026'dan itibaren hesaplanır.",
  "history.metric.lineMeters": "Metre (satır)",
};

export const myMetersSI: Record<keyof typeof myMetersRO, string> = {
  "history.meters.title": "මගේ මීටර්",
  "history.meters.hint": "ඔබ අදියරක් අවසන් කළ ඇණවුම්වල පේළි මීටර්. සෑම අදියරක්ම වෙන වෙනම ගණන් කෙරේ. මේවා කැපීමේ මේසයේ මැනූ මීටර් නොවේ.",
  "history.meters.stage": "අදියර",
  "history.meters.completions": "අවසන් කිරීම්",
  "history.meters.cuts": "කැපීම්",
  "history.meters.recuts": "නැවත කැපීම්",
  "history.meters.meters": "මීටර් (පේළිය)",
  "history.meters.withoutMeters": "දන්නා මීටර් නැති අවසන් කිරීම් {count}: ඒවා බිංදුව ලෙස ගණන් නොකෙරේ.",
  "history.meters.empty": "මෙම කාලය තුළ අවසන් කළ අදියරක් නැත.",
  "history.meters.unavailable": "මීටර් දැන් ලබා ගත නොහැක.",
  "history.meters.beforeStart": "මීටර් 2026 ඔක්තෝබර් 4 සිට ගණන් කෙරේ.",
  "history.metric.lineMeters": "මීටර් (පේළිය)",
};
