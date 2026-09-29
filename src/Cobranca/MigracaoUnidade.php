<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Support\DB;

/**
 * Corte e rollback de Cobrança por unidade.
 *
 * O monólito lê o próprio banco e EMPURRA o histórico para o serviço pela API de migração;
 * o serviço nunca lê o banco do monólito. A carga é idempotente (upsert por legado_id) e
 * nunca sobrescreve uma fatura que o serviço já alterou depois de importada.
 */
final class MigracaoUnidade
{
    public function __construct(
        private readonly CobrancaClient $cliente,
        private readonly int $tamanhoLote = 1000,
        private readonly int $pausaAntesDoDeltaSegundos = 2,
    ) {
    }

    /**
     * Carga completa -> troca de dono -> carga de novo (pega o que mudou no meio) -> reconciliação.
     * @return array{carga: array, delta: array, divergencias: array}
     */
    public function migrar(int $unidadeId, ?callable $log = null): array
    {
        $log ??= static fn (string $m) => null;

        $log("Carga inicial da unidade {$unidadeId}");
        $carga = $this->carregar($unidadeId);

        $log('Trocando o dono da escrita para o serviço');
        Roteamento::definir($unidadeId, Roteamento::SERVICO);
        if ($this->pausaAntesDoDeltaSegundos > 0) {
            sleep($this->pausaAntesDoDeltaSegundos); // deixa terminar transações legadas que começaram antes da troca
        }

        $log('Carga de delta (idempotente)');
        $delta = $this->carregar($unidadeId);

        $log('Reconciliando');
        $divergencias = $this->reconciliar($unidadeId);

        return ['carga' => $carga, 'delta' => $delta, 'divergencias' => $divergencias];
    }

    /** @return array{assinaturas: int, faturas: int} */
    public function carregar(int $unidadeId): array
    {
        $total = ['assinaturas' => 0, 'faturas' => 0];

        $ultimo = 0;
        do {
            $matriculas = DB::select(
                'SELECT m.id, m.aluno_id, m.plano_id, m.inicio, m.fim, m.status, m.dia_vencimento, p.valor_mensal
                   FROM matriculas m
                   JOIN alunos a ON a.id = m.aluno_id
                   JOIN planos p ON p.id = m.plano_id
                  WHERE a.unidade_id = ? AND m.id > ?
                  ORDER BY m.id LIMIT ' . $this->tamanhoLote,
                [$unidadeId, $ultimo],
            );
            if ($matriculas === []) {
                break;
            }
            $assinaturas = array_map(static fn (array $m): array => [
                'matricula_id' => (int) $m['id'],
                'aluno_id' => (int) $m['aluno_id'],
                'unidade_id' => $unidadeId,
                'plano_id' => (int) $m['plano_id'],
                'valor_mensal' => $m['valor_mensal'],
                'dia_vencimento' => (int) $m['dia_vencimento'],
                'inicio' => $m['inicio'],
                'fim' => $m['fim'],
                'status' => ['ativa' => 'ativa', 'trancada' => 'suspensa', 'cancelada' => 'cancelada'][$m['status']],
            ], $matriculas);
            $r = $this->cliente->importarLote(['assinaturas' => $assinaturas, 'faturas' => []]);
            $total['assinaturas'] += (int) ($r['assinaturas'] ?? 0);
            $ultimo = (int) end($matriculas)['id'];
        } while (count($matriculas) === $this->tamanhoLote);

        $ultimo = 0;
        do {
            $faturas = DB::select(
                'SELECT f.*, COALESCE(SUM(p.valor), 0) AS valor_pago, COUNT(p.id) AS qtd_pagamentos,
                        MAX(p.metodo) AS metodo
                   FROM faturas f
                   JOIN alunos a ON a.id = f.aluno_id
                   LEFT JOIN pagamentos p ON p.fatura_id = f.id
                  WHERE a.unidade_id = ? AND f.id > ?
                  GROUP BY f.id
                  ORDER BY f.id LIMIT ' . $this->tamanhoLote,
                [$unidadeId, $ultimo],
            );
            if ($faturas === []) {
                break;
            }
            $lote = array_map(static fn (array $f): array => [
                'legado_id' => (int) $f['id'],
                'codigo' => $f['codigo_externo'] ?? 'leg_' . $f['id'],
                'matricula_id' => (int) $f['matricula_id'],
                'competencia' => $f['competencia'],
                'valor' => $f['valor'],
                'vencimento' => $f['vencimento'],
                'status' => $f['status'],
                'pago_em' => $f['pago_em'],
                'gateway_ref' => $f['gateway_ref'],
                'valor_pago' => $f['valor_pago'],
                'qtd_pagamentos' => (int) $f['qtd_pagamentos'],
                'metodo' => $f['metodo'],
            ], $faturas);
            $r = $this->cliente->importarLote(['assinaturas' => [], 'faturas' => $lote]);
            $total['faturas'] += (int) ($r['faturas'] ?? 0);
            $ultimo = (int) end($faturas)['id'];
        } while (count($faturas) === $this->tamanhoLote);

        return $total;
    }

