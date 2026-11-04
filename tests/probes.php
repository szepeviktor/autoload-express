<?php

declare(strict_types=1);

namespace RoboViktor\AutoloadClassmap;

// Count expensive production operations without adding instrumentation to it.
function hash_file(string $algorithm, string $filename, bool $binary = false): string|false
{
    $GLOBALS['classmap_test_hashes']++;
    return \hash_file($algorithm, $filename, $binary);
}

function file_put_contents(string $filename, mixed $data, int $flags = 0): int|false
{
    $GLOBALS['classmap_test_writes']++;
    return \file_put_contents($filename, $data, $flags);
}
