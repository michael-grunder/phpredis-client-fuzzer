<?php

namespace Mgrunder\PhpredisCommandFuzzer;


use Redis;
use RedisCluster;

use Relay\Relay;
use Relay\Cluster;

class ScriptLogger {
    private const RELAY_INFO_FIELDS =  [
       'Relay Version',
       'Git SHA',
       'Allocator',
       'Build OS',
       'Architecture',
    ];

    private const RELAY_INI_SETTINGS = [
        'relay.maxmemory',
        'relay.maxmemory_pct',
        'relay.databases',
        'relay.eviction_policy',
        'relay.eviction_sample_keys',
        'relay.initial_readers',
        'relay.invalidation_poll_usec',
        'relay.pconnect_default',
        'relay.max_endpoint_dbs',
    ];

    private static ?ScriptLogger $instance = null;

    private ?string $file_name;

    /**
     * @var resource
     */
    private $fp;

    /**
     * @var array<string, string>
     */
    private $objects = [];

    private int $counter = 1;
    private int $reference_counter = 1;

    private bool $try_catch = false;

    /** @return list<string> */
    private function moduleInfoArray(string $module): array {
        ob_start();
        phpinfo(INFO_MODULES);
        $info = ob_get_clean();
        if ($info === false)
            return [];

        $header = "\n$module\n\n";

        $start = strpos($info, $header);
        if ($start === false)
            return [];

        $start += strlen($header);

        $end = strpos($info, "\n\n", $start);
        if ($end === false)
            return [];

        return explode("\n", substr($info, $start, $end - $start));
    }

    /**
     * @param string[] $fields
     * @return array<string, string>
     */
    private function moduleInfoFields(string $module, array $fields): array {
        $result = [];

        $lines = $this->moduleInfoArray($module);
        $re = "/^([\w\s-]+)\s*=>\s*(.+)$/";

        $fields = array_flip(array_map(fn($v) => strtolower($v), $fields));

        foreach ($lines as $line) {
            if ( ! preg_match($re, $line, $matches))
                continue;

            [$key, $val] = [strtolower(trim($matches[1])), $matches[2]];

            if (isset($fields[$key]))
                $result[$key] = $val;
        }

        return $result;
    }

    private function relayPreamble(): void {
        $info = $this->moduleInfoFields('relay', self::RELAY_INFO_FIELDS);

        foreach ($info as $key => $val)
            fprintf($this->fp, "// %s = %s\n", $key, $val);

        foreach (self::RELAY_INI_SETTINGS as $setting)
            fprintf($this->fp, "// %s = %s\n", $setting, ini_get($setting));
    }

    /**
     * @param array<string, string|int|bool|float> $details
     */
    private function preamble(array $details): void {
        fprintf($this->fp, "<?php\n\n");
        fprintf($this->fp, "// Date: %s\n", date('Y-m-d H:i:s'));

        if (class_exists('\Relay\Relay'))
            $this->relayPreamble();

        $output = [];
        foreach ($details as $key => $val) {
            $output[] = sprintf("// %s: %s", $key, $val);
        }

        if ( ! fprintf($this->fp, "%s\n\n", implode("\n", $output))) {
            throw new \Exception("Failed to write to file: $this->file_name");
        }

        $port = $details['port'] ?? 6379;

        fprintf($this->fp, "\$opt = getopt('dw', ['host:', 'port:', 'debug', 'warnings']);\n");
        fprintf($this->fp, "\$host = \$opt['host'] ?? 'localhost';\n");
        fprintf($this->fp, "\$port = \$opt['port'] ?? %d;\n", $port);
        fprintf($this->fp, "\$debug = isset(\$opt['d']) || isset(\$opt['debug']);\n");
        fprintf($this->fp, "\$warnings = isset(\$opt['w']) || isset(\$opt['warnings']);\n\n");

        fprintf($this->fp, "if ( ! \$warnings)\n");
        fprintf($this->fp, "    error_reporting(error_reporting() & ~E_WARNING);\n\n");

        fflush($this->fp);
    }

