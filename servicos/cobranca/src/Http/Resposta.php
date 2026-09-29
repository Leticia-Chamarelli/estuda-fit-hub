<?php

declare(strict_types=1);

namespace Cobranca\Http;

final class Resposta
{
    public function __construct(public readonly int $status, public readonly array $dados = [])
    {
    }

    public function enviar(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
