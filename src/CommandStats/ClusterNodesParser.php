<?php

declare(strict_types=1);

namespace Mgrunder\PhpredisCommandFuzzer\CommandStats;

final class ClusterNodesParser
{
    /** @return list<Node> */
    public function parse(string $reply): array
    {
        $nodes = [];
        foreach (preg_split('/\r?\n/', trim($reply)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if ($fields === false || count($fields) < 8) {
                continue;
            }

            $flags = explode(',', strtolower($fields[2]));
            if ($fields[7] !== 'connected' || array_intersect($flags, ['fail', 'fail?', 'handshake', 'noaddr']) !== []) {
                continue;
            }

            $role = match (true) {
                in_array('master', $flags, true), in_array('primary', $flags, true) => NodeRole::Primary,
                in_array('slave', $flags, true), in_array('replica', $flags, true) => NodeRole::Replica,
                default => null,
            };
            if ($role === null) {
                continue;
            }

            try {
                $node = Node::fromAddress($fields[1], $role);
                $nodes[$node->key()] = $node;
            } catch (\InvalidArgumentException) {
                // A node with no usable advertised address cannot be sampled directly.
            }
        }

        return array_values($nodes);
    }
}
