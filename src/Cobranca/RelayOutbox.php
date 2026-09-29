<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Support\DB;

/**
 * Entrega os comandos da outbox ao serviço, em ordem de gravação.
 *
 * Falha transitória interrompe o lote (preserva a ordem: um cancelamento não passa na frente
 * da criação). Rejeição 4xx vira `rejeitado` e não trava a fila; aparece no alerta.
 */
final class RelayOutbox
{
    public function __construct(private readonly CobrancaClient $cliente)
    {
    }

    /** @return array{enviados: int, rejeitados: int, pendentes: int} */
    public function publicar(int $limite = 100): array
    {
        $enviados = 0;
        $rejeitados = 0;
        $linhas = DB::select(
            "SELECT id, tipo, payload FROM cobranca_outbox WHERE situacao = 'pendente' ORDER BY id LIMIT " . max(1, $limite),
        );

        foreach ($linhas as $linha) {
            try {
                $this->cliente->enviarComando($linha['tipo'], json_decode($linha['payload'], true));
                DB::execute(
                    "UPDATE cobranca_outbox SET situacao = 'enviado', enviado_em = NOW(), tentativas = tentativas + 1 WHERE id = ?",
                    [$linha['id']],
                );
                $enviados++;
            } catch (ComandoRejeitado $e) {
                DB::execute(
                    "UPDATE cobranca_outbox SET situacao = 'rejeitado', tentativas = tentativas + 1, ultimo_erro = ? WHERE id = ?",
                    [substr($e->getMessage(), 0, 255), $linha['id']],
                );
                $rejeitados++;
            } catch (CobrancaIndisponivel $e) {
                DB::execute(
                    'UPDATE cobranca_outbox SET tentativas = tentativas + 1, ultimo_erro = ? WHERE id = ?',
                    [substr($e->getMessage(), 0, 255), $linha['id']],
                );
                break;
            }
        }

        $pendentes = (int) DB::selectOne("SELECT COUNT(*) AS c FROM cobranca_outbox WHERE situacao = 'pendente'")['c'];

        return ['enviados' => $enviados, 'rejeitados' => $rejeitados, 'pendentes' => $pendentes];
    }
}
