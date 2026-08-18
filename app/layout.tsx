import type { Metadata, Viewport } from "next";
import { headers } from "next/headers";
import "./globals.css";
import "../styles/app.css";
import { PwaRegistration } from "./PwaRegistration";

export async function generateMetadata(): Promise<Metadata> {
  const requestHeaders = await headers();
  const host = requestHeaders.get("x-forwarded-host") ?? requestHeaders.get("host") ?? "localhost";
  const protocol = requestHeaders.get("x-forwarded-proto") ?? (host.startsWith("localhost") ? "http" : "https");
  const origin = `${protocol}://${host}`;
  const image = new URL("/og.png", origin).toString();
  const description = "Operațiuni rapide și sigure pentru echipa Arasya.";
  return {
    metadataBase: new URL(origin),
    title: "Arasya Staff",
    description,
    applicationName: "Arasya Staff",
    manifest: "/manifest.webmanifest",
    icons: { icon: "/favicon.svg", shortcut: "/favicon.svg" },
    openGraph: { title: "Arasya Staff", description, images: [{ url: image, width: 1712, height: 908, alt: "Arasya Staff" }] },
    twitter: { card: "summary_large_image", title: "Arasya Staff", description, images: [image] },
  };
}

export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
  themeColor: "#0b0d0f",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="ro">
      <body>{children}<PwaRegistration /></body>
    </html>
  );
}
