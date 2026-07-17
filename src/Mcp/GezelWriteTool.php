<?php

namespace Onomahq\Gezel\Mcp;

use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Onomahq\Gezel\Contracts\WritesGate;
use Onomahq\Gezel\Support\Owner;

/**
 * Generalizes Stagent's write-tool pattern: shouldRegister() hides the tool
 * from tools/list when the bound {@see WritesGate} says writes are disabled
 * for the authenticated owner, and writesDisabledResponse() re-checks the
 * same gate inside handle(). List-time filtering is never the security
 * boundary on its own: a host's own tools/call handler could bypass it (the
 * stock CallTool method applies the same shouldRegister() filter to
 * tools/call as it does to tools/list, but a host overriding CallTool, as
 * Stagent does, is not guaranteed to).
 *
 * writesDisabledResponse() returns an MCP tool-error Response rather than
 * throwing an HTTP exception: an HttpException thrown from handle() bubbles
 * up to Server::handle()'s generic catch and becomes an opaque JSON-RPC
 * protocol error ("Something went wrong..."), discarding the actual message.
 * Response::error() instead produces a normal tools/call result with
 * isError: true, so the message reaches the agent as real tool output it can
 * relay to the user.
 */
abstract class GezelWriteTool extends Tool
{
    public function shouldRegister(): bool
    {
        return $this->writesAllowed();
    }

    /**
     * Call at the top of handle() and return the result if non-null:
     *
     *     if ($response = $this->writesDisabledResponse()) {
     *         return $response;
     *     }
     */
    protected function writesDisabledResponse(): ?Response
    {
        return $this->writesAllowed() ? null : Response::error('Making changes is disabled for this connection.');
    }

    private function writesAllowed(): bool
    {
        $owner = $this->currentOwner();

        return $owner !== null && app(WritesGate::class)->writesEnabled($owner);
    }

    private function currentOwner(): ?Model
    {
        $owner = auth()->user();
        $ownerModel = Owner::model();

        return $owner instanceof $ownerModel ? $owner : null;
    }
}
