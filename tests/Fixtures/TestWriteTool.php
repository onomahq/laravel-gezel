<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Laravel\Mcp\Response;
use Onomahq\Gezel\Mcp\GezelWriteTool;

class TestWriteTool extends GezelWriteTool
{
    protected string $name = 'test-write-tool';

    protected string $description = 'A write tool used only in tests.';

    public function handle(): Response
    {
        if ($response = $this->writesDisabledResponse()) {
            return $response;
        }

        return Response::text('wrote it');
    }
}
