<?php

namespace Sentry\Laravel\Tracing;

use Closure;
use Illuminate\Contracts\Foundation\Application as LaravelApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Lumen\Application as LumenApplication;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\TransactionSource;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function Sentry\continueTrace;

/**
 * @internal
 */
class Middleware
{
    /**
     * The current active transaction.
     *
     * @var \Sentry\Tracing\Transaction|null
     */
    protected $transaction;

    /**
     * The span for the `app.handle` part of the application.
     *
     * @var \Sentry\Tracing\Span|null
     */
    protected $appSpan;

    /**
     * The timestamp of application bootstrap completion.
     *
     * @var float|null
     */
    private $bootedTimestamp;

    /**
     * The Laravel or Lumen application instance.
     *
     * @var LaravelApplication|LumenApplication
     */
    private $app;

    /**
     * Whether we should continue tracing after the response has been sent to the client.
     *
     * @var bool
     */
    private $continueAfterResponse;

    /**
     * Whether the terminating callback has been registered.
     *
     * @var bool
     */
    private $registeredTerminatingCallback = false;

    /**
     * Whether a defined route was matched in the application.
     *
     * @var bool
     */
    private $didRouteMatch = false;

    /**
     * Construct the Sentry tracing middleware.
     *
     * @param LaravelApplication|LumenApplication $app
     */
    public function __construct($app, bool $continueAfterResponse = true)
    {
        $this->app = $app;
        $this->continueAfterResponse = $continueAfterResponse;
    }

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($this->app->bound(HubInterface::class)) {
            $this->startTransaction($request, $this->app->make(HubInterface::class));
        }

