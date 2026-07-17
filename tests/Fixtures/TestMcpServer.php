<?php

namespace Onomahq\Gezel\Tests\Fixtures;

use Onomahq\Gezel\Mcp\GezelMcpServer;

class TestMcpServer extends GezelMcpServer
{
    protected string $name = 'Test Server';

    protected string $version = '1.0.0';

    protected array $tools = [];
}
