<?php

namespace Vblinden\WhatsWrong;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WhatsWrongServiceProvider extends PackageServiceProvider
{
    private $batchId;

    private array $hiddenRequestParameters = [
        'password',
        'password_confirmation',
    ];

    private array $hiddenResponseParameters = [];

    public function register(): void
    {
        parent::register();

        $this->batchId = Str::orderedUuid()->toString();

        app('events')->listen(MessageLogged::class, [$this, 'handleMessageLogged']);
        app('events')->listen(RequestHandled::class, [$this, 'handleRequestHandled']);
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('whatswrong')
            ->hasConfigFile();
    }

    public function handleMessageLogged(MessageLogged $event): void
    {
        if (! $this->isRecording() || $this->shouldIgnore($event)) {
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
            'type' => 'exception',
            'batch_id' => $this->batchId,
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

    public function handleRequestHandled(RequestHandled $event): void
    {
        if (! $this->isRecording() || $event->response->getStatusCode() !== 500) {
            return;
        }

        $startTime = defined('LARAVEL_START') ? LARAVEL_START : $event->request->server('REQUEST_TIME_FLOAT');

        $data = [
            'type' => 'request',
            'batch_id' => $this->batchId,
            'ip_address' => $event->request->ip(),
            'uri' => str_replace($event->request->root(), '', $event->request->fullUrl()) ?: '/',
            'method' => $event->request->method(),
            'controller_action' => optional($event->request->route())->getActionName(),
            'middleware' => array_values(optional($event->request->route())->gatherMiddleware() ?? []),
            'headers' => $this->headers($event->request->headers->all()),
            'payload' => $this->payload($this->input($event->request)),
            'session' => $this->payload($this->sessionVariables($event->request)),
            'response_headers' => $this->headers($event->response->headers->all()),
            'response_status' => $event->response->getStatusCode(),
            'response' => $this->response($event->response),
            'duration' => $startTime ? floor((microtime(true) - $startTime) * 1000) : null,
            'memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ];

        try {
            $this->sendAsyncRequest($data);
        } catch (Throwable) {
            // Do nothing.
        }
    }

    private function payload($payload)
    {
        if (is_string($payload)) {
            return $payload;
        }

        return $this->hideParameters($payload,
            $this->hiddenRequestParameters
        );
    }

    private function headers($headers)
    {
        $headers = collect($headers)
            ->map(fn ($header) => implode(', ', $header))
            ->all();

        return $this->hideParameters($headers,
            $this->hiddenRequestParameters
        );
    }

    private function hideParameters($data, $hidden)
    {
        foreach ($hidden as $parameter) {
            if (Arr::get($data, $parameter)) {
                Arr::set($data, $parameter, '********');
            }
        }

        return $data;
    }

    private function sessionVariables(Request $request)
    {
        return $request->hasSession() ? $request->session()->all() : [];
    }

    private function input(Request $request)
    {
        if (Str::startsWith(strtolower($request->headers->get('Content-Type') ?? ''), 'text/plain')) {
            return (string) $request->getContent();
        }

        $files = $request->files->all();

        array_walk_recursive($files, function (&$file) {
            $file = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->isFile() ? ($file->getSize() / 1000).'KB' : '0',
            ];
        });

        return array_replace_recursive($request->input(), $files);
    }

    private function response(Response $response)
    {
        $content = $response->getContent();

        if (is_string($content)) {
            if (is_array(json_decode($content, true)) &&
                json_last_error() === JSON_ERROR_NONE) {
                return $this->contentWithinLimits($content)
                        ? $this->hideParameters(json_decode($content, true), $this->hiddenResponseParameters)
                        : 'Purged';
            }

            if (Str::startsWith(strtolower($response->headers->get('Content-Type') ?? ''), 'text/plain')) {
                return $this->contentWithinLimits($content) ? $content : 'Purged';
            }
        }

        if ($response instanceof RedirectResponse) {
            return 'Redirected to '.$response->getTargetUrl();
        }

        if ($response instanceof IlluminateResponse && $response->getOriginalContent() instanceof View) {
            return [
                'view' => $response->getOriginalContent()->getPath(),
                'data' => $this->extractDataFromView($response->getOriginalContent()),
            ];
        }

        if (is_string($content) && empty($content)) {
            return 'Empty Response';
        }

        return 'HTML Response';
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
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);

        $response = curl_exec($ch);
        $decodedResponse = json_decode($response, true);
        $sharedResponse = $decodedResponse === null ? $response : $decodedResponse;

        app()->instance('whatswrong.response', $sharedResponse);
        View::share('whatswrong', $sharedResponse);

        curl_close($ch);
    }

    private function contentWithinLimits($content)
    {
        $limit = $this->options['size_limit'] ?? 64;

        return intdiv(mb_strlen($content), 1000) <= $limit;
    }

    private function extractDataFromView($view)
    {
        return collect($view->getData())->map(function ($value) {
            if ($value instanceof Model) {
                return FormatModel::given($value);
            } elseif (is_object($value)) {
                return [
                    'class' => get_class($value),
                    'properties' => json_decode(json_encode($value), true),
                ];
            } else {
                return json_decode(json_encode($value), true);
            }
        })->toArray();
    }

    private function isRecording(): bool
    {
        return config('whatswrong.project_id') !== null;
    }

    private function shouldIgnore($event): bool
    {
        return ! isset($event->context['exception']) ||
            ! $event->context['exception'] instanceof Throwable;
    }
}
