<?php

use App\Logging\CustomizeFormatter;
use App\Logging\DeepNormalizerTap;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'tap' => [CustomizeFormatter::class],
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'Laravel Log'),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        'shipment-import' => [
            'driver' => 'daily',
            'path' => storage_path('logs/shipment-import.log'),
            'level' => env('SHIPMENT_IMPORT_LOG_LEVEL', 'info'),
            'days' => 14,
            'replace_placeholders' => true,
        ],

        /*
        | Carrier validation channels log full API request/response payloads,
        | which contain recipient PII (names, addresses, phones). They must
        | stay disabled in production — Amazon's SP-API data protection policy
        | forbids logging buyer PII. Enable CARRIER_API_LOGGING only for
        | carrier certification runs or local debugging.
        */

        'fedex-validation' => env('CARRIER_API_LOGGING', false) ? [
            'driver' => 'single',
            'path' => storage_path('logs/fedex-validation.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
            'tap' => [DeepNormalizerTap::class],
        ] : [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'usps-validation' => env('CARRIER_API_LOGGING', false) ? [
            'driver' => 'single',
            'path' => storage_path('logs/usps-validation.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
            'tap' => [DeepNormalizerTap::class],
        ] : [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'ups-validation' => env('CARRIER_API_LOGGING', false) ? [
            'driver' => 'single',
            'path' => storage_path('logs/ups-validation.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
            'tap' => [DeepNormalizerTap::class],
        ] : [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

    ],

];
