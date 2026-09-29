<?php

declare(strict_types=1);

namespace Cobranca\Aplicacao;

use Cobranca\Http\Erro;
use Cobranca\Infra\Db;

/**
 * Comandos que chegam do monólito (via outbox). Todos idempotentes por matricula_id,
 * porque o relay entrega "pelo menos uma vez".
 */
final class Assinaturas
{
    public function __construct(private readonly Faturas $faturas = new Faturas())
    {
    }

    /**
     * MatriculaCriada: cria a assinatura e a primeira fatura (competência do mês, vencimento
     * informado pelo monólito, que era "hoje + 3 dias" no legado).
     * @return array{assinatura: array, primeira_fatura: array, criada: bool}
     */
    public function criar(array $d): array
    {
        foreach (['matricula_id', 'aluno_id', 'unidade_id', 'plano_id', 'valor_mensal', 'dia_vencimento'] as $campo) {
            if (!isset($d[$campo]) || $d[$campo] === '') {
                throw new Erro(422, "Campo obrigatório: {$campo}");
            }
        }
        $dia = (int) $d['dia_vencimento'];
        if ($dia < 1 || $dia > 28) {
            throw new Erro(422, 'dia_vencimento deve estar entre 1 e 28');
        }

        $criada = Db::executar(
            'INSERT IGNORE INTO assinaturas (matricula_id, aluno_id, unidade_id, plano_id, valor_mensal, dia_vencimento, inicio, fim)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $d['matricula_id'], (int) $d['aluno_id'], (int) $d['unidade_id'], (int) $d['plano_id'],
                (string) $d['valor_mensal'], $dia, $d['inicio'] ?? date('Y-m-d'), $d['fim'] ?? null,
            ],
        ) === 1;
        $assinatura = self::porMatricula((int) $d['matricula_id']);

        $inicio = (string) $assinatura['inicio'];
        $primeira = $this->faturas->gerar($assinatura, $inicio, $d['primeiro_vencimento'] ?? date('Y-m-d', strtotime($inicio . ' +3 days')));

        return ['assinatura' => $assinatura, 'primeira_fatura' => $primeira['fatura'], 'criada' => $criada];
    }

    /** POST /assinaturas/{matricula_id}/faturas {competencia}: gera (ou devolve) a fatura da competência. */
    public function gerarFatura(int $matriculaId, string $competencia): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $competencia)) {
            throw new Erro(422, 'competencia deve estar no formato YYYY-MM');
        }
        $assinatura = self::porMatricula($matriculaId);
        if ($assinatura['status'] !== 'ativa') {
            throw new Erro(422, "Assinatura {$assinatura['status']} não gera fatura");
        }

        return $this->faturas->gerar($assinatura, $competencia);
    }

    /** MatriculaCancelada: encerra a assinatura e cancela o que está em aberto ou vencido. */
    public function cancelar(int $matriculaId, ?string $data = null): array
    {
        return Db::transacao(function () use ($matriculaId, $data): array {
            $assinatura = Db::um('SELECT * FROM assinaturas WHERE matricula_id = ? FOR UPDATE', [$matriculaId]);
            if ($assinatura === null) {
                throw new Erro(404, 'Assinatura não encontrada');
            }
            $canceladas = 0;
            if ($assinatura['status'] !== 'cancelada') {
                Db::executar("UPDATE assinaturas SET status = 'cancelada', fim = ? WHERE id = ?", [$data ?? date('Y-m-d'), $assinatura['id']]);
                $abertas = Db::todos(
                    "SELECT * FROM faturas WHERE assinatura_id = ? AND status IN ('aberta', 'vencida') FOR UPDATE",
                    [$assinatura['id']],
                );
                foreach ($abertas as $f) {
                    Faturas::transicionar($f, ['status' => 'cancelada'], Eventos::FATURA_CANCELADA);
                    $canceladas++;
                }
            }

            return ['matricula_id' => $matriculaId, 'faturas_canceladas' => $canceladas];
        });
    }

    public static function porMatricula(int $matriculaId): array
    {
        $a = Db::um('SELECT * FROM assinaturas WHERE matricula_id = ?', [$matriculaId]);
        if ($a === null) {
            throw new Erro(404, 'Assinatura não encontrada');
        }

        return $a;
    }
}
