<?php

declare(strict_types=1);

namespace Cobranca\Aplicacao;

use Cobranca\Infra\Db;
use Cobranca\Infra\Gateway;
use Throwable;

/**
 * Caso de uso "gerar fatura para uma matrícula" e as transições de estado da fatura.
 *
 * Idempotência por constraint: UNIQUE (assinatura_id, competencia). Gerar duas vezes a mesma
 * competência devolve a fatura existente, sem segundo evento. O registro no gateway fica FORA
 * da transação: se o gateway cair, a fatura existe sem referência e o worker tenta de novo.
 */
final class Faturas
{
    public function __construct(private readonly Gateway $gateway = new Gateway())
    {
    }

    /** @return array{fatura: array, criada: bool} */
    public function gerar(array $assinatura, string $competencia, ?string $vencimento = null): array
    {
        $competencia = substr($competencia, 0, 7) . '-01';
        $vencimento ??= sprintf('%s-%02d', substr($competencia, 0, 7), (int) $assinatura['dia_vencimento']);

        $resultado = Db::transacao(function () use ($assinatura, $competencia, $vencimento): array {
            $criada = Db::executar(
                'INSERT IGNORE INTO faturas (codigo, assinatura_id, matricula_id, aluno_id, unidade_id, competencia, valor, vencimento)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    self::novoCodigo(), $assinatura['id'], $assinatura['matricula_id'], $assinatura['aluno_id'],
                    $assinatura['unidade_id'], $competencia, $assinatura['valor_mensal'], $vencimento,
                ],
            ) === 1;
            $fatura = Db::um('SELECT * FROM faturas WHERE assinatura_id = ? AND competencia = ?', [$assinatura['id'], $competencia]);
            if ($criada) {
                Eventos::publicar(Eventos::FATURA_GERADA, $fatura);
            }

            return ['fatura' => $fatura, 'criada' => $criada];
        });

        if ($resultado['fatura']['gateway_ref'] === null) {
            $resultado['fatura'] = $this->registrarNoGateway($resultado['fatura']);
        }

        return $resultado;
    }

    /** Tenta registrar no gateway; em caso de falha devolve a fatura sem referência. */
    public function registrarNoGateway(array $fatura): array
    {
        try {
            $ref = $this->gateway->registrarCobranca($fatura['codigo'], (string) $fatura['valor'], (string) $fatura['vencimento']);
        } catch (Throwable $e) {
            error_log("[gateway] falha ao registrar {$fatura['codigo']}: {$e->getMessage()}");

            return $fatura;
        }
        Db::executar('UPDATE faturas SET gateway_ref = ?, versao = versao + 1 WHERE id = ? AND gateway_ref IS NULL', [$ref, $fatura['id']]);

        return Db::um('SELECT * FROM faturas WHERE id = ?', [$fatura['id']]);
    }

    /** Worker: faturas que ficaram sem referência no gateway. */
    public function registrarPendentes(int $limite = 500): int
    {
        $registradas = 0;
        foreach (Db::todos('SELECT * FROM faturas WHERE gateway_ref IS NULL AND status = \'aberta\' ORDER BY id LIMIT ' . max(1, $limite)) as $f) {
            if ($this->registrarNoGateway($f)['gateway_ref'] !== null) {
                $registradas++;
            }
        }

        return $registradas;
    }

    /**
     * Muda o estado de uma fatura travada (FOR UPDATE), sobe a versão e publica o evento,
     * tudo na transação corrente.
     */
    public static function transicionar(array $fatura, array $mudancas, string $evento): array
    {
        $sets = array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($mudancas));
        $sets[] = 'versao = versao + 1';
        Db::executar('UPDATE faturas SET ' . implode(', ', $sets) . ' WHERE id = ?', [...array_values($mudancas), $fatura['id']]);
        $nova = Db::um('SELECT * FROM faturas WHERE id = ?', [$fatura['id']]);
        Eventos::publicar($evento, $nova);

        return $nova;
    }

    /** Cron diário: abertas com vencimento no passado viram vencidas, em lotes. */
    public function marcarVencidas(string $hoje, int $lote = 500): int
    {
        $total = 0;
        do {
            $n = Db::transacao(function () use ($hoje, $lote): int {
                $faturas = Db::todos(
                    "SELECT * FROM faturas WHERE status = 'aberta' AND vencimento < ? ORDER BY id LIMIT {$lote} FOR UPDATE SKIP LOCKED",
                    [$hoje],
                );
                foreach ($faturas as $f) {
                    self::transicionar($f, ['status' => 'vencida'], Eventos::FATURA_VENCIDA);
                }

                return count($faturas);
            });
            $total += $n;
        } while ($n === $lote);

        return $total;
    }

    private static function novoCodigo(): string
    {
        return 'fat_' . bin2hex(random_bytes(10));
    }
}
