<?php

namespace App;

class Validator
{
    public static function validate(string $url): array
    {
        $errors = [];

        if (empty($url)) {
            $errors[] = 'URL не должен быть пустым';
        }

        if (mb_strlen($url) > 255) {
            $errors[] = 'Адрес не должен превышать 255 символов.';
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Некорректный URL';
        }

        return $errors;
    }
}
