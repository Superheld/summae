import { defineConfig } from 'tsup';

// index = library API, summae = executable bin (shebang). core/knex/commander
// + better-sqlite3 stay external.
export default defineConfig({
  entry: ['src/index.ts', 'src/summae.ts'],
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
  banner: { js: '#!/usr/bin/env node' },
  external: ['@superheld/summae-core', '@superheld/summae-knex', 'commander', 'better-sqlite3', 'knex'],
});
