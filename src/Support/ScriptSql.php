<?php

declare(strict_types=1);

namespace EstudaFitHub\Support;

/**
 * Quebra um script SQL em comandos, respeitando `DELIMITER` (necessário para triggers).
 */
final class ScriptSql
{
    /** @return string[] */
    public static function comandos(string $sql): array
    {
        $delimitador = ';';
        $atual = '';
        $comandos = [];

        foreach (preg_split('/\R/', $sql) ?: [] as $linha) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $linha, $m)) {
                $delimitador = $m[1];
                continue;
            }
            if (preg_match('/^\s*--/', $linha)) {
                continue;
            }
            $atual .= $linha . "\n";
            while (($pos = strpos($atual, $delimitador)) !== false) {
                $comando = trim(substr($atual, 0, $pos));
                if ($comando !== '') {
                    $comandos[] = $comando;
                }
                $atual = substr($atual, $pos + strlen($delimitador));
            }
        }
        if (trim($atual) !== '') {
            $comandos[] = trim($atual);
        }

        return $comandos;
    }
}
