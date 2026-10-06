import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import process from "node:process";
import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

const { version } = JSON.parse(readFileSync(new URL("./package.json", import.meta.url), "utf8"));

function releaseRevision() {
  const ciRevision = process.env.VERCEL_GIT_COMMIT_SHA
    || process.env.RENDER_GIT_COMMIT
    || process.env.GITHUB_SHA;

  if (ciRevision) return ciRevision.slice(0, 12);

  try {
    const repository = fileURLToPath(new URL("..", import.meta.url)).replace(/[\\/]+$/, "");
    const safeDirectory = repository.replaceAll("\\", "/");
    return execFileSync("git", ["-c", `safe.directory=${safeDirectory}`, "rev-parse", "--short=12", "HEAD"], {
      cwd: repository,
      encoding: "utf8",
      windowsHide: true,
      stdio: ["ignore", "pipe", "ignore"],
    }).trim();
  } catch {
    return "unknown";
  }
}

export default defineConfig(({ mode }) => {
  const deployToLaravel = mode === "laravel";
  const revision = releaseRevision();
  const buildId = `${version}+${revision}`;

  return {
    base: deployToLaravel ? "/app/" : "/",
    plugins: [
      react(),
      {
        name: "attendancehub-release-metadata",
        generateBundle() {
          this.emitFile({
            type: "asset",
            fileName: "version.json",
            source: JSON.stringify({ version, build_id: buildId }),
          });
        },
      },
    ],
    define: {
      "import.meta.env.VITE_APP_VERSION": JSON.stringify(version),
      "import.meta.env.VITE_APP_BUILD_ID": JSON.stringify(buildId),
    },
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
