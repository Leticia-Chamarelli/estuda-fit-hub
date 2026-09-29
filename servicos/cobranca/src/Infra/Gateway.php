<?php

declare(strict_types=1);

namespace Cobranca\Infra;

/**
 * Simula o gateway de pagamento: registrar a cobrança devolve a referência usada no webhook.
 * A chamada é idempotente pelo código da fatura (o gateway real aceita chave de idempotência).
 */
class Gateway
{
    public function registrarCobranca(string $codigoFatura, string $valor, string $vencimento): string
    {
        $latencia = (int) (getenv('GATEWAY_LATENCIA_MS') ?: 0);
        if ($latencia > 0) {
            usleep($latencia * 1000);
        }

        return 'gwc_' . substr(sha1($codigoFatura), 0, 16);
    }
}
