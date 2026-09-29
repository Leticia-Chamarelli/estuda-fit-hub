<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\HttpException;

/**
 * Relatórios gerenciais. Continuam sendo JOIN local, mas a parte financeira vem do modelo
 * de leitura fin_faturas, que cobre legado e serviço ao mesmo tempo. Nenhuma etapa da
 * migração muda estas consultas; o atraso máximo é o do consumidor de eventos.
 */
final class RelatorioController
{
    public function inadimplencia(Request $request): Response
    {
        $unidadeId = (int) $request->query('unidade_id', 0);
        if ($unidadeId <= 0) {
            throw new HttpException(422, 'Informe unidade_id');
        }

        $linhas = DB::select(
            'SELECT a.id AS aluno_id, a.nome, a.telefone, u.nome AS unidade, p.nome AS plano,
                    COUNT(f.codigo) AS faturas_vencidas, SUM(f.valor) AS total_devido,
                    MIN(f.vencimento) AS vencida_desde
               FROM fin_faturas f
               JOIN alunos a      ON a.id = f.aluno_id
               JOIN unidades u    ON u.id = a.unidade_id
               JOIN matriculas m  ON m.id = f.matricula_id
               JOIN planos p      ON p.id = m.plano_id
              WHERE f.status = ? AND a.unidade_id = ?
              GROUP BY a.id, a.nome, a.telefone, u.nome, p.nome
              ORDER BY total_devido DESC',
            ['vencida', $unidadeId],
        );

        return Response::json(['unidade_id' => $unidadeId, 'inadimplentes' => $linhas]);
    }

    public function receita(Request $request): Response
    {
        $competencia = (string) $request->query('competencia', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
            throw new HttpException(422, 'competencia deve estar no formato YYYY-MM');
        }

        $linhas = DB::select(
            'SELECT u.nome AS unidade, p.nome AS plano,
                    SUM(f.qtd_pagamentos) AS pagamentos, SUM(f.valor_pago) AS receita
               FROM fin_faturas f
               JOIN matriculas m  ON m.id = f.matricula_id
               JOIN planos p      ON p.id = m.plano_id
               JOIN alunos a      ON a.id = f.aluno_id
               JOIN unidades u    ON u.id = a.unidade_id
              WHERE f.competencia = ? AND f.qtd_pagamentos > 0
              GROUP BY u.nome, p.nome
              ORDER BY receita DESC',
            [$competencia . '-01'],
        );

        return Response::json(['competencia' => $competencia, 'receita' => $linhas]);
    }
}
