<?php

namespace Vblinden\WhatsWrong;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

class WhatsWrongServiceProvider extends PackageServiceProvider
{
    public function register(): void
    {
        parent::register();

        app('events')->listen(MessageLogged::class, [$this, 'handleMessageLogged']);
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('whatswrong-laravel')
            ->hasConfigFile();
    }

    public function handleMessageLogged(MessageLogged $event): void
    {
        if ($this->shouldIgnore($event)) {
            return;
        }

        $data = [];

        try {
            if (Auth::hasResolvedGuards() && Auth::hasUser()) {
                $data['user'] = [
                    'id' => Auth::user()->getAuthIdentifier(),
                    'name' => Auth::user()->name ?? null,
                    'email' => Auth::user()->email ?? null,
                ];
            }
        } catch (Throwable) {
            // Do nothing.
        }

        $exception = $event->context['exception'];
        $trace = collect($exception->getTrace())->map(function ($item) {
            return Arr::only($item, ['file', 'line']);
        })->toArray();

        $data = [
            ...$data,
            'hostname' => gethostname(),
            'class' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'message' => $exception->getMessage(),
            'context' => transform(Arr::except($event->context, ['exception']), function ($context) {
                return ! empty($context) ? $context : null;
            }),
            'trace' => $trace,
            'line_preview' => ExceptionContext::get($exception),
            'project_id' => config('whatswrong.project_id') ?? throw new \Exception('What\'s Wrong project ID is not set. Please set the WHATSWRONG_PROJECT_ID environment variable.'),
        ];

        try {
            Http::post(sprintf('%s/ingest/exception', config('whatswrong.url', 'https://whatswrong.dev')), $data);
        } catch (Throwable) {
            // Do nothing.
        }
    }

    private function shouldIgnore($event): bool
    {
        return ! isset($event->context['exception']) ||
            ! $event->context['exception'] instanceof Throwable;
    }
}
