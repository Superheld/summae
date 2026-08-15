import { readFileSync } from 'node:fs';
import { DomainError, TenantOperations } from '@superheld/summae-core';
import { Command } from 'commander';
import { exitCodeFor } from './exit-codes.js';
import { defaultPackLibraryDir, packToRules } from './pack-library.js';
import { Workspace } from './workspace.js';

function parseJson(raw: string): Record<string, unknown> {
  const text = raw.startsWith('@') ? readFileSync(raw.slice(1), 'utf8') : raw;
  const parsed: unknown = JSON.parse(text);
  return parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Record<string, unknown>) : {};
}

function emit(value: unknown): void {
  console.log(JSON.stringify(value));
}

/**
 * Every failure leaves as one line of JSON — the CLI's output contract holds for
 * unanticipated errors too, not just for domain errors (api.md F-IO-003).
 *
 * A caller that parses stdout must never be handed a stack trace: an agent or app
 * cannot branch on one, and a crash mid-pipeline looks like a transport failure
 * rather than a rejected input. Anything that is not a `DomainError` (malformed
 * JSON input, a value object rejecting a parameter, a missing workspace) becomes
 * `E_UNEXPECTED` with exit code 1 — which is what the error-code contract already
 * reserves for "unknown error", so no catalogue entry is added or moved.
 *
 * `E_UNEXPECTED` is deliberately NOT a catalogue code: seeing it means summae
 * failed to classify the problem, and that is a bug report, not a case to handle.
 */
function reportError(error: unknown): void {
  if (error instanceof DomainError) {
    emit({
      error: error.errorCode,
      message: error.message,
      details: Object.keys(error.details).length === 0 ? {} : error.details,
    });
    process.exitCode = exitCodeFor(error.errorCode);
    return;
  }
  emit({
    error: 'E_UNEXPECTED',
    message: error instanceof Error ? error.message : String(error),
    details: {},
  });
  process.exitCode = 1;
}

interface CommonOptions {
  dir: string;
}
interface InitOptions extends CommonOptions {
  name: string;
  currency: string;
  rules?: string;
  pack?: string;
  packLibrary?: string;
  firstFiscalYear?: string;
}

/** Builds the CLI (init/op/report) — counterpart to PHP's Symfony Console app. */
export function buildProgram(): Command {
  const program = new Command();
  program.name('summae').description('summae — accounting via JSON input/output').version('0.1.0');

  program
    .command('init')
    .description('Create workspace (summae.json + SQLite database)')
    .requiredOption('--name <name>', 'Tenant name')
    .option('--currency <iso>', 'Base currency (ISO 4217)', 'EUR')
    .option('--rules <file>', 'JSON file with pack data (alternative to --pack)')
    .option('--pack <id>', 'Select shipped pack from the library (e.g. "de", "default")')
    .option('--pack-library <dir>', 'Path to the pack library', defaultPackLibraryDir)
    .option('--first-fiscal-year <year>', 'Create first fiscal year (e.g. 2026)')
    .option('--dir <dir>', 'Working directory', '.')
    .action((opts: InitOptions) => {
      try {
        initialize(opts);
      } catch (error) {
        reportError(error);
      }
    });

  program
    .command('op')
    .description('Run write operation (post, postVoucher, settle, …)')
    .argument('<operation>', 'Operation name per api.md')
    .option('--input <json>', 'Input as JSON or @file', '{}')
    .option('--dir <dir>', 'Working directory', '.')
    .action((operation: string, opts: CommonOptions & { input: string }) => {
      try {
        const payload = parseJson(opts.input);
        emit(new TenantOperations(Workspace.in(opts.dir).tenant()).execute(operation, payload));
      } catch (error) {
        reportError(error);
      }
    });

  program
    .command('report')
    .description('Compute projection (trialBalance, cashBasisReport, vatReturn, …)')
    .argument('<projection>', 'Projection name per api.md')
    .option('--params <json>', 'Parameters as JSON or @file', '{}')
    .option('--dir <dir>', 'Working directory', '.')
    .action((projection: string, opts: CommonOptions & { params: string }) => {
      try {
        const params = parseJson(opts.params);
        emit(new TenantOperations(Workspace.in(opts.dir).tenant()).project(projection, params));
      } catch (error) {
        reportError(error);
      }
    });

  return program;
}

/** The init command's body — extracted so the command can wrap it in the error boundary. */
function initialize(opts: InitOptions): void {
    let rules: Record<string, unknown>;
    if (typeof opts.pack === 'string') {
      rules = packToRules(opts.pack, opts.packLibrary ?? defaultPackLibraryDir);
      if (typeof opts.firstFiscalYear === 'string') {
        const y = String(Number(opts.firstFiscalYear)).padStart(4, '0');
        rules.fiscalYears = [{ year: Number(opts.firstFiscalYear), start: `${y}-01-01`, end: `${y}-12-31` }];
      }
    } else {
      rules = typeof opts.rules === 'string' ? parseJson(`@${opts.rules}`) : {};
    }
    const workspace = Workspace.in(opts.dir);
    workspace.initialize(opts.name, opts.currency, rules);

    // SF-01: create master data from the rules file directly — immediately postable.
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
}

export function run(argv: string[]): void {
  buildProgram().parse(argv);
}
