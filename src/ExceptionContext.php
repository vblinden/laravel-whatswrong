<?php

namespace Vblinden\WhatsWrong;

use Illuminate\Support\Str;
use Throwable;

class ExceptionContext
{
    public static function get(Throwable $exception): array
    {
        return static::getEvalContext($exception) ??
            static::getFileContext($exception);
    }

    protected static function getEvalContext(Throwable $exception)
    {
        if (Str::contains($exception->getFile(), "eval()'d code")) {
            return [
                $exception->getLine() => "eval()'d code",
            ];
        }
    }

    protected static function getFileContext(Throwable $exception): array
    {
        return collect(explode("\n", file_get_contents($exception->getFile())))
            ->slice($exception->getLine() - 10, 20)
            ->mapWithKeys(function ($value, $key) {
                return [$key + 1 => $value];
            })->all();
    }
}
