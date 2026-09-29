<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Support\DB;
use PDOException;

/**
 * Porta de entrada do webhook do gateway durante a transição.
 *
 * `receber` é tudo o que roda na requisição do gateway: um INSERT com chave única
 * (event_id ou hash do payload). Sem transação longa, sem SMS, sem rede. O worker da
 * catraca não disputa mais capacidade com gateway lento.
 *
 * `processar` roda no worker e decide o destino de cada webhook:
 *  - fatura legada de unidade no legado -> PagamentoLegado, na mesma transação que marca a inbox;
 *  - fatura legada de unidade migrada, ou referência que o legado não conhece -> serviço.
 * Serviço fora do ar: o item fica pendente e é tentado de novo, sem perder nada.
 */
final class WebhookInbox
{
    public function __construct(
        private readonly CobrancaClient $cliente,
        private readonly PagamentoLegado $legado = new PagamentoLegado(),
    ) {
    }

    /** @return bool true se é novo, false se é duplicata */
    public static function receber(array $payload): bool
    {
        $chave = isset($payload['event_id']) && $payload['event_id'] !== ''
            ? 'evt:' . $payload['event_id']
            : 'hash:' . sha1(self::canonico($payload));

        try {
            DB::insert('webhook_inbox', [
                'chave' => substr($chave, 0, 80),
                'referencia' => (string) $payload['reference'],
                'status_gateway' => (string) $payload['status'],
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);

            return true;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /** @return array{legado: int, servico: int, pendentes: int} */
    public function processar(int $limite = 200): array
    {
        $contagem = ['legado' => 0, 'servico' => 0, 'pendentes' => 0];
        $itens = DB::select('SELECT * FROM webhook_inbox WHERE processado_em IS NULL ORDER BY id LIMIT ' . max(1, $limite));

        foreach ($itens as $item) {
            $payload = json_decode($item['payload'], true);
            /** @var ?Fatura $fatura */
            $fatura = Fatura::where('gateway_ref', $item['referencia'])->first();
            $unidadeId = $fatura !== null ? (int) ($fatura->aluno()?->unidade_id ?? 0) : 0;

            if ($fatura !== null && !Roteamento::migrada($unidadeId)) {
                $notificar = DB::transaction(function () use ($fatura, $payload, $item): callable {
                    $depois = $this->legado->aplicar($fatura, $payload);
                    $this->marcar((int) $item['id'], 'legado');

                    return $depois;
                });
                $notificar();
                $contagem['legado']++;
                continue;
            }

            try {
                $this->cliente->encaminharWebhook($payload);
                $this->marcar((int) $item['id'], 'servico');
                $contagem['servico']++;
            } catch (ComandoRejeitado $e) {
                $this->marcar((int) $item['id'], 'descartado', $e->getMessage());
            } catch (CobrancaIndisponivel $e) {
                DB::execute(
                    'UPDATE webhook_inbox SET tentativas = tentativas + 1, ultimo_erro = ? WHERE id = ?',
                    [substr($e->getMessage(), 0, 255), $item['id']],
                );
                $contagem['pendentes']++;
            }
        }

        return $contagem;
    }

    private function marcar(int $id, string $destino, ?string $erro = null): void
    {
        DB::execute(
            'UPDATE webhook_inbox SET processado_em = NOW(), destino = ?, tentativas = tentativas + 1, ultimo_erro = ? WHERE id = ?',
            [$destino, $erro !== null ? substr($erro, 0, 255) : null, $id],
        );
    }

    private static function canonico(array $payload): string
    {
        ksort($payload);

        return (string) json_encode($payload);
    }
}
