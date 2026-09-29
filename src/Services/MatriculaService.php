<?php

declare(strict_types=1);

namespace EstudaFitHub\Services;

use EstudaFitHub\Cobranca\Outbox;
use EstudaFitHub\Cobranca\Roteamento;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Models\Plano;
use EstudaFitHub\Support\DB;
use EstudaFitHub\Support\HttpException;

final class MatriculaService
{
    public function criar(Aluno $aluno, Plano $plano, int $diaVencimento): Matricula
    {
        if ($aluno->situacao === 'bloqueado') {
            throw new HttpException(422, 'Aluno bloqueado por inadimplência não pode se matricular');
        }
        if ($diaVencimento < 1 || $diaVencimento > 28) {
            throw new HttpException(422, 'dia_vencimento deve estar entre 1 e 28');
        }
        if (Matricula::where('aluno_id', $aluno->id)->where('status', 'ativa')->exists()) {
            throw new HttpException(422, 'Aluno já possui matrícula ativa');
        }

        if (Roteamento::migrada((int) $aluno->unidade_id)) {
            return $this->criarComServico($aluno, $plano, $diaVencimento);
        }

        return DB::transaction(function () use ($aluno, $plano, $diaVencimento): Matricula {
            $matricula = $this->novaMatricula($aluno, $plano, $diaVencimento);

            // Caminho legado (unidades não migradas), preservado como era até a unidade migrar.
            $fatura = Fatura::create([
                'matricula_id' => $matricula->id,
                'aluno_id' => $aluno->id,
                'competencia' => date('Y-m-01'),
                'valor' => $plano->valor_mensal,
                'vencimento' => date('Y-m-d', strtotime('+3 days')),
                'status' => 'aberta',
            ]);

            $referencia = (new GatewayPagamento())->registrarCobranca($fatura);
            $fatura->update(['gateway_ref' => $referencia]);

            (new NotificacaoService())->enviarEmail($aluno, 'fatura_gerada', ['fatura' => $fatura->toArray()]);

            return $matricula;
        });
    }

    /**
     * Unidade migrada: a matrícula e o comando para Cobrança são gravados na mesma transação.
     * Nada de gateway nem e-mail aqui dentro; a primeira fatura nasce no serviço e o e-mail sai
     * quando o monólito consome o evento FaturaGerada.
     */
    private function criarComServico(Aluno $aluno, Plano $plano, int $diaVencimento): Matricula
    {
        return DB::transaction(function () use ($aluno, $plano, $diaVencimento): Matricula {
            $matricula = $this->novaMatricula($aluno, $plano, $diaVencimento);

            Outbox::registrar(Outbox::MATRICULA_CRIADA, "matricula:{$matricula->id}", [
                'matricula_id' => (int) $matricula->id,
                'aluno_id' => (int) $aluno->id,
                'unidade_id' => (int) $aluno->unidade_id,
                'plano_id' => (int) $plano->id,
                'valor_mensal' => (string) $plano->valor_mensal,
                'dia_vencimento' => $diaVencimento,
                'inicio' => $matricula->inicio,
                'fim' => $matricula->fim,
                'primeiro_vencimento' => date('Y-m-d', strtotime('+3 days')),
            ]);

            return $matricula;
        });
    }

    private function novaMatricula(Aluno $aluno, Plano $plano, int $diaVencimento): Matricula
    {
        $matricula = Matricula::create([
            'aluno_id' => $aluno->id,
            'plano_id' => $plano->id,
            'inicio' => date('Y-m-d'),
            'fim' => date('Y-m-d', strtotime("+{$plano->duracao_meses} months")),
            'status' => 'ativa',
            'dia_vencimento' => $diaVencimento,
        ]);

        if ($aluno->situacao !== 'ativo') {
            $aluno->update(['situacao' => 'ativo']);
        }

        return $matricula;
    }

    public function cancelar(Matricula $matricula): void
    {
        if ($matricula->status === 'cancelada') {
            throw new HttpException(422, 'Matrícula já cancelada');
        }

        $aluno = $matricula->aluno();
        $migrada = $aluno !== null && Roteamento::migrada((int) $aluno->unidade_id);

        DB::transaction(function () use ($matricula, $aluno, $migrada): void {
            $matricula->update(['status' => 'cancelada', 'fim' => date('Y-m-d')]);

            if ($migrada) {
                Outbox::registrar(Outbox::MATRICULA_CANCELADA, "matricula:{$matricula->id}", [
                    'matricula_id' => (int) $matricula->id,
                    'cancelada_em' => date('Y-m-d'),
                ]);
            } else {
                Fatura::where('matricula_id', $matricula->id)
                    ->whereIn('status', ['aberta', 'vencida'])
                    ->update(['status' => 'cancelada']);
            }

            $aluno?->update(['situacao' => 'inativo']);
        });

        if ($aluno !== null) {
            (new NotificacaoService())->enviarEmail($aluno, 'matricula_cancelada', ['matricula_id' => $matricula->id]);
        }
    }
}
