/**
 * B2B order types in Staff (Staff 2.49.0, API 2.67.0). The server decides the route from the frozen order type; Staff
 * only explains it to the stage 2 and Livrare employees. Romanian and Turkish are the working languages of the owner and
 * the factory; English and Sinhala still need a native review.
 */
export const orderTypesRO = {
  "orderType.eyebrow": "Tip comandă B2B",
  "orderType.FULL_TAILORING": "Confecționare completă",
  "orderType.CUT_ONLY": "Doar debitare",
  "orderType.FULL_ROLL_BAG": "Sac întreg / rolă întreagă",
  "orderType.route.full": "Traseu complet de producție (etapele aplicabile 1–14).",
  "orderType.route.short": "Traseu: Pregătire material → Livrare. Etapele 3–13 nu se aplică acestei comenzi.",
  "orderType.stage2.CUT_ONLY": "Etapa 2: tăiați materialul și confirmați etapa. Comanda merge apoi direct la Livrare.",
  "orderType.stage2.FULL_ROLL_BAG": "Etapa 2: verificați și pregătiți sacul sau rola întreagă, fără tăiere, apoi confirmați etapa. Comanda merge apoi direct la Livrare.",
  "orderType.delivery": "Livrare: preluați comanda și finalizați predarea. Comanda nu a trecut prin croitorie, pentru că tipul ei nu o cere.",
} as const;

export const orderTypesTR: { readonly [K in keyof typeof orderTypesRO]: string } = {
  "orderType.eyebrow": "B2B sipariş tipi",
  "orderType.FULL_TAILORING": "Tam Dikim",
  "orderType.CUT_ONLY": "Yalnız Kesim",
  "orderType.FULL_ROLL_BAG": "Tam Poşet / Rulo",
  "orderType.route.full": "Tam üretim rotası (uygulanan 1–14. aşamalar).",
  "orderType.route.short": "Rota: Malzeme Hazırlama → Teslimat (Livrare). 3–13. aşamalar bu siparişte uygulanmaz.",
  "orderType.stage2.CUT_ONLY": "2. aşama: kumaşı kesin ve aşamayı onaylayın. Sipariş ardından doğrudan Teslimat'a (Livrare) gider.",
  "orderType.stage2.FULL_ROLL_BAG": "2. aşama: tam poşeti veya ruloyu kesmeden kontrol edip hazırlayın, sonra aşamayı onaylayın. Sipariş ardından doğrudan Teslimat'a (Livrare) gider.",
  "orderType.delivery": "Teslimat (Livrare): siparişi teslim alın ve teslimi tamamlayın. Sipariş tipi dikim gerektirmediği için sipariş dikim atölyesinden geçmedi.",
};

export const orderTypesEN: { readonly [K in keyof typeof orderTypesRO]: string } = {
  "orderType.eyebrow": "B2B order type",
  "orderType.FULL_TAILORING": "Full tailoring",
  "orderType.CUT_ONLY": "Cutting only",
  "orderType.FULL_ROLL_BAG": "Full bag / roll",
  "orderType.route.full": "Full production route (applicable stages 1–14).",
  "orderType.route.short": "Route: Material preparation → Livrare. Stages 3–13 do not apply to this order.",
  "orderType.stage2.CUT_ONLY": "Stage 2: cut the material and confirm the stage. The order then goes directly to Livrare.",
  "orderType.stage2.FULL_ROLL_BAG": "Stage 2: check and prepare the full bag or roll without cutting, then confirm the stage. The order then goes directly to Livrare.",
  "orderType.delivery": "Livrare: receive the order and complete the handover. The order did not go through tailoring because its type does not need it.",
};

export const orderTypesSI: { readonly [K in keyof typeof orderTypesRO]: string } = {
  "orderType.eyebrow": "B2B ඇණවුම් වර්ගය",
  "orderType.FULL_TAILORING": "සම්පූර්ණ මැසීම",
  "orderType.CUT_ONLY": "කැපීම පමණයි",
  "orderType.FULL_ROLL_BAG": "සම්පූර්ණ බෑගය / රෝලය",
  "orderType.route.full": "සම්පූර්ණ නිෂ්පාදන මාර්ගය (අදාළ අදියර 1–14).",
  "orderType.route.short": "මාර්ගය: ද්‍රව්‍ය සූදානම → Livrare. අදියර 3–13 මෙම ඇණවුමට අදාළ නොවේ.",
  "orderType.stage2.CUT_ONLY": "අදියර 2: ද්‍රව්‍යය කපා අදියර තහවුරු කරන්න. ඉන්පසු ඇණවුම කෙලින්ම Livrare වෙත යයි.",
  "orderType.stage2.FULL_ROLL_BAG": "අදියර 2: සම්පූර්ණ බෑගය හෝ රෝලය කපන්නේ නැතිව පරීක්ෂා කර සූදානම් කර, අදියර තහවුරු කරන්න. ඉන්පසු ඇණවුම කෙලින්ම Livrare වෙත යයි.",
  "orderType.delivery": "Livrare: ඇණවුම භාරගෙන භාරදීම සම්පූර්ණ කරන්න. ඇණවුම් වර්ගයට මැසීම අවශ්‍ය නොවන නිසා ඇණවුම මැසීම් අංශය හරහා ගියේ නැත.",
};
