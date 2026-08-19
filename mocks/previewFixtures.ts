import type { ActivityEntry, ActivityPage, Employee, StaffOrder } from "../domain/models";

export const previewEmployee: Employee = {
  employeeUuid: "EMP-PREVIEW-001",
  employeeCode: "EMP-DEMO",
  displayName: "Ali Demo",
  username: "demo",
  department: "Pregătire material",
  departmentKey: "pregatire-material",
  permissions: ["orders.scan", "orders.view_mine", "orders.claim", "orders.advance_stage", "orders.handover", "history.view_mine", "profile.view_self"],
  allowedStageIds: ["material-preparation"],
  role: "employee",
  status: "active",
  locale: "ro",
};

export const previewOrders: StaffOrder[] = [
  {
    id: "order-61833",
    source: "trendhome",
    orderNumber: "61833",
    productionStageId: "material-preparation",
    sourceCommerceStatus: { code: "processing", label: "Processing" },
    employeeAllowedAction: { id: "handover", label: "Predă comanda" },
    products: [{ id: "item-dv302", name: "Draperie Velvet", code: "DV-302", color: "Bej", dimensions: "300 × 260 cm", meters: 8.4, quantity: 1 }],
    productionNotes: "Verifică sensul materialului înainte de pregătire.",
    employeeRelation: { employeeUuid: "EMP-PREVIEW-001", type: "claimed", lastActionAt: "2026-08-19T10:42:00+03:00" },
    updatedAt: "2026-08-19T10:42:00+03:00",
    status: "in_progress",
    freshness: { status: "fresh", sourceChangedAt: "2026-08-19T10:41:00+03:00", lastSourceSeenAt: "2026-08-19T10:42:00+03:00" },
    version: 4,
  },
  {
    id: "order-61829",
    source: "outletperdele",
    orderNumber: "61829",
    productionStageId: "side-hem",
    sourceCommerceStatus: { code: "processing", label: "Processing" },
    products: [{ id: "item-pv118", name: "Perdea Voal", code: "PV-118", color: "Alb", dimensions: "420 × 250 cm", meters: 5.2, quantity: 1 }],
    acceptedAt: "2026-08-19T09:48:00+03:00",
    employeeRelation: { employeeUuid: "EMP-PREVIEW-001", type: "updated", lastActionAt: "2026-08-19T10:17:00+03:00" },
    updatedAt: "2026-08-19T10:17:00+03:00",
    status: "handed_over",
    freshness: { status: "stale", sourceChangedAt: "2026-08-19T09:48:00+03:00", lastSourceSeenAt: "2026-08-19T09:50:00+03:00" },
    version: 2,
  },
  {
    id: "order-trendyol-1048",
    source: "trendyol",
    orderNumber: "TY-1048",
    productionStageId: "quality-control",
    sourceCommerceStatus: { code: "Picking", label: "Picking" },
    products: [{ id: "item-trendyol1048", name: "Set perdea marketplace", code: "TY-MANUAL", color: "Gri", dimensions: "280 × 240 cm", meters: 24, quantity: 6 }],
    productionNotes: "Comandă Trendyol fictivă; starea marketplace rămâne separată de producție.",
    acceptedAt: "2026-08-18T14:12:00+03:00",
    employeeRelation: { employeeUuid: "EMP-PREVIEW-001", type: "handover_out", lastActionAt: "2026-08-19T09:52:00+03:00" },
    updatedAt: "2026-08-19T09:52:00+03:00",
    status: "handed_over",
    freshness: { status: "source_unavailable", sourceChangedAt: "2026-08-18T14:12:00+03:00", lastSourceSeenAt: "2026-08-18T14:12:00+03:00" },
    version: 8,
  },
];

