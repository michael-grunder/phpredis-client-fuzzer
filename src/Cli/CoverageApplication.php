<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Cli;

use Mgrunder\PhpredisCommandFuzzer\ClientConfiguration;
use Mgrunder\PhpredisCommandFuzzer\ClientFactory;
use Mgrunder\PhpredisCommandFuzzer\ClientType;
use Mgrunder\PhpredisCommandFuzzer\Coverage\CoverageAnalyzer;
use Mgrunder\PhpredisCommandFuzzer\Coverage\CoverageReport;
use Mgrunder\PhpredisCommandFuzzer\Coverage\IgnoreList;
use Mgrunder\PhpredisCommandFuzzer\Coverage\ServerCommands;

/**
 * Reports which server commands the catalog in src/Commands/Command/ does not
 * yet exercise for a given client.
 *
 * This binary is read-only: it issues COMMAND and nothing else, so it is safe
 * to point at a server the fuzzer itself must not touch.
 */
final class CoverageApplication
{
    /** @var resource */
    private $output;

    /** @var resource */
    private $error;

    /**
     * @param resource|null $output Defaults to STDOUT.
     * @param resource|null $error Defaults to STDERR.
     */
    public function __construct($output = null, $error = null)
    {
        $this->output = $output ?? STDOUT;
        $this->error = $error ?? STDERR;
    }

    private const VALUE_OPTIONS = [
        'client', 'host', 'port', 'username', 'password', 'timeout',
        'read-timeout', 'ignore', 'ignore-file', 'commands-file',
    ];

    private const FLAG_OPTIONS = [
        'help', 'json', 'all', 'no-default-ignores', 'quiet',
    ];

    private const REPEATABLE_OPTIONS = ['ignore'];

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = Options::parse(
                $arguments,
                self::VALUE_OPTIONS,
                self::FLAG_OPTIONS,
                self::REPEATABLE_OPTIONS,
            );

            if ($options->has('help')) {
                $this->write(self::HELP);
                return 0;
            }

            $clientType = $this->clientType($options->string('client', 'redis'));
            $server = $this->serverCommands($options, $clientType);
            $report = (new CoverageAnalyzer())->analyse(
                $server,
                $clientType,
                null,
                $this->ignoreList($options),
            );

