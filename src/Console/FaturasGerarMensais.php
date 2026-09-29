<?php

declare(strict_types=1);

namespace EstudaFitHub\Console;

use EstudaFitHub\Cobranca\Roteamento;
use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Models\Fatura;
use EstudaFitHub\Models\Matricula;
use EstudaFitHub\Services\GatewayPagamento;

/**
 * Cron LEGADO do dia 1º: gera a fatura da competência para toda matrícula ativa
 * de unidades que ainda não migraram. As migradas são do cron do serviço.
 * Uso: php bin/console faturas:gerar-mensais [YYYY-MM]
 */
final class FaturasGerarMensais implements Comando
{
    public function executar(array $args): int
    {
        $competencia = date('Y-m-01');
        if (isset($args[0])) {
            if (!preg_match('/^\d{4}-\d{2}$/', $args[0])) {
                fwrite(STDERR, "Competência inválida, use YYYY-MM\n");

                return 1;
            }
            $competencia = $args[0] . '-01';
        }

        $gateway = new GatewayPagamento();
        $migradas = array_flip(Roteamento::unidadesMigradas());
        $geradas = 0;
        $puladas = 0;
        $ignoradas = 0;

        Matricula::where('status', 'ativa')->chunk(1000, function (array $matriculas) use ($competencia, $gateway, $migradas, &$geradas, &$puladas, &$ignoradas): void {
            /** @var Matricula $m */
            foreach ($matriculas as $m) {
                if ($migradas !== [] && isset($migradas[(int) Aluno::find((int) $m->aluno_id)?->unidade_id])) {
                    $ignoradas++;
                    continue;
                }

                if (Fatura::where('matricula_id', $m->id)->where('competencia', $competencia)->exists()) {
                    $puladas++;
                    continue;
                }

                $plano = $m->plano();
                if ($plano === null) {
                    continue;
                }

                $fatura = Fatura::create([
                    'matricula_id' => $m->id,
                    'aluno_id' => $m->aluno_id,
                    'competencia' => $competencia,
                    'valor' => $plano->valor_mensal,
                    'vencimento' => sprintf('%s-%02d', substr($competencia, 0, 7), (int) $m->dia_vencimento),
                    'status' => 'aberta',
                ]);

                // Uma chamada ao gateway por fatura, em série.
                $fatura->update(['gateway_ref' => $gateway->registrarCobranca($fatura)]);
                $geradas++;
            }
        });

        echo "Competência {$competencia}: {$geradas} faturas geradas, {$puladas} já existiam, {$ignoradas} de unidades migradas\n";

        return 0;
    }
}
