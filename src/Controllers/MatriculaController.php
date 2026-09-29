<?php

declare(strict_types=1);

namespace EstudaFitHub\Controllers;

use EstudaFitHub\Cobranca\Roteamento;
use EstudaFitHub\Http\Request;
use EstudaFitHub\Http\Response;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Models\Plano;
use EstudaFitHub\Services\MatriculaService;
use EstudaFitHub\Support\HttpException;

final class MatriculaController
{
    public function criar(Request $request): Response
    {
        $request->exigir(['aluno_id', 'plano_id', 'dia_vencimento']);

        $aluno = Aluno::find((int) $request->input('aluno_id'));
        if ($aluno === null) {
            throw new HttpException(404, 'Aluno não encontrado');
        }
        $plano = Plano::find((int) $request->input('plano_id'));
        if ($plano === null || (int) $plano->ativo !== 1) {
            throw new HttpException(404, 'Plano não encontrado');
        }

        $matricula = (new MatriculaService())->criar($aluno, $plano, (int) $request->input('dia_vencimento'));

        // Unidade migrada: a primeira fatura é gerada pelo serviço, alguns segundos depois.
        // O cliente acompanha por GET /alunos/{id}/faturas.
        if (Roteamento::migrada((int) $aluno->unidade_id)) {
            return Response::json([
                'matricula' => $matricula->toArray(),
                'primeira_fatura' => null,
                'cobranca' => 'em_processamento',
            ], 201);
        }

        $primeiraFatura = Fatura::where('matricula_id', $matricula->id)->first();

        return Response::json([
            'matricula' => $matricula->toArray(),
            'primeira_fatura' => $primeiraFatura?->toArray(),
            'cobranca' => 'legado',
        ], 201);
    }

    public function cancelar(Request $request): Response
    {
        $matricula = Matricula::find((int) $request->param('id'));
        if ($matricula === null) {
            throw new HttpException(404, 'Matrícula não encontrada');
        }

        (new MatriculaService())->cancelar($matricula);

        return Response::json(['matricula' => $matricula->refresh()->toArray()]);
    }
}
