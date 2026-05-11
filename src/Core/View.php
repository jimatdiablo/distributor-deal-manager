<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $data = []): string
    {
        $data['csrfToken'] = $data['csrfToken'] ?? Auth::csrfToken();
        $data['csrfField'] = $data['csrfField'] ?? static fn(): string => Auth::csrfField();
        extract($data);
        ob_start();
        require __DIR__ . '/../../views/' . $view . '.php';
        return (string)ob_get_clean();
    }
}
