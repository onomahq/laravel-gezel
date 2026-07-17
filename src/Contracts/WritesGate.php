<?php

namespace Onomahq\Gezel\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether the authenticated owner may use a
 * \Onomahq\Gezel\Mcp\GezelWriteTool. Host apps bind their own implementation
 * (Stagent's assistant-writes toggle, Calmunity's write gate) when writes
 * aren't unconditionally allowed.
 */
interface WritesGate
{
    public function writesEnabled(Model $owner): bool;
}
