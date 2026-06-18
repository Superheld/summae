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
    // Adapter (NestJS/Express, Prisma/Knex) sind eigene Pakete (ab Node-M4).
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
