<?php

namespace Vblinden\WhatsWrong\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Vblinden\WhatsWrong\WhatsWrong
 */
class WhatsWrong extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vblinden\WhatsWrong\WhatsWrong::class;
    }
}
