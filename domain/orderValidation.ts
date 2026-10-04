import { StaffServiceError, type ProductionItem, type StaffOrder } from "./models";

function isNonEmptyString(value: unknown): value is string {
  return typeof value === "string" && value.trim().length > 0;
}

export function isUsableProductionItem(value: unknown): value is ProductionItem {
  if (typeof value !== "object" || value === null) return false;
  const item = value as Partial<ProductionItem>;
  return (
    isNonEmptyString(item.id) &&
    isNonEmptyString(item.name) &&
    (item.code === undefined || isNonEmptyString(item.code)) &&
    typeof item.quantity === "number" &&
    Number.isFinite(item.quantity) &&
    item.quantity > 0
  );
}

export function getUsableProductionProducts(order: StaffOrder): ProductionItem[] {
  const products: unknown = (order as { products?: unknown }).products;
  return Array.isArray(products) ? products.filter(isUsableProductionItem) : [];
}

export function requireProductionProducts(order: StaffOrder): StaffOrder {
  const products = getUsableProductionProducts(order);
  if (products.length === 0) throw new StaffServiceError("ORDER_PRODUCTS_UNAVAILABLE");
  return { ...order, products };
}
