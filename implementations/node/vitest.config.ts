import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['packages/*/test/**/*.test.ts', 'runner/test/**/*.test.ts'],
    // Coverage fest im Testlauf verdrahtet (`enabled`): jeder `pnpm test` misst und
    // scheitert unter den Schwellen. Schwellen = Floor knapp unter Ist — nur steigen,
    // nie fallen. Gemessen wird ausgelieferter Code (packages/*/src), nicht runner/.
    coverage: {
      enabled: true,
      provider: 'v8',
      include: ['packages/core/src/**/*.ts'],
      reporter: ['text', 'html'],
      thresholds: {
        statements: 85,
        branches: 70,
        functions: 90,
        lines: 88,
      },
    },
  },
});
