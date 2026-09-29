<?php

declare(strict_types=1);

namespace Cobranca\Http;

/**
 * Roteador mínimo. Rotas internas (chamadas pelo monólito) exigem o cabeçalho
 * X-Servico-Token; o webhook do gateway é público (assinatura do gateway fica como pendência).
 */
final class Rotas
{
    /** @var array<int, array{string, string, callable, bool}> */
    private array $rotas = [];

    public function add(string $metodo, string $padrao, callable $acao, bool $interna = true): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $padrao) . '$#';
        $this->rotas[] = [$metodo, $regex, $acao, $interna];
    }

    public function despachar(Requisicao $req): Resposta
    {
        foreach ($this->rotas as [$metodo, $regex, $acao, $interna]) {
            if ($metodo !== $req->metodo || !preg_match($regex, $req->caminho, $m)) {
                continue;
            }
            if ($interna && ($req->cabecalhos['x-servico-token'] ?? '') !== (getenv('COBRANCA_TOKEN') ?: 'token-local')) {
                throw new Erro(401, 'Token de serviço inválido');
            }
            $req->parametros = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            return $acao($req);
        }

        throw new Erro(404, 'Rota não encontrada');
    }
}
