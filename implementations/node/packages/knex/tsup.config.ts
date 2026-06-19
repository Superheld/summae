import { defineConfig } from 'tsup';

// Dual-Format-Build (ESM + CJS + Typen). core, knex und better-sqlite3 bleiben
// extern (stehen in dependencies). Dev läuft über die TS-Source-Exports.
export default defineConfig({
  entry: ['src/index.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
  treeshake: true,
  external: ['@superheld/summae-core', 'knex', 'better-sqlite3'],
});
