<?php
declare(strict_types=1);

namespace OlegTsvetkov\Yii2\Sentry;

use Sentry\Severity;
use Sentry\State\Scope;
use yii\di\Instance;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target;

class LogTarget extends Target
{
    /**
     * Name of the Sentry component to pass logs
     *
     * @var Component
     */
    public $component = 'sentry';

    public function init()
    {
        parent::init();

        $this->component = Instance::ensure($this->component, Component::class);
    }

    /**
     * @inheritDoc
     */
    public function export()
    {
        // Get primary message source
        $primaryMessage = array_reduce($this->messages, function ($message, $next) {
            // First iteration `$record` is null
            if (null === $message) {
                return $next;
            }

            // We need the most latest message with the most lowest level
            if ($next[1] <= $message[1]) {
                return $next;
            }

            return $message;
        });

        // Collect other logs, that appeared in request
        $logs = [];
        foreach ($this->messages as $message) {
            if ($message === $primaryMessage) {
                continue;
            }

            $logs[] = $this->formatMessage($message);
        }

        // Configure scope and capture error
        $this->component->getHub()->withScope(function (Scope $scope) use ($primaryMessage, $logs) {
            [$body, $level, $category, $timestamp] = $primaryMessage;

            if (!empty($category)) {
                $scope->setTag('category', $category);
            }

            $scope->setExtra('logs', [] === $logs ? 'N/A' : $logs);

            if ($body instanceof \Throwable) {
                $this->component->getHub()->captureException($body);
            } else {
                $scope->setExtra('real_body', $body);
                $scope->setExtra('real_time', $this->getTime($timestamp));

                if (isset($primaryMessage[4])) {
                    $traces = [];

                    foreach ($primaryMessage[4] as $trace) {
                        $traces[] = "in {$trace['file']}:{$trace['line']}";
                    }

                    $scope->setExtra('traces', $traces);
                } else {
                    $scope->setExtra('traces', 'N/A');
                }

                if (is_string($body)) {
                    $text = $body;
                } else {
                    $text = VarDumper::export($body);
                }

                $this->component->getHub()->captureMessage($text, $this->getSeverityLevel($level));
            }
        });
    }

    /**
     * @inheritDoc
     */
    protected function getContextMessage()
    {
        // Mute context
        return '';
    }

    protected function getSeverityLevel($yiiLogLevel): ?Severity
    {
        switch ($yiiLogLevel) {
            case Logger::LEVEL_PROFILE_END:
            case Logger::LEVEL_PROFILE_BEGIN:
            case Logger::LEVEL_PROFILE:
            case Logger::LEVEL_TRACE:
                return Severity::debug();
            case Logger::LEVEL_INFO:
                return Severity::info();
            case Logger::LEVEL_WARNING:
                return Severity::warning();
            case Logger::LEVEL_ERROR:
                return Severity::error();
            default:
                throw new \UnexpectedValueException("An unsupported Yii's log level \"{$yiiLogLevel}\" given.");
        }
    }

    protected function getTime($timestamp)
    {
        $parent = parent::getTime($timestamp);

        return $parent . ' ' . \Yii::$app->timeZone;
    }
}
