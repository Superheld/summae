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
        // Measured 2026-08-15 (lines): core 93.15 · cli 92.66 · knex 85.59 · runner 92.72.
        // The domain core is the strictest floor, knex the mildest: it has no tests of its
        // own and is covered indirectly through the CLI and the cross-test, which is exactly
        // why its floor exists — so that indirect coverage cannot quietly disappear.
        'packages/core/src/**': { statements: 90, branches: 79, functions: 92, lines: 92 },
        'packages/cli/src/**': { statements: 88, branches: 67, functions: 88, lines: 91 },
        'packages/knex/src/**': { statements: 82, branches: 50, functions: 81, lines: 84 },
        'runner/src/**': { statements: 91, branches: 80, functions: 95, lines: 91 },
      },
    },
  },
});
