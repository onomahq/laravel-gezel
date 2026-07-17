<?php

namespace Onomahq\Gezel\Mcp;

use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Tool;
use Onomahq\Gezel\Contracts\WritesGate;
use Onomahq\Gezel\Support\Owner;

/**
 * Generalizes Stagent's write-tool pattern: shouldRegister() hides the tool
 * from tools/list when the bound {@see WritesGate} says writes are disabled
 * for the authenticated owner, and ensureWritesEnabled() re-checks the same
 * gate inside handle(). List-time filtering is never the security boundary,
 * since a client can call tools/call directly without ever listing first.
 */
abstract class GezelWriteTool extends Tool
{
    public function shouldRegister(): bool
    {
        $owner = $this->currentOwner();

        return $owner !== null && app(WritesGate::class)->writesEnabled($owner);
    }

    protected function ensureWritesEnabled(): void
    {
        $owner = $this->currentOwner();

        abort_unless(
            $owner !== null && app(WritesGate::class)->writesEnabled($owner),
            403,
            'Making changes is disabled for this connection.',
        );
    }

    private function currentOwner(): ?Model
    {
        $owner = auth()->user();
        $ownerModel = Owner::model();

        return $owner instanceof $ownerModel ? $owner : null;
    }
}
