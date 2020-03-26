<?php

namespace Codeception\Extension;

use Codeception\Events;
use Codeception\Exception\ExtensionException;
use Codeception\Extension;
use Maknz\Slack\Client;
use Maknz\Slack\Message;
use PHPUnit\Framework\TestFailure;
use PHPUnit\Framework\TestResult;

/**
 * This extension sends the test results to Slack channels.
 *
 * To use this extension a valid Slack Webhook URL is required.
 *
 * Configuration 'codeception.yaml' example:
 *
 *      extensions:
 *        enabled:
 *          - SlackExtension
 *        config:
 *          SlackExtension:
 *            webhook: 'https://hooks.slack.com/services/...'
 */
class SlackExtension extends Extension
{
    const STRATEGY_ALWAYS = 'always';
    const STRATEGY_FAIL_ONLY = 'failonly';
    const STRATEGY_FAIL_AND_RECOVER = 'failandrecover';
    const STRATEGY_STATUS_CHANGE = 'statuschange';
    const STRATEGY_SUCCESS_ONLY = 'successonly';
    /**
     * @var array list events to listen to
     */
    public static $events = array(
        Events::RESULT_PRINT_AFTER => 'sendTestResults',
    );

    /**
     * @var Message
     */
    protected $message;

    /**
     * @var string
     */
    protected $messagePrefix;

    /**
     * @var string
     */
    protected $messageSuffix = '';

    /**
     * @var string
     */
    protected $messageSuffixOnFail = '';

    /**
     * @var array Array of slack channels
     */
    protected $channels = [];

    /**
     * @var array Array of slack channels for the special case of failure.
     *            Defined by Codeception config "channelOnFail".
     *            This Codeception configuration is optional, otherwise "channel" will be used.
     */
    protected $channelOnFail;

    /**
     * @var boolean If set to true notifications will be send only when at least one test fails.
     */
    protected $strategy = self::STRATEGY_ALWAYS;

    protected $strategies = array(
        self::STRATEGY_ALWAYS,
        self::STRATEGY_FAIL_ONLY,
        self::STRATEGY_FAIL_AND_RECOVER,
        self::STRATEGY_STATUS_CHANGE,
        self::STRATEGY_SUCCESS_ONLY
    );

    /**
     * @var bool Whether or not to yet extended details about failed tests.
     */
    protected $extended = false;

    /**
     * @var bool
     */
    protected $lastRunFailed;

    /**
     * @var \Maknz\Slack\Client
     */
    protected $client;

    /**
     * @var int Maximum length for error messages to be displayed in extended mode.
     */
    protected $extendedMaxLength = 80;

    /**
     * @var int|null The maximum amount of errors to send
     */
    protected $extendedMaxErrors = 0;

    /**
     * Setup Slack client and message object.
     *
     * @throws ExtensionException in case required configuration for 'webhook' is missing
     */
    public function _initialize()
    {
        if (!isset($this->config['webhook']) or empty($this->config['webhook'])) {
            throw new ExtensionException($this, "configuration for 'webhook' is missing");
        }

        $this->client = new Client($this->config['webhook']);

        if (isset($this->config['channel'])) {
            if (true === empty($this->config['channel'])) {
                throw new ExtensionException(
                    $this, "SlackExtension: The specified value for key \"channel\" must not be empty."
                );
            }
            $this->channels = explode(',', $this->config['channel']);
        }

        if (isset($this->config['username'])) {
            $this->client->setDefaultUsername($this->config['username']);
        }

        if (isset($this->config['icon'])) {
            $this->client->setDefaultIcon($this->config['icon']);
        }

        if (isset($this->config['messagePrefix'])) {
            $this->messagePrefix = $this->config['messagePrefix'] . ' ';
        }

        if (isset($this->config['messageSuffix'])) {
            $messageSuffix = $this->config['messageSuffix'];

            if (substr($this->config['messageSuffix'], 0, 1) === '"') {
                $messageSuffix = substr($messageSuffix, 1);
            }

            if (substr($this->config['messageSuffix'], -1) === '"') {
                $messageSuffix = substr($messageSuffix, 0, strlen($messageSuffix) - 1);
            }

            $this->messageSuffix = ' ' . $messageSuffix;
        }

        if (isset($this->config['messageSuffixOnFail'])) {
            $this->messageSuffixOnFail = ' ' . $this->config['messageSuffixOnFail'];
        }
        if (isset($this->config['channelOnFail'])) {
            if (true === empty($this->config['channelOnFail'])) {
                throw new ExtensionException(
                    $this, "SlackExtension: The specified value for key \"channelOnFail\" must not be empty."
                );
            }
            $this->channelOnFail = explode(',', $this->config['channelOnFail']);
        }

        if (isset($this->config['strategy'])) {
            if (false === in_array(
                    $this->config['strategy'],
                    $this->strategies
                )
            ) {
                throw new ExtensionException(
                    $this,
                    '"' . $this->config['strategy'] . '" is not a valid Slack notification "strategy".'
                    . ' Possible values are: ' . PHP_EOL
                    . implode(',', $this->strategies)
                );
            }
            $this->strategy = $this->config['strategy'];
        }

        if (isset($this->config['extended'])
            && (true === $this->config['extended'] || 'true' === $this->config['extended'])
        ) {
            $this->extended = true;
        }

        if (isset($this->config['extendedMaxErrors'])) {
            $this->extendedMaxErrors = max((int) $this->config['extendedMaxErrors'], 0);
        }

        if (isset($this->config['extendedMaxLength'])) {
            $this->extendedMaxLength = intval($this->config['extendedMaxLength']);
        }

        $this->lastRunFailed = $this->hasLastRunFailed();

        $this->message = $this->client->createMessage();
    }

