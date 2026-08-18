import type { ActivityEntry, Employee, StaffOrder } from "../domain/models";

export const demoEmployee: Employee = {
  employeeUuid: "7ec92458-6986-4aa9-8d21-cbad99d2121e",
  name: "Ali Demir",
  username: "ali.demo",
  department: "Pregătire Material",
  productionStagePermissions: ["material-preparation"],
  role: "employee",
  locale: "ro",
};

export const demoOrders: StaffOrder[] = [
  {
    id: "order-61833",
    source: "trendhome",
    orderNumber: "61833",
    currentStage: { id: "waiting", ordinal: 1, label: "În așteptare" },
    nextStage: { id: "material-preparation", ordinal: 2, label: "Pregătire Material" },
    employeeAllowedAction: { id: "accept", label: "Preia comanda" },
    products: [{ id: "item-dv302", name: "Draperie Velvet", code: "DV-302", color: "Bej", dimensions: "300 × 260 cm", meters: 8.4, quantity: 1 }],
    productionNotes: "Verificați sensul materialului înainte de croire.",
    employeeRelation: { employeeUuid: "7ec92458-6986-4aa9-8d21-cbad99d2121e", type: "assigned", lastActionAt: "2026-08-18T10:42:00+03:00" },
    updatedAt: "2026-08-18T10:42:00+03:00",
    status: "in_progress",
    version: 4,
  },
  {
    id: "order-61829",
    source: "outletperdele",
    orderNumber: "61829",
    currentStage: { id: "material-preparation", ordinal: 2, label: "Pregătire Material" },
    nextStage: { id: "cutting", ordinal: 3, label: "Croire" },
    employeeAllowedAction: { id: "handover", label: "Predă comanda" },
    products: [{ id: "item-in114", name: "Perdea Ines", code: "IN-114", color: "Alb cald", dimensions: "420 × 250 cm", meters: 5.2, quantity: 1 }],
    acceptedAt: "2026-08-18T09:48:00+03:00",
    employeeRelation: { employeeUuid: "7ec92458-6986-4aa9-8d21-cbad99d2121e", type: "claimed", lastActionAt: "2026-08-18T10:17:00+03:00" },
    updatedAt: "2026-08-18T10:17:00+03:00",
    status: "in_progress",
    version: 2,
  },
  {
    id: "order-b2b-1048",
    source: "b2b",
    orderNumber: "B2B-1048",
    currentStage: { id: "quality", ordinal: 5, label: "Control calitate" },
    products: [{ id: "item-hm022", name: "Draperie hotelieră Mistral", code: "HM-022", color: "Gri", dimensions: "280 × 240 cm", meters: 24, quantity: 6 }],
    acceptedAt: "2026-08-17T14:12:00+03:00",
    employeeRelation: { employeeUuid: "7ec92458-6986-4aa9-8d21-cbad99d2121e", type: "handover_out", lastActionAt: "2026-08-18T08:34:00+03:00" },
    updatedAt: "2026-08-18T08:34:00+03:00",
    status: "handed_over",
    version: 8,
  },
];

export const demoActivities: ActivityEntry[] = [
  { id: "act-1", occurredAt: "2026-08-18T10:42:00+03:00", orderId: "order-61833", orderNumber: "61833", source: "trendhome", fromStage: "Preluată", toStage: "Pregătire Material", meters: 8.4 },
  { id: "act-2", occurredAt: "2026-08-18T10:17:00+03:00", orderId: "order-61829", orderNumber: "61829", source: "outletperdele", fromStage: "Predată", toStage: "Croire", meters: 5.2 },
  { id: "act-3", occurredAt: "2026-08-18T08:34:00+03:00", orderId: "order-b2b-1048", orderNumber: "B2B-1048", source: "b2b", fromStage: "Finalizată", toStage: "Control calitate", meters: 24 },
];
