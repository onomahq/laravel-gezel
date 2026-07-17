<?php

namespace Onomahq\Gezel\Mcp;

use Laravel\Mcp\Server;

/**
 * Host apps extend this to define their own MCP server: its name,
 * instructions, and $tools/$resources/$prompts list, exactly like extending
 * {@see Server} directly today. The only difference is registration: set
 * config('gezel.mcp.server') to the host's concrete class-string and the
 * package wires the route itself (see GezelServiceProvider::registerMcpServer())
 * instead of the host calling Mcp::web() from its own routes file.
 */
abstract class GezelMcpServer extends Server {}
