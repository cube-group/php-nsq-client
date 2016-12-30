<?php
/**
 * Nsq api
 * User: moyo
 * Date: 5/7/15
 * Time: 3:45 PM
 */

namespace Kdt\Iron\Queue\Adapter\Nsq;

use Kdt\Iron\Queue\Interfaces\AdapterInterface;
use Kdt\Iron\Queue\Message;

use nsqphp\Message\Message as NsqMessage;
use nsqphp\Exception\RequeueMessageException;

use Exception as SysException;

class Client implements AdapterInterface
{
    /**
     * @var Config
     */
    private $config = null;

    /**
     * Client constructor.
     */
    public function __construct()
    {
        $this->config = Config::getInstance();
    }

    /**
     * @param $topic
     * @param $message
     * @param $options
     * @return array
     */
    public function push($topic, $message, array $options = [])
    {
        $result = HA::getInstance()->pubRetrying(function () use ($topic, $message) {

            return InstanceMgr::getPubInstance($topic)->publish(
                $this->config->parseTopicName($topic),
                MsgFilter::getInstance()->getMsgObject($topic, $message)
            );

        }, $topic, $options['max_retry'], $options['retry_delay_ms']);

        return $this->makePubResult($topic, $result);
    }

    /**
     * @param $topic
     * @param $messages
     * @param $options
     * @return array
     */
    public function bulk($topic, array $messages, array $options = [])
    {
        $result = HA::getInstance()->pubRetrying(function () use ($topic, $messages) {

            return InstanceMgr::getPubInstance($topic)->publish(
                $this->config->parseTopicName($topic),
                MsgFilter::getInstance()->getMsgObjectBag($topic, $messages)
            );

        }, $topic, $options['max_retry'], $options['retry_delay_ms']);

        return $this->makePubResult($topic, $result);
    }

    /**
     * @param $topic
     * @param callable $callback
     * @param $options
     * @return string
     */
    public function pop($topic, callable $callback, array $options = [])
    {
        // topic & channel
        if (is_array($topic))
        {
            list($topic, $channel) = $topic;
        }
        else
        {
            $channel = 'default';
        }

        $identify = $this->config->parseTopicName($topic).'-'.$channel;

        return HA::getInstance()->subRetrying(function ($maxKeepSeconds) use ($topic, $channel, $callback, $options) {

            InstanceMgr::getSubInstance($topic)->subscribe(
                Router::getInstance()->fetchSubscribeNodes($topic, $options['sub_partition']),
                $this->config->parseTopicName($topic), $channel,
                function (NsqMessage $msg) use ($callback)
                {
                    call_user_func_array($callback, [
                        (new Message(
                            $msg->getId(),
                            $msg->getTimestamp(),
                            $msg->getAttempts(),
                            $msg->getPayload()
                        ))
                            ->setTraceID($msg->getTraceId())
                    ]);
                },
                $options['auto_delete'],
                $options['sub_ordered']
            )
                ->run($maxKeepSeconds);

            return false;

        }, $identify, $options['keep_seconds'], $options['max_retry'], $options['retry_delay']);
    }

    /**
     * exiting pop
     */
    public function stop()
    {
        InstanceMgr::getSubInstance()->stop();
    }

    /**
     * @param $messageId
     * @return bool
     */
    public function delete($messageId)
    {
        return InstanceMgr::getSubInstance()->deleteMessage($messageId);
    }

    /**
     * make delay
     * @param $seconds
     */
    public function later($seconds)
    {
        throw new RequeueMessageException($seconds * 1000);
    }

    /**
     * make retry
     */
    public function retry()
    {
        throw new RequeueMessageException(1);
    }

    /**
     * close all connections
     */
    public function close()
    {
        InstanceMgr::getSubInstance()->close();
    }

    /**
     * @param $topic
     * @return array
     */
    public function stats($topic)
    {
        $res = [];

        try {

            $nsqd = InstanceMgr::getPubInstance($topic);

            $hosts = Router::getInstance()->fetchPublishNodes($topic);

            foreach ($hosts as $host) {
                $res[$host['host']] = $nsqd->node_stats($host['host']);
            }

        } catch (SysException $e) {

        }

        return $res;
    }

    /**
     * @param $topic
     * @param $result
     * @return array
     */
    private function makePubResult($topic, array $result)
    {
        // check result
        $error_code = -1;
        $error_message = '';
        if ($result['success'])
        {
            $error_code = 0;
        }
        else if ($result['errors'])
        {
            $error_code = 1;
            $error_message = implode('|', $result['errors']);
            // logging
            InstanceMgr::getLoggerInstance()->error('[IRON] Actual failed via (PUB) : ['.$topic.'] ~ ' . $error_message);
        }
        // return result
        return [
            'error_code' => $error_code,
            'error_message' => $error_message
        ];
    }
}