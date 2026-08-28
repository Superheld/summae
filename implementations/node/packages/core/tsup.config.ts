import { defineConfig } from 'tsup';

// Dual-format build for external consumers: ESM (.js) + CJS (.cjs) + types. big.js stays
// external (it is in dependencies). Dev keeps running through the TS source exports — this
// build only applies to `pnpm build` / publish.
export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
  treeshake: true,
});
