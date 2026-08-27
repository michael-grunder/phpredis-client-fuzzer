<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer;

final class Utilities
{
    public static function panicAbort(string $message): never
    {
        throw new \RuntimeException($message);
    }

    public static function randomChance(float $chance): bool
    {
        if ($chance <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < min(1.0, $chance);
    }

    public static function bytesToSize(int $bytes, int $precision = 2): string
    {
        if ($bytes === 0) {
            return '0b';
        }

        $units = ['b', 'kb', 'mb', 'gb', 'tb', 'pb'];
        $index = min((int) floor(log(abs($bytes), 1024)), count($units) - 1);
        $size = $index > 0 ? $bytes / (1024 ** $index) : $bytes;

        return sprintf("%.{$precision}f%s", $size, $units[$index]);
    }

    public static function toSerializerIdent(int $value): string
    {
        return self::constantName($value, [
            'Redis::SERIALIZER_PHP',
            'Redis::SERIALIZER_IGBINARY',
            'Redis::SERIALIZER_MSGPACK',
            'Redis::SERIALIZER_JSON',
        ], 'Redis::SERIALIZER_NONE');
    }

    public static function toCompressionIdent(int $value): string
    {
        return self::constantName($value, [
            'Redis::COMPRESSION_LZF',
            'Redis::COMPRESSION_ZSTD',
            'Redis::COMPRESSION_LZ4',
        ], 'Redis::COMPRESSION_NONE');
    }

    /** @param list<string> $constants */
    private static function constantName(int $value, array $constants, string $default): string
    {
        foreach ($constants as $constant) {
            if (defined($constant) && constant($constant) === $value) {
                return $constant;
            }
        }

        return $default;
    }
}
