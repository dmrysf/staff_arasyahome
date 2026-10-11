import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, statSync } from "node:fs";
import path from "node:path";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { ArasyaLogo, TrendyolLogo } from "../components/brand/BrandLogo";

const root = path.resolve(import.meta.dirname, "..");

test("logos reserve their exact space (intrinsic width and height) and offer WebP with a PNG fallback", () => {
  const arasya = renderToStaticMarkup(createElement(ArasyaLogo, { priority: true }));
  assert.match(arasya, /<picture class="brand-logo"><source type="image\/webp" srcSet="[^"]*arasya-logo\.webp"\/><img src="[^"]*arasya-logo\.png" width="599" height="96" alt="Arasya Home"/);
  assert.match(arasya, /loading="eager"/);
  assert.match(arasya, /fetchPriority="high"/);
  const trendyol = renderToStaticMarkup(createElement(TrendyolLogo, { alt: "" }));
  assert.match(trendyol, /width="314" height="72" alt=""/);
  for (const file of ["arasya-logo.webp", "arasya-logo.png", "trendyol-logo.webp", "trendyol-logo.png"]) {
    assert.ok(statSync(path.join(root, "components/brand", file)).size < 40_000, `${file} stays small`);
  }
});

test("the Arasya wordmark replaces the letter mark on login, password, start-up, header and sidebar; Trendyol stays in its workspace", () => {
  const read = (file: string) => readFileSync(path.join(root, file), "utf8");
  for (const file of ["features/auth/LoginScreen.tsx", "features/auth/ChangePasswordScreen.tsx", "components/ui/States.tsx", "features/auth/NoStaffAccessScreen.tsx", "components/AppShell.tsx", "components/navigation/BottomNavigation.tsx"]) {
    const source = read(file);
    assert.match(source, /<ArasyaLogo /, file);
    assert.doesNotMatch(source, /className="brand-mark">A</, file);
  }
  for (const file of ["features/workspaces/TrendyolDashboard.tsx", "features/trendyol/TrendyolInboxScreen.tsx", "features/trendyol/TrendyolPackageScreen.tsx"]) assert.match(read(file), /<TrendyolLogo /, file);
  for (const file of ["features/workspaces/StageWorkspace.tsx", "features/home/HomeScreen.tsx", "features/orders/OrderDetailScreen.tsx"]) assert.doesNotMatch(read(file), /TrendyolLogo/, `${file}: no marketplace logo outside the Trendyol workspace`);
});
