<?php

namespace Onomahq\Gezel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class GezelClient
{
    /**
     * The visible conversation: user prompts and the assistant's final
     * replies. Tool-call carriers (empty assistant turns) and raw tool
     * results are dropped so history reads like the live chat.
     *
     * @return list<array{role: string, content: string}>
     */
    public function fetchHistory(string $gezelId, string $chatId): array
    {
        $messages = $this->request($gezelId)
            ->get("/v1/sessions/{$chatId}/messages", ['agent_id' => 'default'])
            ->throw()
            ->json('messages', []);

        return collect($messages)
            ->filter(fn (array $message): bool => in_array($message['role'] ?? null, ['user', 'assistant'], true)
                && trim((string) ($message['content'] ?? '')) !== '')
            ->map(fn (array $message): array => ['role' => $message['role'], 'content' => $message['content']])
            ->values()
            ->all();
    }

    /**
     * Point Gezel's proactive-delivery target at this chat. Best-effort: this
     * must never fail on an unreachable middleware.
     */
    public function activateSession(string $gezelId, string $chatId): void
    {
        try {
            $this->request($gezelId)->post("/v1/sessions/{$chatId}/activate");
        } catch (Throwable) {
            // Best-effort: the delivery target self-corrects on the next turn.
        }
    }

    /**
     * Delete the session transcript in Gezel. Best-effort: the app-side chat
     * row is the source of truth for the list, and an orphaned transcript is
     * unreachable once the row is gone.
     */
    public function deleteSession(string $gezelId, string $chatId): void
    {
        try {
            $this->request($gezelId)->delete("/v1/sessions/{$chatId}?agent_id=default");
        } catch (Throwable) {
            // Best-effort: nothing to roll back locally.
        }
    }

    /**
     * @return list<array{model: string, display_name: string, owned_by: string, is_premium: bool, is_european: bool, can_access: bool}>
     */
    public function models(string $gezelId): array
    {
        $raw = $this->request($gezelId)->get('/v1/models')->throw()->json('data', []);

        return array_map(fn (array $m): array => [
            'model' => $m['id'] ?? $m['model'] ?? '',
            'display_name' => $m['display_name'] ?? $m['id'] ?? '',
            'owned_by' => $m['owned_by'] ?? '',
            'is_premium' => $m['is_premium'] ?? false,
            'is_european' => $m['is_european'] ?? false,
            'can_access' => $m['can_access'] ?? true,
        ], is_array($raw) ? $raw : []);
    }

    /**
     * The personas available in this container, for the picker. The
     * container only mounts its account's set, so the list is scoped.
     *
     * @return array{default_persona_id: string, data: list<array<string, mixed>>}
     */
    public function personas(string $gezelId): array
    {
        $response = $this->request($gezelId)->get('/v1/personas')->throw();
        $data = $response->json('data', []);

        return [
            'default_persona_id' => (string) $response->json('default_persona_id', ''),
            'data' => array_map(fn (array $p): array => [
                'id' => $p['id'] ?? '',
                'name' => $p['name'] ?? ($p['id'] ?? ''),
                'description' => $p['description'] ?? null,
                'is_default' => (bool) ($p['is_default'] ?? false),
            ], is_array($data) ? $data : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function syncProfile(string $gezelId, array $profile): void
    {
        $this->request($gezelId)->put('/v1/me/profile', $profile)->throw();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $gezelId, string $path, array $query = []): Response
    {
        return $this->request($gezelId)->get($path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $gezelId, string $path, array $data = []): Response
    {
        return $this->request($gezelId)->post($path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $gezelId, string $path, array $data = []): Response
    {
        return $this->request($gezelId)->put($path, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function delete(string $gezelId, string $path, array $data = []): Response
    {
        return $this->request($gezelId)->delete($path, $data);
    }

    protected function request(string $gezelId): PendingRequest
    {
        return Http::baseUrl($this->proxyBaseUrl($gezelId))
            ->timeout(config('gezel.timeout'))
            ->withToken(config('gezel.middleware.app_token'))
            ->withHeaders($this->traceHeaders());
    }

    protected function proxyBaseUrl(string $gezelId): string
    {
        return rtrim((string) config('gezel.middleware.url'), '/')."/v1/proxy/{$gezelId}";
    }

    /**
     * Cross-system tracing: forward the inbound request_id to Gezel so a
     * single trace can be followed across the consumer app → middleware →
     * Gezel. Falls back to a fresh uuid when there's no inbound request
     * (e.g. a queued job).
     *
     * @return array<string, string>
     */
    protected function traceHeaders(): array
    {
        $requestId = null;

        if (app()->bound('request')) {
            $requestId = (string) request()->attributes->get('request_id', '');
        }

        if ($requestId === null || $requestId === '') {
            $requestId = (string) Str::uuid();
        }

        return ['X-Request-Id' => $requestId];
    }
}
