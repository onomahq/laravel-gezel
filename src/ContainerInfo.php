<?php

namespace Onomahq\Gezel;

class ContainerInfo
{
    public function __construct(
        public readonly string $containerId,
        public readonly string $status,
    ) {}
}
