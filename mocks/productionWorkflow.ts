import type { ProductionWorkflow } from "../domain/models";

export const previewProductionWorkflow: ProductionWorkflow = {
  id: "curtain-production",
  name: "Flux producție Arasya",
  version: 1,
  stages: [
    { id: "waiting", ordinal: 1, label: "În așteptare" },
    { id: "material-preparation", ordinal: 2, label: "Tăiere" },
    { id: "workshop-receiving", ordinal: 3, label: "Primire Croitorie" },
    { id: "labeling", ordinal: 4, label: "Etichetare" },
    { id: "material-straightening", ordinal: 5, label: "Îndreptare material" },
    { id: "bottom-hem", ordinal: 6, label: "Tivul de jos" },
    { id: "side-hem", ordinal: 7, label: "Tivul lateral" },
    { id: "ironing", ordinal: 8, label: "Călcare" },
    { id: "height", ordinal: 9, label: "Înălțime" },
    { id: "header-tape", ordinal: 10, label: "Rejansă" },
    { id: "sewing-finishing", ordinal: 11, label: "Finisare coasere" },
    { id: "quality-control", ordinal: 12, label: "Control calitate" },
    { id: "packing", ordinal: 13, label: "Împachetare" },
    { id: "delivery", ordinal: 14, label: "Livrare" },
  ],
};
