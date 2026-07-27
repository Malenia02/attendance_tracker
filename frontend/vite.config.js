import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig(({ mode }) => {
  const deployToLaravel = mode === "laravel";

  return {
    base: deployToLaravel ? "/app/" : "/",
    plugins: [react()],
    build: deployToLaravel
      ? {
          outDir: "../backend/public/app",
          emptyOutDir: true,
        }
      : undefined,
    server: {
      host: "0.0.0.0",
      proxy: {
        "/sanctum": {
          target: "http://127.0.0.1:8000",
          changeOrigin: true,
        },
        "/api": {
          target: "http://127.0.0.1:8000",
          changeOrigin: true,
        },
      },
    },
  };
})