    /**
     * Compara quantidade e soma por competência e status entre o legado e o serviço.
     * Faturas que o serviço criou depois do corte não existem no legado e são ignoradas.
     * @return array<int, array{competencia: string, status: string, legado: string, servico: string}>
     */
    public function reconciliar(int $unidadeId): array
    {
        $legado = [];
        foreach (DB::select(
            'SELECT f.competencia, f.status, COUNT(*) AS quantidade, SUM(f.valor) AS total
               FROM faturas f JOIN alunos a ON a.id = f.aluno_id
              WHERE a.unidade_id = ?
              GROUP BY f.competencia, f.status',
            [$unidadeId],
        ) as $l) {
            $legado["{$l['competencia']}|{$l['status']}"] = sprintf('%d/%.2f', $l['quantidade'], $l['total']);
        }

        $servico = [];
        foreach ($this->cliente->reconciliacao($unidadeId) as $l) {
            $servico["{$l['competencia']}|{$l['status']}"] = sprintf('%d/%.2f', $l['quantidade'], $l['total']);
        }

        $divergencias = [];
        foreach (array_unique(array_merge(array_keys($legado), array_keys($servico))) as $chave) {
            $a = $legado[$chave] ?? '0/0.00';
            $b = $servico[$chave] ?? '0/0.00';
            if ($a !== $b) {
                [$competencia, $status] = explode('|', $chave);
                $divergencias[] = ['competencia' => $competencia, 'status' => $status, 'legado' => $a, 'servico' => $b];
            }
        }

        return $divergencias;
    }

    /**
     * Rollback: devolve a escrita ao legado e traz de volta o que o serviço criou ou mudou.
     * Idempotente: pode ser executado de novo para pegar retardatários.
     * @return array{atualizadas: int, inseridas: int}
     */
    public function reverter(int $unidadeId): array
    {
        Roteamento::definir($unidadeId, Roteamento::LEGADO);
        $this->cliente->devolverUnidade($unidadeId);

        $atualizadas = 0;
        $inseridas = 0;
        foreach ($this->cliente->faturasDaUnidade($unidadeId) as $f) {
            DB::transaction(function () use ($f, &$atualizadas, &$inseridas): void {
                // A linha do modelo de leitura volta a ser mantida pelos triggers do legado.
                DB::execute("UPDATE fin_faturas SET origem = 'legado' WHERE codigo = ?", [$f['codigo']]);

                $legadoId = $f['legado_id'] !== null ? (int) $f['legado_id']
                    : (int) (DB::selectOne('SELECT id FROM faturas WHERE codigo_externo = ?', [$f['codigo']])['id'] ?? 0);

                if ($legadoId > 0) {
                    DB::execute(
                        'UPDATE faturas SET status = ?, pago_em = ?, gateway_ref = COALESCE(?, gateway_ref) WHERE id = ?',
                        [$f['status'], $f['pago_em'], $f['gateway_ref'], $legadoId],
                    );
                    $atualizadas++;
                } else {
                    $legadoId = DB::insert('faturas', [
                        'matricula_id' => (int) $f['matricula_id'],
                        'aluno_id' => (int) $f['aluno_id'],
                        'competencia' => $f['competencia'],
                        'valor' => $f['valor'],
                        'vencimento' => $f['vencimento'],
                        'status' => $f['status'],
                        'pago_em' => $f['pago_em'],
                        'gateway_ref' => $f['gateway_ref'],
                        'codigo_externo' => $f['codigo'],
                    ]);
                    $inseridas++;
                }

                $temPagamento = DB::selectOne('SELECT 1 FROM pagamentos WHERE fatura_id = ? LIMIT 1', [$legadoId]) !== null;
                if ((float) $f['valor_pago'] > 0 && !$temPagamento) {
                    DB::insert('pagamentos', [
                        'fatura_id' => $legadoId,
                        'valor' => $f['valor_pago'],
                        'metodo' => $f['metodo'] ?? 'desconhecido',
                        'gateway_payload' => null,
                    ]);
                }
                // O legado volta a ser a fonte dos pagamentos desta fatura no modelo de leitura.
                DB::execute(
                    'UPDATE fin_faturas f
                       JOIN (SELECT COALESCE(SUM(valor), 0) AS v, COUNT(*) AS q FROM pagamentos WHERE fatura_id = ?) p
                        SET f.valor_pago = p.v, f.qtd_pagamentos = p.q
                      WHERE f.codigo = ?',
                    [$legadoId, $f['codigo']],
                );
            });
        }

        return ['atualizadas' => $atualizadas, 'inseridas' => $inseridas];
    }
}
