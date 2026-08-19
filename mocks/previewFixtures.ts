import type { ActivityEntry, ActivityPage, Employee, StaffOrder } from "../domain/models";

export const previewEmployee: Employee = {
  employeeUuid: "EMP-PREVIEW-001",
  employeeCode: "EMP-DEMO",
  displayName: "Ali Demo",
  username: "demo",
  department: "Pregătire Material",
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
    currentStage: { id: "material-preparation", ordinal: 2, label: "Pregătire Material" },
    nextStage: { id: "cutting", ordinal: 3, label: "Croire" },
    employeeAllowedAction: { id: "handover", label: "Predă comanda" },
    products: [{ id: "item-dv302", name: "Draperie Velvet", code: "DV-302", color: "Bej", dimensions: "300 × 260 cm", meters: 8.4, quantity: 1 }],
    productionNotes: "Verifică sensul materialului înainte de pregătire.",
    employeeRelation: { employeeUuid: "EMP-PREVIEW-001", type: "claimed", lastActionAt: "2026-08-19T10:42:00+03:00" },
    updatedAt: "2026-08-19T10:42:00+03:00",
    status: "in_progress",
    version: 4,
  },
  {
    id: "order-61829",
    source: "outletperdele",
    orderNumber: "61829",
    currentStage: { id: "cutting", ordinal: 3, label: "Croire" },
    products: [{ id: "item-pv118", name: "Perdea Voal", code: "PV-118", color: "Alb", dimensions: "420 × 250 cm", meters: 5.2, quantity: 1 }],
    acceptedAt: "2026-08-19T09:48:00+03:00",
    employeeRelation: { employeeUuid: "EMP-PREVIEW-001", type: "updated", lastActionAt: "2026-08-19T10:17:00+03:00" },
    updatedAt: "2026-08-19T10:17:00+03:00",
    status: "handed_over",
    version: 2,
  },
  {
    id: "order-b2b-1048",
    source: "b2b",
    orderNumber: "B2B-1048",
    currentStage: { id: "quality", ordinal: 5, label: "Control calitate" },
    products: [{ id: "item-b2b1048", name: "Comandă manuală en-gros", code: "B2B-MANUAL", color: "Gri", dimensions: "280 × 240 cm", meters: 24, quantity: 6 }],
    productionNotes: "Lot fictiv pentru verificarea fluxului B2B.",
    acceptedAt: "2026-08-18T14:12:00+03:00",
    employeeRelation: { employeeUuid: "EMP-PREVIEW-001", type: "handover_out", lastActionAt: "2026-08-19T09:52:00+03:00" },
    updatedAt: "2026-08-19T09:52:00+03:00",
    status: "handed_over",
    version: 8,
  },
];

export const previewUnrelatedOrders: StaffOrder[] = [
  {
    id: "order-62001",
    source: "trendhome",
    orderNumber: "62001",
    currentStage: { id: "waiting", ordinal: 1, label: "În așteptare" },
    nextStage: { id: "material-preparation", ordinal: 2, label: "Pregătire Material" },
    employeeAllowedAction: { id: "accept", label: "Preia comanda" },
    products: [{ id: "item-ln401", name: "Draperie Linen", code: "LN-401", color: "Nisip", dimensions: "320 × 260 cm", meters: 7.8, quantity: 1 }],
    productionNotes: "Comandă fictivă disponibilă numai prin fluxul de scanare.",
    updatedAt: "2026-08-19T11:08:00+03:00",
    status: "in_progress",
    version: 1,
  },
  {
    id: "order-62002",
    source: "outletperdele",
    orderNumber: "62002",
    currentStage: { id: "material-preparation", ordinal: 2, label: "Pregătire Material" },
    nextStage: { id: "cutting", ordinal: 3, label: "Croire" },
    employeeAllowedAction: { id: "handover", label: "Predă comanda" },
    products: [{ id: "item-vl220", name: "Perdea Voal", code: "VL-220", color: "Ivory", dimensions: "400 × 250 cm", meters: 6.1, quantity: 1 }],
    employeeRelation: { employeeUuid: "EMP-PREVIEW-OTHER", type: "claimed", lastActionAt: "2026-08-19T10:58:00+03:00" },
    acceptedAt: "2026-08-19T10:58:00+03:00",
    updatedAt: "2026-08-19T10:58:00+03:00",
    status: "in_progress",
    version: 3,
  },
];

export const previewOrderDatabase = [...previewOrders, ...previewUnrelatedOrders];

const todayActivities: ActivityEntry[] = [
  { id: "preview-act-1", occurredAt: "2026-08-19T10:42:00+03:00", orderId: "order-61833", orderNumber: "61833", source: "trendhome", fromStage: "Preluată", toStage: "Pregătire Material", meters: 8.4 },
  { id: "preview-act-2", occurredAt: "2026-08-19T10:17:00+03:00", orderId: "order-61829", orderNumber: "61829", source: "outletperdele", fromStage: "Predată", toStage: "Croire", meters: 5.2 },
  { id: "preview-act-3", occurredAt: "2026-08-19T09:52:00+03:00", orderId: "order-b2b-1048", orderNumber: "B2B-1048", source: "b2b", fromStage: "Preluată", toStage: "Pregătire Material", meters: 24 },
];

const weekActivities: ActivityEntry[] = [
  ...todayActivities,
  { id: "preview-act-4", occurredAt: "2026-08-18T15:28:00+03:00", orderId: "order-61829", orderNumber: "61829", source: "outletperdele", fromStage: "Preluată", toStage: "Pregătire Material", meters: 5.2 },
  { id: "preview-act-5", occurredAt: "2026-08-17T11:06:00+03:00", orderId: "order-61833", orderNumber: "61833", source: "trendhome", fromStage: "Verificată", toStage: "Preluată", meters: 8.4 },
];

const monthActivities: ActivityEntry[] = [
  ...weekActivities,
  { id: "preview-act-6", occurredAt: "2026-08-12T08:41:00+03:00", orderId: "order-b2b-1048", orderNumber: "B2B-1048", source: "b2b", fromStage: "Înregistrată", toStage: "Preluată", meters: 24 },
];

export const previewActivityPages: Record<"today" | "7days" | "month" | "custom", ActivityPage> = {
  today: { items: todayActivities, summary: { processed: 11, meters: 37.6, handedOver: 8, inProgress: 3 } },
  "7days": { items: weekActivities, summary: { processed: 48, meters: 162.4, handedOver: 31, inProgress: 3 } },
  month: { items: monthActivities, summary: { processed: 187, meters: 624.8, handedOver: 142, inProgress: 3 } },
  custom: { items: monthActivities, summary: { processed: 187, meters: 624.8, handedOver: 142, inProgress: 3 } },
};