export const previewUnrelatedOrders: StaffOrder[] = [
  {
    id: "order-62001",
    source: "trendhome",
    orderNumber: "62001",
    productionStageId: "waiting",
    sourceCommerceStatus: { code: "processing", label: "Processing" },
    employeeAllowedAction: { id: "accept", label: "Preia comanda" },
    products: [{ id: "item-ln401", name: "Draperie Linen", code: "LN-401", color: "Nisip", dimensions: "320 × 260 cm", meters: 7.8, quantity: 1 }],
    productionNotes: "Comandă fictivă disponibilă numai prin fluxul de scanare.",
    updatedAt: "2026-08-19T11:08:00+03:00",
    status: "in_progress",
    freshness: { status: "fresh", sourceChangedAt: "2026-08-19T11:07:00+03:00", lastSourceSeenAt: "2026-08-19T11:08:00+03:00" },
    version: 1,
  },
  {
    id: "order-62002",
    source: "outletperdele",
    orderNumber: "62002",
    productionStageId: "material-preparation",
    sourceCommerceStatus: { code: "processing", label: "Processing" },
    employeeAllowedAction: { id: "handover", label: "Predă comanda" },
    products: [{ id: "item-vl220", name: "Perdea Voal", code: "VL-220", color: "Ivory", dimensions: "400 × 250 cm", meters: 6.1, quantity: 1 }],
    employeeRelation: { employeeUuid: "EMP-PREVIEW-OTHER", type: "claimed", lastActionAt: "2026-08-19T10:58:00+03:00" },
    acceptedAt: "2026-08-19T10:58:00+03:00",
    updatedAt: "2026-08-19T10:58:00+03:00",
    status: "in_progress",
    freshness: { status: "fresh", sourceChangedAt: "2026-08-19T10:57:00+03:00", lastSourceSeenAt: "2026-08-19T10:58:00+03:00" },
    version: 3,
  },
];

export const previewOrderDatabase = [...previewOrders, ...previewUnrelatedOrders];

const todayActivities: ActivityEntry[] = [
  { id: "preview-act-1", occurredAt: "2026-08-19T10:42:00+03:00", orderId: "order-61833", orderNumber: "61833", source: "trendhome", fromStageId: "waiting", fromStageLabelSnapshot: "În așteptare", toStageId: "material-preparation", toStageLabelSnapshot: "Pregătire material", meters: 8.4 },
  { id: "preview-act-2", occurredAt: "2026-08-19T10:17:00+03:00", orderId: "order-61829", orderNumber: "61829", source: "outletperdele", fromStageId: "bottom-hem", fromStageLabelSnapshot: "Tivul de jos", toStageId: "side-hem", toStageLabelSnapshot: "Tivul lateral", meters: 5.2 },
  { id: "preview-act-3", occurredAt: "2026-08-19T09:52:00+03:00", orderId: "order-trendyol-1048", orderNumber: "TY-1048", source: "trendyol", fromStageId: "sewing-finishing", fromStageLabelSnapshot: "Finisare coasere", toStageId: "quality-control", toStageLabelSnapshot: "Control calitate", meters: 24 },
];

const weekActivities: ActivityEntry[] = [
  ...todayActivities,
  { id: "preview-act-4", occurredAt: "2026-08-18T15:28:00+03:00", orderId: "order-61829", orderNumber: "61829", source: "outletperdele", fromStageId: "material-straightening", fromStageLabelSnapshot: "Îndreptare material", toStageId: "bottom-hem", toStageLabelSnapshot: "Tivul de jos", meters: 5.2 },
  { id: "preview-act-5", occurredAt: "2026-08-17T11:06:00+03:00", orderId: "order-61833", orderNumber: "61833", source: "trendhome", fromStageId: "waiting", fromStageLabelSnapshot: "În așteptare", toStageId: "material-preparation", toStageLabelSnapshot: "Pregătire material", meters: 8.4 },
];

const monthActivities: ActivityEntry[] = [
  ...weekActivities,
  { id: "preview-act-6", occurredAt: "2026-08-12T08:41:00+03:00", orderId: "order-trendyol-1048", orderNumber: "TY-1048", source: "trendyol", fromStageId: "header-tape", fromStageLabelSnapshot: "Rejansă", toStageId: "sewing-finishing", toStageLabelSnapshot: "Finisare coasere", meters: 24 },
];

export const previewActivityPages: Record<"today" | "7days" | "month" | "custom", ActivityPage> = {
  today: { items: todayActivities, summary: { processed: 11, meters: 37.6, handedOver: 8, inProgress: 3 } },
  "7days": { items: weekActivities, summary: { processed: 48, meters: 162.4, handedOver: 31, inProgress: 3 } },
  month: { items: monthActivities, summary: { processed: 187, meters: 624.8, handedOver: 142, inProgress: 3 } },
  custom: { items: monthActivities, summary: { processed: 187, meters: 624.8, handedOver: 142, inProgress: 3 } },
};
