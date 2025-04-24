<?php

namespace App;

class Validator
{
    public static function validate(string $url): array
    {
        $errors = [];

        if (empty($url)) {
            $errors[] = 'Адрес обязателен для заполнения.';
        }

        if (mb_strlen($url) > 255) {
            $errors[] = 'Адрес не должен превышать 255 символов.';
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Некорректный формат URL.';
        }

        return $errors;
    }

    public static function normalize(string $url): string
    {
        $parsed = parse_url($url);

        if (!isset($parsed['scheme']) || !isset($parsed['host'])) {
            return $url; // Вернём как есть, если разбор невозможен
        }

        return strtolower($parsed['scheme']) . '://' . strtolower($parsed['host']);
    }
}