    private function varExport(mixed $v): string {
        if ($v === [])
            return '[]';

        return var_export($v, true);
    }

    private function setClientOption(string $name, string $opt,
                                     mixed $value): void
    {
        fprintf($this->fp, "%s->setOption(%s, %s);\n", $name, $opt,
                $this->varExport($value));
    }

    private function initObject(Redis|RedisCluster|Relay|Cluster $client): void {
        $hash = spl_object_hash($client);

        $class        = get_class($client);
        $class_parts  = explode('\\', strtolower(get_class($client)));
        $name         = sprintf("\$%s%d", end($class_parts), $this->counter++);

        if (method_exists($client, 'getTimeout'))
            $timeout = $client->getTimeout();
        else
            $timeout = 0;

        $read_timeout = $client->getOption(Redis::OPT_READ_TIMEOUT);
        $timeout = is_numeric($timeout) ? (float)$timeout : 0.0;
        $read_timeout = is_numeric($read_timeout) ? (float)$read_timeout : 0.0;

        if ($client instanceof Redis || $client instanceOf Relay) {
            if (($auth = $client->getAuth())) {
                $context = ['auth' => $auth];
            } else {
                $context = [];
            }

            fprintf($this->fp, "%s = new %s;\n", $name, $class);
            fprintf($this->fp, "%s->connect(%s, \$port, %f, null, 0, %f, %s);\n", $name,
                    $this->varExport($client->getHost()), $timeout,
                    $read_timeout, $this->varExport($context));
        } else {
            /** @var RedisCluster|Cluster $client */
            $seeds = [];
            foreach ($client->_masters() as $seed) {
                if (is_array($seed)
                    && is_string($seed[0] ?? null)
                    && is_int($seed[1] ?? null)) {
                    $seeds[] = sprintf("%s:%d", $seed[0], $seed[1]);
                }
            }
            if ($seeds === []) {
                $seeds = ["127.0.0.1:6379"];
            }
            try {
                $auth = method_exists($client, 'getAuth') ? $client->getAuth() : null;
            } catch (\Exception) {
                $auth = null;
            }

            fprintf(
                $this->fp,
                "%s = new %s(null, %s, %f, %f, false, %s);\n",
                $name,
                get_class($client),
                $this->varExport($seeds),
                $timeout,
                $read_timeout,
                $this->varExport($auth),
            );
        }

        $ser = $client->getOption(Redis::OPT_SERIALIZER);
        $cmp = $client->getOption(Redis::OPT_COMPRESSION);
        $pfx = $client->getOption(Redis::OPT_PREFIX);

        assert(is_int($ser) && is_int($cmp));

        if ($ser != Redis::SERIALIZER_NONE)
            $this->setClientOption($name, 'Redis::OPT_SERIALIZER', $ser);
        if ($cmp != Redis::COMPRESSION_NONE)
            $this->setClientOption($name, 'Redis::OPT_COMPRESSION', $cmp);
        if ($client->getOption(Redis::OPT_PREFIX))
            $this->setClientOption($name, 'Redis::OPT_PREFIX', $pfx);

        if (($client instanceOf Relay) || ($client instanceOf Cluster)) {
            $compat = $client->getOption(Relay::OPT_PHPREDIS_COMPATIBILITY);
            if ( ! $compat)
                $this->setClientOption($name, 'Relay\Relay::OPT_PHPREDIS_COMPATIBILITY', false);
        }

        fflush($this->fp);

        $this->objects[$hash] = $name;
    }

    /**
     * @param array<string, string|int|bool|float> $details
     * @param array<Redis|RedisCluster|Relay|Cluster> $clients
     */
    protected function __construct(?string $filename, array $details = [],
                                   array $clients = [], bool $try_catch = false)
    {
        $this->file_name = $filename;
        $this->try_catch = $try_catch;

        if ($this->file_name !== null) {
            $fp = fopen($this->file_name, 'w');
            if ( ! $fp)
                throw new \Exception("Failed to open file: $filename");
            $this->fp = $fp;
        }

        $this->preamble($details);
        $this->initClients($clients);
    }

