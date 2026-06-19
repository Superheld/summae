import js from '@eslint/js';
import tseslint from 'typescript-eslint';

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
    // Kern framework-frei — strukturelles Pendant zu PHP "kein use Illuminate\…".
    // Adapter sind eigene Pakete (ab Node-M4): Persistenz via Knex (Schema-/Query-Builder,
    // Pendant zu illuminate/database) + better-sqlite3 (sqlite) / pg (postgres) als Treiber;
    // HTTP via NestJS/Express o. Ä. Im Kern weder Framework noch DB-Treiber.
    files: ['packages/core/**/*.ts'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [
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
          ],
        },
      ],
    },
  },
);
