<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

/**
 * Ponto único de obtenção do cliente do serviço. Os testes trocam por um dublê.
 */
final class Servico
{
    private static ?CobrancaClient $cliente = null;

    public static function cliente(): CobrancaClient
    {
        return self::$cliente ??= HttpCobrancaClient::doAmbiente();
    }

    public static function usar(?CobrancaClient $cliente): void
    {
        self::$cliente = $cliente;
    }
}
