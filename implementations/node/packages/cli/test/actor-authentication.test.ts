import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { run } from '../src/cli.js';

/**
 * SPEC-020 — the embedding can say who is behind `actor`, and summae reports it as a declaration.
 *
 * Reported by an embedding application as its F-30, and it is the one entry on that list where the
 * library was not wrong and the answer was still unusable. `auditTrail.actorIsAuthenticated` is a
 * constant `false`, which is exactly right — summae is handed a string and cannot know where it
 * came from. But the application generating a Verfahrensdokumentation puts that field in under
 * obligation A-1 as "Urheber geprüft: **nein**", and then it grew a login: scrypt in the people
 * register, a signed session cookie, a gate nothing passes but the login screen. The document went
 * on telling an auditor that every entry's author is unverified about an installation where a
 * password had been proved before the actor was ever set.
 *
 * An understatement in a compliance document is cheaper than an overstatement and it is not free.
 *
 * Three states, and the third is the point: **not declared is not the same as declared false**. An
 * unanswered question and a denial read differently to an auditor, so `null` survives as `null` and
 * a generator that turns it into "nein" is making a claim summae did not make.
 *
 * The declaration is workspace configuration, not a posting and not part of the books: an embedding
 * that drops its login tomorrow must not leave yesterday's claim behind in a record. That is why it
 * lives in `summae.json` and reaches the tenant on every open, and why this test edits that file
 * rather than calling an operation.
 *
 * The SAME cases live in the PHP ActorAuthenticationTest.
 */
function capture(argv: string[], dir: string): Record<string, unknown> {
  const out: string[] = [];
  const spy = vi.spyOn(console, 'log').mockImplementation((msg: unknown) => void out.push(String(msg)));
  const code = process.exitCode;
  try {
    run(['node', 'summae', ...argv, '--dir', dir]);
  } finally {
    spy.mockRestore();
    process.exitCode = code;
  }
  return JSON.parse(out[0] ?? '{}') as Record<string, unknown>;
}

function workspace(): string {
  const dir = mkdtempSync(join(tmpdir(), 'summae-actor-'));
  capture(['init', '--name', 'Actor GmbH', '--pack', 'default', '--currency', 'EUR', '--first-fiscal-year', '2026'], dir);
  return dir;
}

function auditTrail(dir: string): Record<string, unknown> {
  const description = capture(['report', 'systemDescription'], dir);
  return description.auditTrail as Record<string, unknown>;
}

function declare(dir: string, declaration: unknown): void {
  const path = join(dir, 'summae.json');
  const config = JSON.parse(readFileSync(path, 'utf8')) as Record<string, unknown>;
  config.actorAuthentication = declaration;
  writeFileSync(path, JSON.stringify(config));
}

describe('SPEC-020 — what the system description says about the actor', () => {
  it('says the library authenticates nobody, whatever the embedding declares', () => {
    const dir = workspace();
    const before = auditTrail(dir).actorAuthentication as Record<string, unknown>;
    expect(before.byLibrary, 'this can never go stale: summae proves no identity').toBe(false);

    declare(dir, { declared: true, method: 'scrypt password login, signed session cookie' });
    const after = auditTrail(dir).actorAuthentication as Record<string, unknown>;
    expect(after.byLibrary, 'a declaration about the embedding says nothing about the library').toBe(false);
  });

  it('reports an absent declaration as null, not as "no"', () => {
    const trail = auditTrail(workspace());
    expect(trail.actorAuthentication).toEqual({ byLibrary: false, declaredByEmbedding: null, method: null });
    // The old field keeps its meaning and its value — it was never wrong, only easy to misread.
    expect(trail.actorIsAuthenticated).toBe(false);
  });

  it('reports what the embedding declares, method and all', () => {
    const dir = workspace();
    declare(dir, { declared: true, method: 'scrypt password login, signed session cookie' });

    expect((auditTrail(dir) as Record<string, unknown>).actorAuthentication).toEqual({
      byLibrary: false,
      declaredByEmbedding: true,
      method: 'scrypt password login, signed session cookie',
    });
  });

  it('lets an embedding declare the opposite, which is a statement and not silence', () => {
    const dir = workspace();
    declare(dir, { declared: false });

    expect((auditTrail(dir) as Record<string, unknown>).actorAuthentication).toEqual({
      byLibrary: false,
      declaredByEmbedding: false,
      method: null,
    });
  });

  it('ignores a malformed declaration rather than half-reading it', () => {
    const dir = workspace();
    // No `declared`, so there is no declaration — a method alone states nothing, and guessing
    // `true` from its presence would put a claim in the document that nobody made.
    declare(dir, { method: 'something' });

    expect((auditTrail(dir) as Record<string, unknown>).actorAuthentication).toEqual({
      byLibrary: false,
      declaredByEmbedding: null,
      method: null,
    });
  });
});
