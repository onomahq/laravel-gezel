<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Onomahq\Gezel\Mcp\GezelWriteTool;

class TestWriteTool extends GezelWriteTool
{
    public function callEnsureWritesEnabled(): void
    {
        $this->ensureWritesEnabled();
    }
}
