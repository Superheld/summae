import { defineConfig } from 'tsup';

// Dual-format build (ESM + CJS + types). core, knex and better-sqlite3 stay
// external (listed in dependencies). Dev runs via the TS source exports.
export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
  treeshake: true,
  external: ['@superheld/summae-core', 'knex', 'better-sqlite3'],
});
