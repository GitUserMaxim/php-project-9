<?php

namespace App;

use Valitron\Validator as ValitronValidator;

class Validator
{
    public static function validate(string $urlName): array
    {
        $v = new ValitronValidator(['url_name' => $urlName]);
        $v->rule('required', 'url_name')->message('Поле не может быть пустым');
        $v->rule('url', 'url_name')->message('Введите корректный URL');
        if ($v->validate()) {
            return [];
        }
        return $v->errors();
    }
}
