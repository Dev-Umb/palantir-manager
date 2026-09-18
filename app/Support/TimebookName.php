<?php

namespace App\Support;

class TimebookName
{
    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', \Normalizer::normalize($name, \Normalizer::FORM_KC)));
    }

    public static function key(string $name): string
    {
        return mb_convert_case(self::normalize($name), MB_CASE_FOLD, 'UTF-8');
    }
}
