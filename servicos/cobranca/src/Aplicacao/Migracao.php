<?php

declare(strict_types=1);

namespace Cobranca\Aplicacao;

use Cobranca\Http\Erro;
use Cobranca\Infra\Db;

/**
 * API de migração. O monólito empurra o histórico em lotes; o serviço nunca lê o banco dele.
 *
 * A carga é silenciosa (não publica eventos: o monólito já tem essas faturas no modelo de
 * leitura) e idempotente. Uma nova carga só sobrescreve fatura que o serviço não alterou desde
 * a última carga ou devolução (ver importarFatura).
 */
final class Migracao
{
    /** @return array{assinaturas: int, faturas: int} */
    public function importarLote(array $lote): array
    {
        return Db::transacao(function () use ($lote): array {
            $assinaturas = 0;
            foreach ($lote['assinaturas'] ?? [] as $a) {
                Db::executar(
                    'INSERT INTO assinaturas (matricula_id, aluno_id, unidade_id, plano_id, valor_mensal, dia_vencimento, inicio, fim, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) AS n
                     ON DUPLICATE KEY UPDATE status = n.status, valor_mensal = n.valor_mensal,
                                             dia_vencimento = n.dia_vencimento, fim = n.fim, unidade_id = n.unidade_id',
                    [
                        (int) $a['matricula_id'], (int) $a['aluno_id'], (int) $a['unidade_id'], (int) $a['plano_id'],
                        (string) $a['valor_mensal'], (int) $a['dia_vencimento'], $a['inicio'], $a['fim'] ?? null, $a['status'],
                    ],
                );
                $assinaturas++;
            }

            $faturas = 0;
            foreach ($lote['faturas'] ?? [] as $f) {
                $this->importarFatura($f);
                $faturas++;
            }

            return ['assinaturas' => $assinaturas, 'faturas' => $faturas];
        });
    }

    /**
     * Insere ou atualiza uma fatura vinda do legado.
     *
     * `versao_importada` guarda a versão no momento da última carga (ou devolução). Se a versão
     * atual é igual, o serviço não mexeu na fatura desde então e o legado ainda é a fonte: pode
     * sobrescrever. Se é maior, o serviço já é dono daquele estado e a carga não toca.
     * Sobrescrever sobe a versão, para que eventos futuros continuem maiores que o que o
     * monólito já tem no modelo de leitura.
     */
    private function importarFatura(array $f): void
    {
        $assinatura = Db::um('SELECT * FROM assinaturas WHERE matricula_id = ?', [(int) $f['matricula_id']]);
        if ($assinatura === null) {
            throw new Erro(422, "Fatura legada {$f['legado_id']} sem assinatura (matrícula {$f['matricula_id']})");
        }

        $existente = Db::um('SELECT * FROM faturas WHERE legado_id = ? OR codigo = ? FOR UPDATE', [(int) $f['legado_id'], $f['codigo']]);
        $estado = [
            'status' => $f['status'],
            'pago_em' => $f['pago_em'],
            'valor_pago' => $f['valor_pago'] ?? '0',
            'qtd_pagamentos' => (int) ($f['qtd_pagamentos'] ?? 0),
            'metodo' => $f['metodo'] ?? null,
        ];

        if ($existente === null) {
            $id = Db::inserir('faturas', $estado + [
                'codigo' => $f['codigo'],
                'legado_id' => (int) $f['legado_id'],
                'assinatura_id' => $assinatura['id'],
                'matricula_id' => $assinatura['matricula_id'],
                'aluno_id' => $assinatura['aluno_id'],
                'unidade_id' => $assinatura['unidade_id'],
                'competencia' => $f['competencia'],
                'valor' => $f['valor'],
                'vencimento' => $f['vencimento'],
                'gateway_ref' => $f['gateway_ref'],
                'versao' => 1,
                'versao_importada' => 1,
            ]);
        } else {
            $id = (int) $existente['id'];
            Db::executar('UPDATE faturas SET legado_id = ? WHERE id = ?', [(int) $f['legado_id'], $id]);
            if ((int) $existente['versao'] === (int) $existente['versao_importada']) {
                $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($estado)));
                Db::executar(
                    "UPDATE faturas SET {$sets}, versao = versao + 1, versao_importada = versao WHERE id = ?",
                    [...array_values($estado), $id],
                );
            }
        }

        if ((float) ($f['valor_pago'] ?? 0) > 0) {
            Db::executar(
                'INSERT IGNORE INTO pagamentos (fatura_id, valor, metodo) VALUES (?, ?, ?)',
                [$id, $f['valor_pago'], $f['metodo'] ?? 'desconhecido'],
            );
        }
    }

    /** Quantidade e soma por competência e status das faturas importadas de uma unidade. */
    public function reconciliacao(int $unidadeId): array
    {
        return Db::todos(
            'SELECT competencia, status, COUNT(*) AS quantidade, SUM(valor) AS total
               FROM faturas WHERE unidade_id = ? AND legado_id IS NOT NULL
              GROUP BY competencia, status ORDER BY competencia, status',
            [$unidadeId],
        );
    }

    public function faturasDaUnidade(int $unidadeId): array
    {
        return Db::todos(
            'SELECT codigo, legado_id, matricula_id, aluno_id, competencia, valor, vencimento, status, pago_em,
                    valor_pago, metodo, gateway_ref
               FROM faturas WHERE unidade_id = ? ORDER BY id',
            [$unidadeId],
        );
    }

    /**
     * Rollback: o serviço para de cobrar a unidade (cron ignora assinaturas devolvidas) e o
     * legado volta a ser a fonte de todas as faturas dela, então uma futura re-migração pode
     * sobrescrevê-las.
     */
    public function devolver(int $unidadeId): int
    {
        return Db::transacao(static function () use ($unidadeId): int {
            Db::executar('UPDATE faturas SET versao_importada = versao WHERE unidade_id = ?', [$unidadeId]);

            return Db::executar(
                "UPDATE assinaturas SET status = 'devolvida' WHERE unidade_id = ? AND status IN ('ativa', 'suspensa')",
                [$unidadeId],
            );
        });
    }
}
