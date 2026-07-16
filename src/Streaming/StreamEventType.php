<?php

namespace Onomahq\Gezel\Streaming;

enum StreamEventType: string
{
    case Token = 'token';
    case ToolCall = 'tool_call';
    case ToolResult = 'tool_result';
    case Done = 'done';
    case Error = 'error';
}
