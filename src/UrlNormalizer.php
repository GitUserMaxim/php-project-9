<?php

namespace App;

class UrlNormalizer
{
    public static function normalize(string $url): string
    {
        $components = parse_url($url);
        $scheme = $components['scheme'];
        $host = $components['host'];

        return "$scheme://$host";
    }
}
