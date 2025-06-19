<?php

namespace App;

class UrlNormalizer
{
    public static function normalize(string $url): string
    {
        $components = parse_url($url);
        $scheme = mb_strtolower($components['scheme']);
        $host = mb_strtolower($components['host']);

        return "$scheme://$host";
    }
}
