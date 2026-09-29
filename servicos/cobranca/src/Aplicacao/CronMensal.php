<?php

declare(strict_types=1);

namespace Cobranca\Aplicacao;

use Cobranca\Infra\Db;

/**
 * Cron do dia 1º no serviço.
 *
 * Diferenças para o legado:
 *  - particionável: `--particao=i/n` processa só assinaturas com id % n = i, então n processos
 *    rodam em paralelo sem se pisar (250 mil faturas em 1 hora = ~70/s; 8 partições folgam);
 *  - idempotente por constraint: pode ser interrompido e reexecutado; o que já existe é pulado;
 *  - inserções em lote numa transação por página, com o evento FaturaGerada junto;
 *  - o gateway é chamado depois, fora da transação e fora do laço de geração
 *    (worker `gateway:registrar`), então lentidão do gateway não segura a geração.
 */
final class CronMensal
{
    /** @return array{geradas: int, existentes: int} */
    public function executar(string $competencia, int $particao = 0, int $particoes = 1, int $lote = 500): array
    {
        $competencia = substr($competencia, 0, 7) . '-01';
        $geradas = 0;
        $existentes = 0;
        $ultimoId = 0;

        do {
            $assinaturas = Db::todos(
                "SELECT * FROM assinaturas
                  WHERE status = 'ativa' AND id > ? AND MOD(id, ?) = ?
                  ORDER BY id LIMIT " . max(1, $lote),
                [$ultimoId, max(1, $particoes), $particao],
            );
            if ($assinaturas === []) {
                break;
            }

            [$g, $e] = Db::transacao(function () use ($assinaturas, $competencia): array {
                $g = 0;
                $e = 0;
                foreach ($assinaturas as $a) {
                    $codigo = 'fat_' . bin2hex(random_bytes(10));
                    $vencimento = sprintf('%s-%02d', substr($competencia, 0, 7), (int) $a['dia_vencimento']);
                    $inserida = Db::executar(
                        'INSERT IGNORE INTO faturas (codigo, assinatura_id, matricula_id, aluno_id, unidade_id, competencia, valor, vencimento)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$codigo, $a['id'], $a['matricula_id'], $a['aluno_id'], $a['unidade_id'], $competencia, $a['valor_mensal'], $vencimento],
                    ) === 1;
                    if ($inserida) {
                        Eventos::publicar(Eventos::FATURA_GERADA, Db::um('SELECT * FROM faturas WHERE codigo = ?', [$codigo]));
                        $g++;
                    } else {
                        $e++;
                    }
                }

                return [$g, $e];
            });
            $geradas += $g;
            $existentes += $e;
            $ultimoId = (int) end($assinaturas)['id'];
        } while (count($assinaturas) === $lote);

        return ['geradas' => $geradas, 'existentes' => $existentes];
    }
}