    /**
     * @param array<string, string|int|bool|float> $details
     * @param array<Redis|RedisCluster|Relay|Cluster> $clients
     */
    public static function init(string $file_name, array $details = [],
                                array $clients = [], bool
                                $try_catch = false): void
    {
        if (self::$instance !== null) {
            throw new \Exception("ScriptLogger already initialized");
        }

        self::$instance = new ScriptLogger($file_name, $details, $clients,
                                          $try_catch);
    }

    public static function finish(): void {
        if (self::$instance === null) {
            return;
        }

        if (is_resource(self::$instance->fp)) {
            fclose(self::$instance->fp);
        }
        self::$instance = null;
    }

    /** @param array<Redis|RedisCluster|Relay|Cluster> $clients */
    private function initClients(array $clients): void {
        foreach ($clients as $client) {
            $hash = spl_object_hash($client);
            if (isset($this->objects[$hash]))
                continue;

            $this->initObject($client);
        }
    }

    /**
     * @param array<mixed> $args
     */
    public function logCommand(Redis|RedisCluster|Relay|Cluster $client,
                               string $cmd, array $args): void
    {
        $this->writeCommand($client, $cmd, $args);
    }

    /**
     * @param array<mixed> $args
     */
    private function writeCommand(Redis|RedisCluster|Relay|Cluster $client,
                                  string $cmd, array $args,
                                  ?int $reference_index = null): void
    {
        $hash = spl_object_hash($client);
        if ( ! isset($this->objects[$hash]))
            $this->initObject($client);

        $reference = null;
        if ($reference_index !== null) {
            if (!array_key_exists($reference_index, $args)) {
                throw new \OutOfBoundsException('Reference argument is missing');
            }
            $reference = sprintf('$phpredisFuzzReference%d', $this->reference_counter++);
            fprintf($this->fp, "%s = %s;\n", $reference,
                    $this->varExport($args[$reference_index]));
        }

        $code_args = [];
        foreach ($args as $index => $arg) {
            if ($index === $reference_index) {
                $code_args[] = $reference;
            } else if ($arg instanceOf ScriptArg) {
                $code_args[] = $arg->code();
            } else {
                $code_args[] = $this->varExport($arg);
            }
        }

        if ($this->try_catch) {
            fprintf($this->fp, "try {\n");
            fprintf($this->fp, "    ");
        }

        fprintf($this->fp, "%s->%s(%s);\n", $this->objects[$hash], $cmd,
                implode(", ", $code_args));

        if ($this->try_catch) {
            fprintf($this->fp, "} catch (Exception \$e) {\n");
            fprintf($this->fp, "    if (\$debug) printf(\"[%%s:%%d] Exception: %%s\\n\", '$cmd', __LINE__, \$e->getMessage());\n");
            fprintf($this->fp, "}\n");
        }

        fflush($this->fp);
    }

    public static function comment(string $message): void {
        if (self::$instance === null)
            return;

        fprintf(self::$instance->fp, "// %s\n", $message);
        fflush(self::$instance->fp);
    }

    public static function shellExec(string $cmd): void{
        if (self::$instance === null)
            return;

        fprintf(self::$instance->fp, "shell_exec('%s');\n", $cmd);
        fflush(self::$instance->fp);
    }

    /**
     * @param array<mixed> $args
     */
    public static function log(Redis|RedisCluster|Relay|Cluster $client,
                               string $cmd, array $args): void
    {
        if (self::$instance === null)
            return;

        self::$instance->logCommand($client, $cmd, $args);
    }

    /**
     * Log a command argument through a generated variable so the reproduction
     * remains valid when that argument must be passed by reference.
     *
     * @param array<mixed> $args
     */
    public static function logReference(Redis|RedisCluster|Relay|Cluster $client,
                                        string $cmd, array $args,
                                        int $reference_index): void
    {
        if (self::$instance === null)
            return;

        self::$instance->writeCommand($client, $cmd, $args, $reference_index);
    }
}
