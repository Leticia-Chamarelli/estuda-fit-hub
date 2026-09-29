<?php

declare(strict_types=1);

namespace Cobranca\Infra;

use PDO;
use Throwable;

/**
 * Acesso ao banco próprio do serviço (COBRANCA_DB_*). Não existe configuração para o banco
 * do monólito aqui, de propósito.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('COBRANCA_DB_HOST') ?: '127.0.0.1',
                getenv('COBRANCA_DB_PORT') ?: '3306',
                getenv('COBRANCA_DB_NAME') ?: 'cobranca',
            );
            self::$pdo = new PDO($dsn, getenv('COBRANCA_DB_USER') ?: 'cobranca', getenv('COBRANCA_DB_PASS') ?: 'cobranca', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$pdo;
    }

    public static function todos(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public static function um(string $sql, array $params = []): ?array
    {
        return self::todos($sql, $params)[0] ?? null;
    }

    public static function executar(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function inserir(string $tabela, array $dados, bool $ignorarDuplicado = false): int
    {
        $colunas = array_keys($dados);
        $inseridas = self::executar(
            sprintf(
                'INSERT %s INTO `%s` (%s) VALUES (%s)',
                $ignorarDuplicado ? 'IGNORE' : '',
                $tabela,
                implode(', ', array_map(static fn (string $c): string => "`{$c}`", $colunas)),
                implode(', ', array_fill(0, count($colunas), '?')),
            ),
            array_values($dados),
        );

        return $inseridas === 0 ? 0 : (int) self::pdo()->lastInsertId();
    }

    public static function transacao(callable $fn): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();

            return $r;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
