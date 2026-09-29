<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Cobranca\SituacaoFinanceira;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Models\Unidade;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\HttpException;

final class AlunoController
{
    public function criar(Request $request): Response
    {
        $request->exigir(['nome', 'email', 'telefone', 'cpf', 'unidade_id']);

        $cpf = preg_replace('/\D/', '', (string) $request->input('cpf')) ?? '';
        if (strlen($cpf) !== 11) {
            throw new HttpException(422, 'CPF deve ter 11 dígitos');
        }
        if (Aluno::where('cpf', $cpf)->exists()) {
            throw new HttpException(422, 'CPF já cadastrado');
        }
        if (Unidade::find((int) $request->input('unidade_id')) === null) {
            throw new HttpException(422, 'Unidade inexistente');
        }

        $aluno = Aluno::create([
            'nome' => (string) $request->input('nome'),
            'email' => (string) $request->input('email'),
            'telefone' => (string) $request->input('telefone'),
            'cpf' => $cpf,
            'unidade_id' => (int) $request->input('unidade_id'),
            'situacao' => 'ativo',
        ]);

        return Response::json($aluno->toArray(), 201);
    }

    public function mostrar(Request $request): Response
    {
        $aluno = Aluno::find((int) $request->param('id'));
        if ($aluno === null) {
            throw new HttpException(404, 'Aluno não encontrado');
        }

        $matriculas = array_map(
            static fn (Matricula $m): array => $m->toArray(),
            Matricula::where('aluno_id', $aluno->id)->orderBy('id', 'DESC')->get(),
        );

        return Response::json([
            'aluno' => $aluno->toArray(),
            'matriculas' => $matriculas,
            'financeiro' => SituacaoFinanceira::resumo((int) $aluno->id),
        ]);
    }

    public function faturas(Request $request): Response
    {
        $aluno = Aluno::find((int) $request->param('id'));
        if ($aluno === null) {
            throw new HttpException(404, 'Aluno não encontrado');
        }

        $faturas = DB::select(
            'SELECT codigo, matricula_id, aluno_id, competencia, valor, vencimento, status, pago_em, valor_pago, origem
               FROM fin_faturas WHERE aluno_id = ? ORDER BY competencia DESC',
            [$aluno->id],
        );

        return Response::json(['aluno_id' => $aluno->id, 'faturas' => $faturas]);
    }
}