            if ($options->has('json')) {
                $this->write(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
            } else {
                $this->write($this->format($report, $options, $this->source($options)));
            }

            return 0;
        } catch (\Throwable $throwable) {
            $this->write('phpredis-coverage: ' . $throwable->getMessage() . "\n", true);
            return 1;
        }
    }

    private function clientType(string $value): ClientType
    {
        $type = ClientType::tryFrom(strtolower(trim($value)));
        if ($type === null) {
            throw new \InvalidArgumentException("Unknown client type: {$value}");
        }

        return $type;
    }

    /**
     * The command table is server-wide, so a plain client is enough even when
     * the analysed class is a cluster one. The extension is matched to the
     * selected client so a Relay-only consumer never needs PhpRedis connected.
     */
    private function serverCommands(Options $options, ClientType $clientType): ServerCommands
    {
        $file = $options->nullableString('commands-file');
        if ($file !== null) {
            return ServerCommands::fromFile($file);
        }

        $username = $options->nullableString('username');
        $password = $options->nullableString('password');
        $host = $options->string('host', '127.0.0.1');
        $port = $options->integer('port', 6379);

        $client = (new ClientFactory())->create(new ClientConfiguration(
            type: match ($clientType) {
                ClientType::Relay, ClientType::RelayCluster => ClientType::Relay,
                default => ClientType::Redis,
            },
            host: $host,
            port: $port,
            seeds: ["{$host}:{$port}"],
            timeout: $options->number('timeout', 1.0),
            readTimeout: $options->number('read-timeout', 1.0),
            auth: $username !== null && $password !== null ? [$username, $password] : $password,
        ));

        return ServerCommands::fromClient($client);
    }

    private function ignoreList(Options $options): IgnoreList
    {
        $file = $options->nullableString('ignore-file');

        $ignore = match (true) {
            $file !== null => IgnoreList::fromFile($file),
            $options->has('no-default-ignores') => new IgnoreList(),
            default => IgnoreList::defaults(),
        };

        return $ignore->with($options->repeated('ignore'));
    }

    private function source(Options $options): string
    {
        $file = $options->nullableString('commands-file');
        if ($file !== null) {
            return $file;
        }

        return $options->string('host', '127.0.0.1') . ':' . $options->integer('port', 6379);
    }

    private function format(CoverageReport $report, Options $options, string $source): string
    {
        if ($options->has('quiet')) {
            return $report->missing === [] ? '' : implode("\n", $report->missing) . "\n";
        }

        $class = $report->clientClass;
        $out = sprintf(
            "Command coverage for %s (--client=%s)\n",
            $class,
            $report->clientType->value,
        );
        $out .= sprintf(
            "Source: %s, %d server commands, %d catalog commands\n",
            $source,
            $report->serverCommands,
            $report->catalogCommands,
        );
        $out .= sprintf(
            "Covered: %d/%d (%.1f%%), %d ignored\n",
            count($report->covered),
            $report->considered(),
            $report->ratio() * 100,
            count($report->ignored),
        );

        $out .= $this->section(
            sprintf('Not covered, %s has a method for it', $class),
            $report->missing,
        );
        $out .= $this->section(
            sprintf('Not covered, %s has no method for it', $class),
            $report->unsupported,
        );
        $out .= $this->section(
            sprintf('Catalog commands the server table and %s both lack', $class),
            $report->unmatched,
        );

        if ($options->has('all')) {
            $out .= $this->section(
                sprintf('Catalog commands the server table lacks, provided by %s', $class),
                $report->clientApi,
            );
            $out .= $this->section('Ignored', array_keys($report->ignored));
            $out .= $this->section('Covered', $report->covered);
        }

        return $out;
    }

    /** @param list<string> $names */
    private function section(string $title, array $names): string
    {
        if ($names === []) {
            return '';
        }

        $out = sprintf("\n%s (%d):\n", $title, count($names));
        foreach ($names as $name) {
            $out .= "  {$name}\n";
        }

        return $out;
    }

    private function write(string $message, bool $error = false): void
    {
        fwrite($error ? $this->error : $this->output, $message);
    }

    private const HELP = <<<'HELP'
phpredis-coverage - report Redis commands the fuzzer catalog does not cover

Usage:
  phpredis-coverage [options]

The server command table comes from COMMAND, uncovered commands are matched
against the ignore patterns, and what remains is split by method_exists() on
the selected client class. Container subcommands ("config|get") are folded into
their parent because the catalog has one class per top-level command.

Only COMMAND is issued, so unlike phpredis-fuzz this binary does not mutate the
target.

Client and connection:
  --client=TYPE              redis, redis-cluster, relay, relay-cluster (default: redis)
                             Selects the class checked with method_exists()
  --host=HOST                Server to read COMMAND from (default: 127.0.0.1)
  --port=PORT                Server port (default: 6379)
  --username=USER            ACL username
  --password=PASS            Password
  --timeout=SECONDS          Connect timeout (default: 1)
  --read-timeout=SECONDS     Read timeout (default: 1)
  --commands-file=FILE       Read command names from FILE instead of connecting;
                             one name per line, "#" comments allowed

Ignore patterns:
  --ignore=PATTERN,...       Add shell globs to ignore; repeatable
  --ignore-file=FILE         Use FILE instead of data/coverage-ignore.txt
  --no-default-ignores       Start from an empty ignore list

Output:
  --json                     Emit the full report as JSON
  --quiet                    Print only the uncovered command names
  --all                      Also list covered, ignored, and client-local commands
  --help                     Show this help without connecting

Examples:
  phpredis-coverage --client redis --port 6379
  phpredis-coverage --client relay --ignore 'ft.*,json.*' --json
HELP;
}
