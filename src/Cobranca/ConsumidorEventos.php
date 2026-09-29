<?php

declare(strict_types=1);

namespace EstudaFitHub\Cobranca;

use EstudaFitHub\Models\Aluno;
use EstudaFitHub\Services\NotificacaoService;
use EstudaFitHub\Support\DB;

/**
 * Lê o feed de eventos do serviço (GET /eventos?apos=cursor) e aplica no monólito:
 *  - atualiza o modelo de leitura fin_faturas;
 *  - executa as reações que são do monólito: notificar o aluno e desbloqueá-lo ao pagar.
 *
 * Cada evento é aplicado numa transação que grava o id em cobranca_eventos_aplicados e avança
 * o cursor: reprocessar o mesmo trecho do feed não repete efeito. As notificações saem depois
 * do commit (no máximo uma vez; ver riscos no ADR).
 */
final class ConsumidorEventos
{
    private const NOME = 'cobranca';

    public function __construct(
        private readonly CobrancaClient $cliente,
        private readonly NotificacaoService $notificacoes = new NotificacaoService(),
    ) {
    }

    /** @return array{aplicados: int, ignorados: int, cursor: int} */
    public function consumir(int $limite = 500): array
    {
        $cursor = (int) (DB::selectOne('SELECT cursor_evento FROM cobranca_consumo WHERE nome = ?', [self::NOME])['cursor_evento'] ?? 0);
        $pagina = $this->cliente->eventos($cursor, $limite);

        $aplicados = 0;
        $ignorados = 0;
        foreach ($pagina['eventos'] ?? [] as $evento) {
            $reacao = DB::transaction(function () use ($evento): ?callable {
                $novo = DB::execute(
                    'INSERT IGNORE INTO cobranca_eventos_aplicados (evento_id, tipo) VALUES (?, ?)',
                    [(int) $evento['id'], (string) $evento['tipo']],
                ) > 0;
                DB::execute(
                    'INSERT INTO cobranca_consumo (nome, cursor_evento) VALUES (?, ?) AS n
                     ON DUPLICATE KEY UPDATE cursor_evento = GREATEST(cobranca_consumo.cursor_evento, n.cursor_evento)',
                    [self::NOME, (int) $evento['id']],
                );
                if (!$novo) {
                    return null;
                }
                if ($this->devolvidaAoLegado($evento['fatura'])) {
                    // Evento atrasado de uma unidade que sofreu rollback: a linha voltou a ser do
                    // legado (triggers) e não pode ser retomada por aqui, nem gerar notificação.
                    return static fn () => null;
                }
                ProjecaoFinanceira::aplicar($evento['fatura'], Roteamento::migrada((int) ($evento['fatura']['unidade_id'] ?? 0)));

                return $this->reagir($evento);
            });

            if ($reacao === null) {
                $ignorados++;
                continue;
            }
            $reacao();
            $aplicados++;
        }

        return ['aplicados' => $aplicados, 'ignorados' => $ignorados, 'cursor' => (int) ($pagina['proximo_cursor'] ?? $cursor)];
    }

    private function devolvidaAoLegado(array $fatura): bool
    {
        if (Roteamento::migrada((int) ($fatura['unidade_id'] ?? 0))) {
            return false;
        }
        $linha = DB::selectOne('SELECT origem FROM fin_faturas WHERE codigo = ?', [(string) $fatura['codigo']]);

        return ($linha['origem'] ?? null) === 'legado';
    }

    /**
     * Mudanças de estado do monólito acontecem dentro da transação; notificações são devolvidas
     * como closure para rodar depois do commit.
     */
    private function reagir(array $evento): callable
    {
        $fatura = $evento['fatura'];
        $aluno = Aluno::find((int) $fatura['aluno_id']);
        if ($aluno === null) {
            return static fn () => null;
        }

        switch ($evento['tipo']) {
            case 'FaturaGerada':
                return fn () => $this->notificacoes->enviarEmail($aluno, 'fatura_gerada', ['fatura' => $fatura]);

            case 'FaturaPaga':
                // Mesma regra do legado: pagamento confirmado reativa aluno bloqueado.
                // Quem decide a situação do aluno é o monólito, a partir do fato publicado.
                if ($aluno->situacao === 'bloqueado') {
                    $aluno->update(['situacao' => 'ativo']);
                }

                return fn () => $this->notificacoes->enviarSms($aluno, 'pagamento_confirmado', ['fatura' => $fatura['codigo']]);

            case 'PagamentoRecusado':
                return fn () => $this->notificacoes->enviarEmail($aluno, 'pagamento_recusado', ['fatura' => $fatura['codigo']]);

            default:
                return static fn () => null;
        }
    }
}
