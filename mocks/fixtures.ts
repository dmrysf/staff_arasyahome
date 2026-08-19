import type { ActivityEntry, Employee, StaffOrder } from "../domain/models";

export const demoEmployee: Employee = {
  employeeUuid: "7ec92458-6986-4aa9-8d21-cbad99d2121e",
  employeeCode: "EMP-0001",
  displayName: "Ali Demir",
  username: "ali.demo",
  department: "Pregătire material",
  departmentKey: "pregatire-material",
  permissions: ["orders.scan", "orders.view_mine", "orders.claim", "orders.advance_stage", "orders.handover", "history.view_mine", "profile.view_self"],
  allowedStageIds: ["material-preparation"],
  role: "employee",
  status: "active",
  locale: "ro",
};

export const demoOrders: StaffOrder[] = [
  {
    id: "order-61833",
    source: "trendhome",
    orderNumber: "61833",
    productionStageId: "waiting",
    sourceCommerceStatus: { code: "processing", label: "Processing" },
    employeeAllowedAction: { id: "accept", label: "Preia comanda" },
    products: [{ id: "item-dv302", name: "Draperie Velvet", code: "DV-302", color: "Bej", dimensions: "300 × 260 cm", meters: 8.4, quantity: 1 }],
    productionNotes: "Verificați sensul materialului înainte de pregătire.",
    employeeRelation: { employeeUuid: "7ec92458-6986-4aa9-8d21-cbad99d2121e", type: "assigned", lastActionAt: "2026-08-18T10:42:00+03:00" },
    updatedAt: "2026-08-18T10:42:00+03:00",
    status: "in_progress",
    version: 4,
  },
  {
    id: "order-61829",
    source: "outletperdele",
    orderNumber: "61829",
    productionStageId: "side-hem",
    sourceCommerceStatus: { code: "processing", label: "Processing" },
    employeeAllowedAction: { id: "handover", label: "Predă comanda" },
    products: [{ id: "item-in114", name: "Perdea Ines", code: "IN-114", color: "Alb cald", dimensions: "420 × 250 cm", meters: 5.2, quantity: 1 }],
    acceptedAt: "2026-08-18T09:48:00+03:00",
    employeeRelation: { employeeUuid: "7ec92458-6986-4aa9-8d21-cbad99d2121e", type: "claimed", lastActionAt: "2026-08-18T10:17:00+03:00" },
    updatedAt: "2026-08-18T10:17:00+03:00",
    status: "in_progress",
    version: 2,
  },
  {
    id: "order-trendyol-1048",
    source: "trendyol",
    orderNumber: "TY-1048",
    productionStageId: "quality-control",
    sourceCommerceStatus: { code: "Picking", label: "Picking" },
    products: [{ id: "item-hm022", name: "Draperie hotelieră Mistral", code: "HM-022", color: "Gri", dimensions: "280 × 240 cm", meters: 24, quantity: 6 }],
    acceptedAt: "2026-08-17T14:12:00+03:00",
    employeeRelation: { employeeUuid: "7ec92458-6986-4aa9-8d21-cbad99d2121e", type: "handover_out", lastActionAt: "2026-08-18T08:34:00+03:00" },
    updatedAt: "2026-08-18T08:34:00+03:00",
    status: "handed_over",
    version: 8,
  },
];

export const demoActivities: ActivityEntry[] = [
  { id: "act-1", occurredAt: "2026-08-18T10:42:00+03:00", orderId: "order-61833", orderNumber: "61833", source: "trendhome", fromStageId: "waiting", fromStageLabelSnapshot: "În așteptare", toStageId: "material-preparation", toStageLabelSnapshot: "Pregătire material", meters: 8.4 },
  { id: "act-2", occurredAt: "2026-08-18T10:17:00+03:00", orderId: "order-61829", orderNumber: "61829", source: "outletperdele", fromStageId: "bottom-hem", fromStageLabelSnapshot: "Tivul de jos", toStageId: "side-hem", toStageLabelSnapshot: "Tivul lateral", meters: 5.2 },
  { id: "act-3", occurredAt: "2026-08-18T08:34:00+03:00", orderId: "order-trendyol-1048", orderNumber: "TY-1048", source: "trendyol", fromStageId: "sewing-finishing", fromStageLabelSnapshot: "Finisare coasere", toStageId: "quality-control", toStageLabelSnapshot: "Control calitate", meters: 24 },
];
