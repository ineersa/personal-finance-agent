<?php

namespace App\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsMonologProcessor]
final class SafeContextProcessor
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = [];
        foreach (['correlation_id', 'status_code', 'duration_ms', 'exception_class'] as $key) {
            $value = $record->context[$key] ?? null;
            if (is_scalar($value)) {
                $context[$key] = $value;
            }
        }
        $correlation = $this->requests->getMainRequest()?->attributes->get('_correlation_id');
        if (is_string($correlation)) {
            $context['correlation_id'] = $correlation;
        }
        $message = in_array($record->message, ['http.request.started', 'http.request.completed', 'http.request.failed'], true) ? $record->message : 'framework.record';

        return $record->with(message: $message, context: $context, extra: []);
    }
}
