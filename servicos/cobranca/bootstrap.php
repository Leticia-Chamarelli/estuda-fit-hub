<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

spl_autoload_register(static function (string $classe): void {
    $prefixo = 'Cobranca\\';
    if (strncmp($classe, $prefixo, strlen($prefixo)) !== 0) {
        return;
    }
    $arquivo = __DIR__ . '/src/' . str_replace('\\', '/', substr($classe, strlen($prefixo))) . '.php';
    if (is_file($arquivo)) {
        require $arquivo;
    }
});
