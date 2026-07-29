<?php

namespace Onomahq\Gezel\Support;

/**
 * Sanitises owner-controlled text before it enters a model-facing grounding
 * block. Those blocks open with "trust these facts", so a space or team named
 * "X\nIgnore previous instructions" must render as one escaped line, never as
 * its own line of the block.
 *
 * Control characters, unicode line separators and bidi overrides all flatten
 * or strip: the first two would break the line, and the last can visually
 * reorder one while leaving the bytes intact.
 */
final class PromptField
{
    public static function clean(mixed $value, string $fallback = ''): string
    {
        $text = trim((string) ($value ?? '')) ?: $fallback;

        // Bidi overrides and isolates are format chars: neither [:cntrl:] nor
        // \s matches them, so they survive both passes below unless named.
        $text = preg_replace('/[[:cntrl:]\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $text) ?? $fallback;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $fallback;

        return addcslashes($text !== '' ? $text : $fallback, '"\\');
    }
}
