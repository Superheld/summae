import { defineConfig } from 'tsup';

// Dual-Format-Build für externe Konsumenten: ESM (.js) + CJS (.cjs) + Typen.
// big.js bleibt extern (steht in dependencies). Dev läuft weiter über die
// TS-Source-Exports — dieser Build greift nur für `pnpm build` / Publish.
export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
  treeshake: true,
});
