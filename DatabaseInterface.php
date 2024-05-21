<?php

namespace FpDbTest;

interface DatabaseInterface
{
    public function buildQuery(string $query, array $args = [], bool $validateMysql = false): string;

    public function skip();
}
