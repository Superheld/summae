import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['packages/*/test/**/*.test.ts', 'runner/test/**/*.test.ts'],
    // Coverage is wired into the test run (`enabled`): every `pnpm test` measures and fails
    // below the thresholds. Every package carries its OWN floor, set just below what it
    // measures today — a shared number would either be meaningless for core or unreachable
    // for knex. Floors are a ratchet: they may only rise, never fall.
    coverage: {
      enabled: true,
      provider: 'v8',
      include: ['packages/*/src/**/*.ts', 'runner/src/**/*.ts'],
      reporter: ['text', 'html'],
      thresholds: {
        // Measured 2026-08-16 (lines): core 93.15 · cli 92.66 · knex 89.08 · runner 92.72.
        // knex used to be the mildest floor because it had no tests of its own and was covered
        // only indirectly through the CLI and the cross test. It has its own adapter suite since
        // 2026-08-16 (NF-015, twin of the PHP packages/laravel suite), which is what raised it.
        'packages/core/src/**': { statements: 90, branches: 79, functions: 92, lines: 92 },
        'packages/cli/src/**': { statements: 88, branches: 67, functions: 88, lines: 91 },
        'packages/knex/src/**': { statements: 85, branches: 56, functions: 85, lines: 88 },
        'runner/src/**': { statements: 91, branches: 80, functions: 95, lines: 91 },
      },
    },
  },
});
