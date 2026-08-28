import js from '@eslint/js';
import tseslint from 'typescript-eslint';

// Framework-free core (the structural counterpart to PHP's "no `use Illuminate\…` in the
// core"): neither a web framework nor a database driver may be imported there.
const FRAMEWORK_PATTERNS = [
  'express',
  'fastify',
  '@nestjs/*',
  'knex',
  'better-sqlite3',
  'pg',
  'prisma',
  '@prisma/*',
  'typeorm',
  'sequelize',
];

// Axis 2 (the substrate boundary): the substrate is frozen and sits lowest — it may import
// nothing from the layers above it.
// `records/` is a data layer and is allowed to reference the substrate (PostResult, for
// instance); the boundary guards against policy and law reaching the substrate, not against
// data records.
const ABOVE_SUBSTRATE_PATTERNS = [
  '**/policies/**',
  '**/ledger/**',
  '**/tax/**',
  '**/assets/**',
  '**/costing/**',
  '**/projection/**',
  '**/mapping/**',
  '**/composition/**',
  '**/partner/**',
  '**/port.js',
  '**/in-memory.js',
];

const FRAMEWORK_FREE = {
  group: FRAMEWORK_PATTERNS,
  message: 'Kern framework-frei: kein Web-Framework/DB-Treiber im core.',
};

export default tseslint.config(
  { ignores: ['**/dist/**', '**/node_modules/**'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    // Mark deliberately unused bindings with a leading underscore.
    rules: {
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
    },
  },
  {
    files: ['packages/core/**/*.ts'],
    rules: {
      'no-restricted-imports': ['error', { patterns: [FRAMEWORK_FREE] }],
    },
  },
  {
    // Substrate boundary: substrate/ imports nothing from above (on top of framework-free).
    files: ['packages/core/src/substrate/**/*.ts'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            FRAMEWORK_FREE,
            {
              group: ABOVE_SUBSTRATE_PATTERNS,
              message:
                'The substrate is frozen and sits lowest — no import from above (axis 2, core/src/CLAUDE.md).',
            },
          ],
        },
      ],
    },
  },
);
