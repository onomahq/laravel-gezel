<?php

namespace Onomahq\Gezel;

final class ContainerInfo
{
    public function __construct(
        public readonly string $containerId,
        public readonly string $status,
    ) {}
}
