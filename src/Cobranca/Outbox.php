<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Support\DB;

/**
 * Comandos para o serviço de Cobrança. Deve ser chamado DENTRO da transação que
 * originou o fato: ou os dois ficam gravados, ou nenhum. Quem entrega é o RelayOutbox.
 */
final class Outbox
{
    public const MATRICULA_CRIADA = 'MatriculaCriada';
    public const MATRICULA_CANCELADA = 'MatriculaCancelada';

    public static function registrar(string $tipo, string $chave, array $payload): int
    {
        return DB::insert('cobranca_outbox', [
            'tipo' => $tipo,
            'chave' => $chave,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
