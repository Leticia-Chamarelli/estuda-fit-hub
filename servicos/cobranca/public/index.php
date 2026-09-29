<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Cobranca\Aplicacao\Assinaturas;
use Cobranca\Aplicacao\Eventos;
use Cobranca\Aplicacao\Migracao;
use Cobranca\Aplicacao\Webhooks;
use Cobranca\Http\Erro;
use Cobranca\Http\Requisicao;
use Cobranca\Http\Resposta;
use Cobranca\Http\Rotas;
use Cobranca\Infra\Db;

$rotas = new Rotas();

$rotas->add('GET', '/health', static function (): Resposta {
    Db::um('SELECT 1');

    return new Resposta(200, ['status' => 'ok', 'servico' => 'cobranca']);
}, interna: false);

// Comandos do monólito (entregues pelo relay da outbox, idempotentes)
$rotas->add('POST', '/assinaturas', static function (Requisicao $r): Resposta {
    $res = (new Assinaturas())->criar($r->corpo);

    return new Resposta($res['criada'] ? 201 : 200, $res);
});
$rotas->add('POST', '/assinaturas/{matricula}/cancelamento', static fn (Requisicao $r): Resposta => new Resposta(
    200,
    (new Assinaturas())->cancelar((int) $r->parametros['matricula'], $r->campo('cancelada_em')),
));
$rotas->add('POST', '/assinaturas/{matricula}/faturas', static function (Requisicao $r): Resposta {
    $r->exigir(['competencia']);
    $res = (new Assinaturas())->gerarFatura((int) $r->parametros['matricula'], (string) $r->campo('competencia'));

    return new Resposta($res['criada'] ? 201 : 200, $res);
});

// Leitura
$rotas->add('GET', '/faturas', static fn (Requisicao $r): Resposta => new Resposta(200, ['faturas' => Db::todos(
    'SELECT * FROM faturas WHERE aluno_id = ? ORDER BY competencia DESC',
    [(int) ($r->query['aluno_id'] ?? 0)],
)]));
$rotas->add('GET', '/eventos', static fn (Requisicao $r): Resposta => new Resposta(
    200,
    Eventos::feed((int) ($r->query['apos'] ?? 0), (int) ($r->query['limite'] ?? 500)),
));

// Webhook do gateway (público)
$rotas->add('POST', '/webhooks/pagamento', static function (Requisicao $r): Resposta {
    $res = (new Webhooks())->receber($r->corpo);

    return new Resposta(str_starts_with($res['resultado'], 'duplicado') ? 200 : 202, $res);
}, interna: false);

// Migração
$rotas->add('POST', '/migracao/lotes', static fn (Requisicao $r): Resposta => new Resposta(200, (new Migracao())->importarLote($r->corpo)));
$rotas->add('GET', '/migracao/reconciliacao', static fn (Requisicao $r): Resposta => new Resposta(200, [
    'linhas' => (new Migracao())->reconciliacao((int) ($r->query['unidade_id'] ?? 0)),
]));
$rotas->add('GET', '/migracao/faturas', static fn (Requisicao $r): Resposta => new Resposta(200, [
    'faturas' => (new Migracao())->faturasDaUnidade((int) ($r->query['unidade_id'] ?? 0)),
]));
$rotas->add('POST', '/migracao/unidades/{unidade}/devolucao', static fn (Requisicao $r): Resposta => new Resposta(200, [
    'assinaturas_devolvidas' => (new Migracao())->devolver((int) $r->parametros['unidade']),
]));

$inicio = microtime(true);
try {
    $resposta = $rotas->despachar(Requisicao::daGlobal());
} catch (Erro $e) {
    $resposta = new Resposta($e->status, ['erro' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log(sprintf('[erro] %s: %s em %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    $resposta = new Resposta(500, ['erro' => 'Erro interno']);
}
$resposta->enviar();
error_log(sprintf('[req] %s %s -> %d (%.0f ms)', $_SERVER['REQUEST_METHOD'] ?? '-', $_SERVER['REQUEST_URI'] ?? '-', $resposta->status, (microtime(true) - $inicio) * 1000));
