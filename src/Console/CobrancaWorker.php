<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Cobranca\CobrancaIndisponivel;
use EstudaFitHub\Cobranca\ConsumidorEventos;
use EstudaFitHub\Cobranca\RelayOutbox;
use EstudaFitHub\Cobranca\Servico;
use EstudaFitHub\Cobranca\WebhookInbox;
use Throwable;

/**
 * Worker da integração com Cobrança. A cada ciclo:
 *  1. processa a inbox do webhook (legado ou encaminha ao serviço);
 *  2. entrega a outbox de comandos ao serviço;
 *  3. consome o feed de eventos do serviço.
 * Serviço fora do ar não derruba o worker: o ciclo registra e tenta de novo no próximo.
 * Uso: php bin/console cobranca:worker [--uma-vez] [--intervalo=2]
 */
final class CobrancaWorker implements Comando
{
    public function executar(array $args): int
    {
        $umaVez = in_array('--uma-vez', $args, true);
        $intervalo = 2;
        foreach ($args as $arg) {
            if (preg_match('/^--intervalo=(\d+)$/', $arg, $m)) {
                $intervalo = max(1, (int) $m[1]);
            }
        }

        $cliente = Servico::cliente();
        $inbox = new WebhookInbox($cliente);
        $relay = new RelayOutbox($cliente);
        $consumidor = new ConsumidorEventos($cliente);

        do {
            $this->etapa('inbox', fn () => $inbox->processar());
            $this->etapa('outbox', fn () => $relay->publicar());
            $this->etapa('eventos', fn () => $consumidor->consumir());
            if (!$umaVez) {
                sleep($intervalo);
            }
        } while (!$umaVez);

        return 0;
    }

    private function etapa(string $nome, callable $fn): void
    {
        try {
            $resultado = $fn();
            if (array_sum(array_map('intval', array_diff_key($resultado, ['cursor' => 0, 'pendentes' => 0]))) > 0) {
                echo date('H:i:s') . " {$nome} " . json_encode($resultado) . "\n";
            }
        } catch (CobrancaIndisponivel $e) {
            echo date('H:i:s') . " {$nome}: serviço de Cobrança indisponível ({$e->getMessage()}), tentando no próximo ciclo\n";
        } catch (Throwable $e) {
            fwrite(STDERR, date('H:i:s') . " {$nome}: " . $e::class . ': ' . $e->getMessage() . "\n");
        }
    }
}
