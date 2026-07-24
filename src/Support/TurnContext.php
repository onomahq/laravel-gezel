<?php

namespace Onomahq\Gezel\Support;

/**
 * Collects the lines of a turn-context block and the questions those lines
 * resolve, so a composer's sections never have to agree on a calling
 * convention.
 *
 * The point is the coupling: a line and the question it answers are added in
 * one call, so a section that gets gated out on empty data cannot leave behind
 * a `resolved` entry claiming the agent already knows something it was never
 * told. Blank lines are skipped for the same reason — every section stays
 * "gated on what exists" without each one restating the check.
 */
final class TurnContext
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $resolved = [];

    public function add(string $line, ?string $resolves = null): static
    {
        if (trim($line) === '') {
            return $this;
        }

        $this->lines[] = $line;

        if ($resolves !== null && $resolves !== '' && ! in_array($resolves, $this->resolved, true)) {
            $this->resolved[] = $resolves;
        }

        return $this;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<string>
     */
    public function resolved(): array
    {
        return $this->resolved;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
