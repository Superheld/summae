import { readFileSync } from 'node:fs';
import { DomainError, TenantOperations } from '@superheld/summae-core';
import { Command } from 'commander';
import { exitCodeFor } from './exit-codes.js';
import { Workspace } from './workspace.js';

function parseJson(raw: string): Record<string, unknown> {
  const text = raw.startsWith('@') ? readFileSync(raw.slice(1), 'utf8') : raw;
  const parsed: unknown = JSON.parse(text);
  return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Record<string, unknown>) : {};
}

function emit(value: unknown): void {
  console.log(JSON.stringify(value));
}

/** DomainError → JSON-Fehler + Exit-Code; sonst neu werfen. */
function reportDomainError(error: unknown): void {
  if (!(error instanceof DomainError)) throw error;
  emit({
    error: error.errorCode,
    message: error.message,
    details: Object.keys(error.details).length === 0 ? {} : error.details,
  });
  process.exitCode = exitCodeFor(error.errorCode);
}

interface CommonOptions {
  dir: string;
}
interface InitOptions extends CommonOptions {
  name: string;
  currency: string;
  rules?: string;
}

/** Baut die CLI (init/op/report) — Pendant zu PHPs Symfony-Console-App. */
export function buildProgram(): Command {
  const program = new Command();
  program.name('summae').description('summae — Buchführung über JSON-Ein/Ausgabe').version('0.1.0');

  program
    .command('init')
    .description('Arbeitsbereich anlegen (summae.json + SQLite-Datenbank)')
    .requiredOption('--name <name>', 'Mandantenname')
    .option('--currency <iso>', 'Basiswährung (ISO 4217)', 'EUR')
    .option('--rules <file>', 'JSON-Datei mit Regelmodul-Daten')
    .option('--dir <dir>', 'Arbeitsverzeichnis', '.')
    .action((opts: InitOptions) => {
      const rules = typeof opts.rules === 'string' ? parseJson(`@${opts.rules}`) : {};
      const workspace = Workspace.in(opts.dir);
      workspace.initialize(opts.name, opts.currency, rules);

      // SF-01: Stammdaten aus der Regeldatei direkt anlegen — sofort buchbar.
      const ops = new TenantOperations(workspace.tenant());
      const created = { accounts: 0, fiscalYears: 0 };
      for (const account of Array.isArray(rules.accounts) ? rules.accounts : []) {
        if (account !== null && typeof account === 'object') {
          ops.execute('createAccount', account as Record<string, unknown>);
          created.accounts++;
        }
      }
      for (const fiscalYear of Array.isArray(rules.fiscalYears) ? rules.fiscalYears : []) {
        if (fiscalYear !== null && typeof fiscalYear === 'object') {
          ops.execute('createFiscalYear', fiscalYear as Record<string, unknown>);
          created.fiscalYears++;
        }
      }
      emit({ initialized: true, tenant: opts.name, baseCurrency: opts.currency, created });
    });

  program
    .command('op')
    .description('Schreiboperation ausführen (post, postVoucher, settle, …)')
    .argument('<operation>', 'Operationsname laut api.md')
    .option('--input <json>', 'Eingabe als JSON oder @datei', '{}')
    .option('--dir <dir>', 'Arbeitsverzeichnis', '.')
    .action((operation: string, opts: CommonOptions & { input: string }) => {
      try {
        const payload = parseJson(opts.input);
        emit(new TenantOperations(Workspace.in(opts.dir).tenant()).execute(operation, payload));
      } catch (error) {
        reportDomainError(error);
      }
    });

  program
    .command('report')
    .description('Projektion berechnen (trialBalance, cashBasisReport, vatReturn, …)')
    .argument('<projection>', 'Projektionsname laut api.md')
    .option('--params <json>', 'Parameter als JSON oder @datei', '{}')
    .option('--dir <dir>', 'Arbeitsverzeichnis', '.')
    .action((projection: string, opts: CommonOptions & { params: string }) => {
      try {
        const params = parseJson(opts.params);
        emit(new TenantOperations(Workspace.in(opts.dir).tenant()).project(projection, params));
      } catch (error) {
        reportDomainError(error);
      }
    });

  return program;
}

export function run(argv: string[]): void {
  buildProgram().parse(argv);
}
