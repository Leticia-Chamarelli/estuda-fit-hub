<?php

declare(strict_types=1);

namespace Cobranca\Http;

use RuntimeException;

final class Erro extends RuntimeException
{
    public function __construct(public readonly int $status, string $mensagem)
    {
        parent::__construct($mensagem);
    }
}
