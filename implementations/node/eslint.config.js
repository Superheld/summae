import js from '@eslint/js';
import tseslint from 'typescript-eslint';

// Kern framework-frei (strukturelles Pendant zu PHP „kein use Illuminate\…"):
// weder Web-Framework noch DB-Treiber im core.
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

// Achse 2 (Substrat-Grenze): das Substrat ist eingefroren und liegt zuunterst —
// es darf nichts von den Schichten darüber importieren.
// records/ ist eine Daten-Schicht, die das Substrat referenzieren darf (z. B. PostResult);
// die Grenze schützt vor Policy/Recht im Substrat, nicht vor Daten-Records.
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
    // Bewusst ungenutzte Bindungen per _-Präfix kennzeichnen.
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
    // Substrat-Grenze: substrate/ importiert nichts von oben (zusätzlich zu framework-frei).
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
                'Substrat ist eingefroren und liegt zuunterst — kein Import von oben (Achse 2, core/src/CLAUDE.md).',
            },
          ],
        },
      ],
    },
  },
);
