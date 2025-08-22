<?php

namespace Vblinden\WhatsWrong;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
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
            ->name('whatswrong')
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
            'line_preview' => base64_encode(json_encode(ExceptionContext::get($exception))),
            'project_id' => config('whatswrong.project_id'),
        ];

        try {
            $this->sendAsyncRequest($data);
        } catch (Throwable) {
            // Do nothing.
        }
    }

    private function sendAsyncRequest(array $data): void
    {
        $url = sprintf('%s/ingest/exception', config('whatswrong.url', 'https://whatswrong.dev'));
        $jsonData = json_encode($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: '.strlen($jsonData),
        ]);

        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);

        curl_exec($ch);
        curl_close($ch);
    }

    private function shouldIgnore($event): bool
    {
        if (config('whatswrong.project_id') === null) {
            return true;
        }

        return ! isset($event->context['exception']) ||
            ! $event->context['exception'] instanceof Throwable;
    }
}