        return $next($request);
    }

    /**
     * Handle the application termination.
     *
     * @param \Illuminate\Http\Request $request
     * @param mixed                    $response
     *
     * @return void
     */
    public function terminate(Request $request, $response): void
    {
        // If there is no transaction or the HubInterface is not bound in the container there is nothing for us to do
        if ($this->transaction === null || !$this->app->bound(HubInterface::class)) {
            return;
        }

        if ($this->shouldRouteBeIgnored($request)) {
            return;
        }

        if ($this->appSpan !== null) {
            $this->appSpan->finish();
            $this->appSpan = null;
        }

        if ($response instanceof SymfonyResponse) {
            $this->hydrateResponseData($response);
        }

        if ($this->continueAfterResponse) {
            // Ensure we do not register the terminating callback multiple times since there is no point in doing so
            if ($this->registeredTerminatingCallback) {
                return;
            }

            // We need to finish the transaction after the response has been sent to the client
            // so we register a terminating callback to do so, this allows us to also capture
            // spans that are created during the termination of the application like queue
            // dispatched using dispatch(...)->afterResponse(). This middleware is called
            // before the terminating callbacks so we are 99.9% sure to be the last one
            // to run except if another terminating callback is registered after ours.
            $this->app->terminating(function () {
                $this->finishTransaction();
            });

            $this->registeredTerminatingCallback = true;
        } else {
            $this->finishTransaction();
        }
    }

    /**
     * Set the timestamp of application bootstrap completion.
     *
     * @param float|null $timestamp The unix timestamp of the booted event, default to `microtime(true)` if not `null`.
     *
     * @return void
     *
     * @internal This method should only be invoked right after the application has finished "booting".
     */
    public function setBootedTimestamp(?float $timestamp = null): void
    {
        $this->bootedTimestamp = $timestamp ?? microtime(true);
    }

    private function startTransaction(Request $request, HubInterface $sentry): void
    {
        // Reset our internal state in case we are handling multiple requests (e.g. in Octane)
        $this->didRouteMatch = false;

        // Try $_SERVER['REQUEST_TIME_FLOAT'] then LARAVEL_START and fallback to microtime(true) if neither are defined
        $requestStartTime = $request->server(
            'REQUEST_TIME_FLOAT',
            defined('LARAVEL_START')
                ? LARAVEL_START
                : microtime(true)
        );

        $context = continueTrace(
            $request->header('sentry-trace') ?? $request->header('traceparent', ''),
            $request->header('baggage', '')
        );

        $requestPath = '/' . ltrim($request->path(), '/');

        $context->setOp('http.server');
        $context->setName($requestPath);
        $context->setSource(TransactionSource::url());
        $context->setStartTimestamp($requestStartTime);

        $context->setData([
            'url' => $requestPath,
            'http.request.method' => strtoupper($request->method()),
        ]);

        $transaction = $sentry->startTransaction($context);

        // If this transaction is not sampled, we can stop here to prevent doing work for nothing
        if (!$transaction->getSampled()) {
            return;
        }

        $this->transaction = $transaction;

        SentrySdk::getCurrentHub()->setSpan($this->transaction);

        $bootstrapSpan = $this->addAppBootstrapSpan();

        $appContextStart = new SpanContext;
        $appContextStart->setOp('middleware.handle');
        $appContextStart->setStartTimestamp($bootstrapSpan ? $bootstrapSpan->getEndTimestamp() : microtime(true));

        $this->appSpan = $this->transaction->startChild($appContextStart);

        SentrySdk::getCurrentHub()->setSpan($this->appSpan);
    }

    private function addAppBootstrapSpan(): ?Span
    {
        if ($this->bootedTimestamp === null) {
            return null;
        }

        $spanContextStart = new SpanContext;
        $spanContextStart->setOp('app.bootstrap');
        $spanContextStart->setStartTimestamp($this->transaction->getStartTimestamp());
        $spanContextStart->setEndTimestamp($this->bootedTimestamp);

        $span = $this->transaction->startChild($spanContextStart);

        // Consume the booted timestamp, because we don't want to report the bootstrap span more than once
        $this->bootedTimestamp = null;

        // Add more information about the bootstrap section if possible
        $this->addBootDetailTimeSpans($span);

        return $span;
    }

    private function addBootDetailTimeSpans(Span $bootstrap): void
    {
        // This constant should be defined right after the composer `autoload.php` require statement in `public/index.php`
        // define('SENTRY_AUTOLOAD', microtime(true));
        if (!defined('SENTRY_AUTOLOAD') || !SENTRY_AUTOLOAD) {
            return;
        }

        $autoload = new SpanContext;
        $autoload->setOp('app.php.autoload');
        $autoload->setStartTimestamp($this->transaction->getStartTimestamp());
        $autoload->setEndTimestamp(SENTRY_AUTOLOAD);

        $bootstrap->startChild($autoload);
    }

    private function hydrateResponseData(SymfonyResponse $response): void
    {
        $this->transaction->setHttpStatus($response->getStatusCode());
    }

    private function finishTransaction(): void
    {
        // We could end up multiple times here since we register a terminating callback so
        // double check if we have a transaction before trying to finish it since it could
        // have already been finished in between being registered and being executed again
        if ($this->transaction === null) {
            return;
        }

        // If window mode is set the decision to sample will be based on whether the performance
        // falls within the specified range in the window of time and increments by the specified value.
        if ((config('sentry.tracing.window_mode', false) === true) && !$this->getWindowModeSampled()) {
            $this->transaction->setSampled(false);
        }

        // Make sure we set the transaction and not have a child span in the Sentry SDK
        // If the transaction is not on the scope during finish, the trace.context is wrong
        SentrySdk::getCurrentHub()->setSpan($this->transaction);

        $this->transaction->finish();
        $this->transaction = null;
    }

    private function internalSignalRouteWasMatched(): void
    {
        $this->didRouteMatch = true;
    }

    /**
     * Indicates if the route should be ignored and the transaction discarded.
     */
    private function shouldRouteBeIgnored(Request $request): bool
    {
        // Laravel Lumen doesn't use `illuminate/routing`.
        // Instead we use the route available on the request to detect if a route was matched.
        if ($this->app instanceof LumenApplication) {
            return $request->route() === null && config('sentry.tracing.missing_routes', false) === false;
        }

        // If a route has not been matched we ignore unless we are configured to trace missing routes
        return !$this->didRouteMatch && config('sentry.tracing.missing_routes', false) === false;
    }

    public static function signalRouteWasMatched(): void
    {
        if (!app()->bound(self::class)) {
            return;
        }

        app(self::class)->internalSignalRouteWasMatched();
    }

    protected function getWindowModeSampled(): bool
    {
        $name = 'SENTRY_WINDOW_' . $this->transaction->getName();
        $sampled = false;

        $min = config('sentry.tracing.window_min_ms', 0);
        $max = config('sentry.tracing.window_max_ms', 60000);
        $step = config('sentry.tracing.window_step_ms', 500);
        if (!Cache::has($name)) {
            $sampled = true;
            $lastPerformance = -$step;
        } else {
            $lastPerformance = Cache::get($name) ?? 0;
        }

        $performance = microtime(true) * 1000 - $this->transaction->getStartTimestamp() * 1000;
        if ($performance < $lastPerformance + $step || $performance < $min || $performance > $max) {
            return $sampled;
        }
        $sampled = true;

        Cache::put($name, $performance, $this->getRemainSecondsInCurrentWindow());
        return $sampled;
    }

    protected function getRemainSecondsInCurrentWindow(): int
    {
        $curMinutes = date('i');
        $window = config('sentry.tracing.window_minutes', 2);
        $seconds = $window * 60 - 1 - date('s') - (60 * ($curMinutes % $window));
        if ($seconds < 1) {
            $seconds = 1;
        }
        return $seconds;
    }
}
