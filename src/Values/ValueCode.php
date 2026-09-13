<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\Values;

/** Export supported values without invoking serialization or conversion callbacks. */
final class ValueCode
{
    /** @var array<string, string> */
    private array $references = [];
    /** @var array<int, string> */
    private array $objects = [];
    /** @var list<string> */
    private array $statements = [];
    private int $next = 0;

    public static function requiresExport(mixed $value): bool
    {
        if ($value instanceof StringValue || $value instanceof SerializedValue || $value instanceof WakeupValue) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (\ReflectionReference::fromArrayElement($value, $key) !== null || self::requiresExport($item)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function export(mixed $value): string
    {
        $exporter = new self();
        $expression = $exporter->expression($value);
        if ($exporter->statements === []) {
            return $expression;
        }

        return '(static function () { ' . implode(' ', $exporter->statements) . ' return ' . $expression . '; })()';
    }

    private function expression(mixed $value): string
    {
        if (is_string($value)) {
            return preg_match('/[^\x20-\x7e]/', $value)
                ? "base64_decode('" . base64_encode($value) . "')" : var_export($value, true);
        }
        if (is_array($value)) {
            $entries = [];
            foreach ($value as $key => $item) {
                $reference = \ReflectionReference::fromArrayElement($value, $key);
                if ($reference !== null) {
                    $id = bin2hex($reference->getId());
                    if (!isset($this->references[$id])) {
                        $variable = '$value' . $this->next++;
                        $this->references[$id] = $variable;
                        $this->statements[] = $variable . ' = null;';
                        $expression = $this->expression($item);
                        $this->statements[] = $variable . ' = ' . $expression . ';';
                    }
                    $expression = '&' . $this->references[$id];
                } else {
                    $expression = $this->expression($item);
                }
                $entries[] = var_export($key, true) . ' => ' . $expression;
            }

            return '[' . implode(', ', $entries) . ']';
        }
        if ($value instanceof StringValue || $value instanceof SerializedValue || $value instanceof WakeupValue) {
            $id = spl_object_id($value);
            if (!isset($this->objects[$id])) {
                $variable = '$value' . $this->next++;
                $this->objects[$id] = $variable;
                $option = $value instanceof SerializedValue ? $value->behavior : $value->throws;
                $this->statements[] = $variable . ' = new \\' . $value::class . '('
                    . $this->expression($value->payload) . ', ' . var_export($option, true) . ');';
            }

            return $this->objects[$id];
        }

        return var_export($value, true);
    }

    /** @return list<class-string> */
    public static function fixtureClasses(): array
    {
        return [StringValue::class, SerializedValue::class, WakeupValue::class, CallbackContext::class, MutatingValue::class];
    }
}
