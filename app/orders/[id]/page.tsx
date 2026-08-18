import { StaffEntry } from "../../StaffEntry";
export default async function OrderPage({ params }: { params: Promise<{ id: string }> }) { const { id } = await params; return <StaffEntry route={`/orders/${encodeURIComponent(id)}`} />; }
