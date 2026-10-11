/**
 * Brand wordmarks. The files are imported through `new URL(…, import.meta.url)`, so Vite ships them as hashed,
 * immutable assets (kept with every retained release for rollback). Each image carries its intrinsic width and
 * height, so the browser reserves the exact space before it loads: the logo never shifts the layout.
 * WebP first, PNG for older browsers.
 */
const arasya = { webp: new URL("./arasya-logo.webp", import.meta.url).href, png: new URL("./arasya-logo.png", import.meta.url).href, width: 599, height: 96 };
const trendyol = { webp: new URL("./trendyol-logo.webp", import.meta.url).href, png: new URL("./trendyol-logo.png", import.meta.url).href, width: 314, height: 72 };

type LogoProps = { className?: string; alt?: string; priority?: boolean };

function Logo({ source, className, alt, priority }: LogoProps & { source: typeof arasya; alt: string }) {
  return (
    <picture className={`brand-logo${className ? ` ${className}` : ""}`}>
      <source type="image/webp" srcSet={source.webp} />
      <img src={source.png} width={source.width} height={source.height} alt={alt} decoding="async" loading={priority ? "eager" : "lazy"} fetchPriority={priority ? "high" : "auto"} draggable={false} />
    </picture>
  );
}

/** Arasya Home wordmark. `alt=""` when a neighbouring label already names the brand. */
export function ArasyaLogo({ className, alt = "Arasya Home", priority = false }: LogoProps) {
  return <Logo source={arasya} className={className} alt={alt} priority={priority} />;
}

/** Trendyol wordmark, shown only inside the Trendyol workspace as the marketplace the work comes from. */
export function TrendyolLogo({ className, alt = "Trendyol" }: LogoProps) {
  return <Logo source={trendyol} className={className} alt={alt} />;
}
