import { useEffect } from "react";

export function PwaRegistration() {
  useEffect(() => {
    if (!import.meta.env.PROD || !("serviceWorker" in navigator)) return;
    const register = () => { navigator.serviceWorker.register("/sw.js").then((registration) => registration.update()).catch(() => undefined); };
    window.addEventListener("load", register, { once: true });
    return () => window.removeEventListener("load", register);
  }, []);
  return null;
}