    /**
     * Sends test results to Slack channels.
     *
     * This method is fired when the event 'result.print.after' occurs.
     * @param \Codeception\Event\PrintResultEvent $e
     */
    public function sendTestResults(\Codeception\Event\PrintResultEvent $e)
    {
        if (is_null($this->client)) {
            return;
        }

        $result = $e->getResult();

        if ($result->wasSuccessful()) {

            if (self::STRATEGY_ALWAYS === $this->strategy
                || self::STRATEGY_SUCCESS_ONLY === $this->strategy
                || ($this->lastRunFailed && $this->strategy === self::STRATEGY_FAIL_AND_RECOVER)
                || ($this->lastRunFailed && $this->strategy === self::STRATEGY_STATUS_CHANGE)
            ) {
                $this->sendSuccessMessage($result);
            }

        } else {

            if (self::STRATEGY_ALWAYS === $this->strategy
                || self::STRATEGY_FAIL_ONLY === $this->strategy
                || self::STRATEGY_FAIL_AND_RECOVER === $this->strategy
                || ($this->strategy === self::STRATEGY_STATUS_CHANGE && false === $this->lastRunFailed)
            ) {
                $this->sendFailMessage($result);
            }
        }
    }

    /**
     * Sends success message to Slack channels.
     *
     * @param TestResult $result
     */
    private function sendSuccessMessage(TestResult $result)
    {
        $numberOfTests = $result->count();

        foreach ($this->channels as $channel) {
            $this->message->setChannel(trim($channel));
            $this->message->send(
                ':white_check_mark: '
                . $this->messagePrefix
                . $numberOfTests . ' of ' . $numberOfTests . ' tests passed.'
                . str_replace('\\n', PHP_EOL, $this->messageSuffix)
            );
        }
    }

    /**
     * Sends fail message to Slack channels.
     *
     * @param TestResult $result
     */
    private function sendFailMessage(TestResult $result)
    {
        $numberOfTests = $result->count();
        $numberOfFailedTests = $result->failureCount() + $result->errorCount();

        if (true === $this->extended) {
            $this->attachExtendedInformation($this->message, $result);
        }

        $targetChannels = (is_array($this->channelOnFail) && count($this->channelOnFail) > 0) ?
            $this->channelOnFail : $this->channels;

        foreach ($targetChannels as $channel) {
            $this->message->setChannel(trim($channel));

            $this->message->send(
                ':interrobang: '
                . $this->messagePrefix
                . $numberOfFailedTests . ' of ' . $numberOfTests . ' tests failed.'
                . str_replace('\\n', PHP_EOL, $this->messageSuffix)
                . $this->messageSuffixOnFail
            );
        }
    }

    /**
     *
     * @param TestResult $result
     */
    private function attachExtendedInformation(Message &$message, TestResult $result) {
        $fields = [];
        $failures = array_merge($result->failures(), $result->errors());
        $omittedFailures = 0;
        if ($this->extendedMaxErrors > 0) {
            $omittedFailures = count($failures) - $this->extendedMaxErrors;
            $failures = array_slice($failures, 0, $this->extendedMaxErrors);
        }

        foreach ($failures as $failure) {
            /**
             * @var $failure TestFailure
             */
            $exceptionMsg = strtok($failure->exceptionMessage(), "\n");

            $result = json_decode($exceptionMsg);

            if (json_last_error() === JSON_ERROR_NONE && isset($result->errorMessage)) {
                $exceptionMsg = $result->errorMessage;
            }

            if ($this->extendedMaxLength > 0  && strlen($exceptionMsg) > $this->extendedMaxLength) {
                $exceptionMsg =  substr($exceptionMsg, 0, $this->extendedMaxLength) . ' ...';
            }

            $fields[] = [
                'title' => $failure->getTestName(),
                'value' => $exceptionMsg
            ];
        }

        if ($omittedFailures > 0) {
            $fields[] = [
                'title' => sprintf('%d other tests...', $omittedFailures),
                'value' => '',
            ];
        }

        $message->attach([
            'color' => 'danger',
            'fields' => $fields
        ]);
    }

    private function hasLastRunFailed()
    {
        return is_file($this->getLogDir() . 'failed');
    }
}
