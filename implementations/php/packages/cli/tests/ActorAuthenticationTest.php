<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Cli\Cli;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
 * The SAME cases live in the Node actor-authentication.test.ts.
 */
final class ActorAuthenticationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/summae-actor-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->runCli([
            'command' => 'init',
            '--name' => 'Actor GmbH',
            '--dir' => $this->dir,
            '--pack' => 'default',
            '--currency' => 'EUR',
            '--first-fiscal-year' => '2026',
        ]);
    }

    public function testTheLibraryAuthenticatesNobodyWhateverTheEmbeddingDeclares(): void
    {
        self::assertFalse($this->actorAuthentication()['byLibrary'], 'this can never go stale: summae proves no identity');

        $this->declare(['declared' => true, 'method' => 'scrypt password login, signed session cookie']);

        self::assertFalse(
            $this->actorAuthentication()['byLibrary'],
            'a declaration about the embedding says nothing about the library',
        );
    }

    public function testAnAbsentDeclarationIsNullAndNotNo(): void
    {
        $trail = $this->auditTrail();

        self::assertSame(
            ['byLibrary' => false, 'declaredByEmbedding' => null, 'method' => null],
            $trail['actorAuthentication'],
        );
        // The old field keeps its meaning and its value — it was never wrong, only easy to misread.
        self::assertFalse($trail['actorIsAuthenticated']);
    }

    public function testWhatTheEmbeddingDeclaresIsReportedMethodAndAll(): void
    {
        $this->declare(['declared' => true, 'method' => 'scrypt password login, signed session cookie']);

        self::assertSame(
            [
                'byLibrary' => false,
                'declaredByEmbedding' => true,
                'method' => 'scrypt password login, signed session cookie',
            ],
            $this->actorAuthentication(),
        );
    }

    public function testAnEmbeddingMayDeclareTheOppositeWhichIsAStatementAndNotSilence(): void
    {
        $this->declare(['declared' => false]);

        self::assertSame(
            ['byLibrary' => false, 'declaredByEmbedding' => false, 'method' => null],
            $this->actorAuthentication(),
        );
    }

    public function testAMalformedDeclarationIsIgnoredRatherThanHalfRead(): void
    {
        // No `declared`, so there is no declaration — a method alone states nothing, and guessing
        // `true` from its presence would put a claim in the document that nobody made.
        $this->declare(['method' => 'something']);

        self::assertSame(
            ['byLibrary' => false, 'declaredByEmbedding' => null, 'method' => null],
            $this->actorAuthentication(),
        );
    }

    /** @param array<string, mixed> $declaration */
    private function declare(array $declaration): void
    {
        $path = $this->dir . '/summae.json';
        /** @var array<string, mixed> $config */
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $config['actorAuthentication'] = $declaration;
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function auditTrail(): array
    {
        $result = $this->runCli(['command' => 'report', 'projection' => 'systemDescription', '--dir' => $this->dir]);
        /** @var array<string, mixed> $trail */
        $trail = $result['json']['auditTrail'] ?? [];

        return $trail;
    }

    /** @return array<string, mixed> */
    private function actorAuthentication(): array
    {
        /** @var array<string, mixed> $block */
        $block = $this->auditTrail()['actorAuthentication'] ?? [];

        return $block;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{exit: int, json: array<string, mixed>, raw: string}
     */
    private function runCli(array $input): array
    {
        $output = new BufferedOutput();
        $exit = Cli::application()->run(new ArrayInput($input), $output);
        $raw = $output->fetch();
        $json = json_decode(trim($raw) === '' ? '{}' : trim($raw), true);

        /** @var array<string, mixed> $jsonArray */
        $jsonArray = is_array($json) ? $json : [];

        return ['exit' => $exit, 'json' => $jsonArray, 'raw' => $raw];
    }
}
