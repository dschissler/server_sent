<?php
namespace Webird\Http;

use ZMQ;
use Phalcon\DiInterface;
use Phalcon\Di\InjectionAwareInterface;
use React\EventLoop\Factory as EventLoopFactory;
use React\ZMQ\Context as ReactZMQContent;
use Webird\Http\ServerSent\Event;
use Webird\Http\ServerSent\Exception;

/**
 *
 */
class ServerSent implements InjectionAwareInterface
{

    /**
     *
     */
    private $di;

    /**
     *
     */
    private $isRunning;

    /**
     *
     */
    private $loop;

    /**
     *
     */
    private $keepAlive;

    /**
     *
     */
    private $retryDelay;

    /**
     *
     */
    private $lastTime;

    /**
     *
     */
    private $listener;

    /**
     *
     */
    private $channels;

    /**
     *
     */
    public function __construct(array $options = [])
    {
        if (array_key_exists('keepAlive', $options)) {
            $keepAlive = $options['keepAlive'];
            if (!is_int($keepAlive) && !is_float($keepAlive)) {
                throw new Exception('keepAlive value must be a number.');
            }
            if ($keepAlive <= 0) {
                throw new Exception('keepAlive must be great than 0.');
            }
            $this->keepAlive = $keepAlive;
        }

        if (array_key_exists('retryDelay', $options)) {
            $retryDelay = $options['retryDelay'];
            if (!is_int($retryDelay)) {
                throw new Exception('retryDelay value must be an integer.');
            }
            $this->retryDelay = $retryDelay;
        }

        $this->isRunning = false;
        $this->channels = [];

        $this->loop = EventLoopFactory::create();
    }

    /**
     *
     */
    public function setDI(DiInterface $di)
    {
        $this->di = $di;
    }

    /**
     *
     */
    public function getDI()
    {
        return $this->di;
    }

    /**
     *
     */
    public function start()
    {
        $pubPort = $this->getDI()
            ->getConfig()
            ->ports->instance->sseZmqPub;

        $this->startResponse();

        $this->lastTime = microtime(true);
        $this->isRunning = true;

        $this->loop->addPeriodicTimer(0.25, \Closure::bind(function() {
            $this->keepAlive();
        }, $this));

        $context = new ReactZMQContent($this->loop);
        $this->listener = $context->getSocket(ZMQ::SOCKET_SUB);
        $this->listener->connect("tcp://127.0.0.1:$pubPort");

        // Subscribe to all registered channels.
        foreach ($this->channels as $channel => $callback) {
            $this->listener->subscribe($channel);
        }

        $this->listener->on('messages', \Closure::bind(function($msgArr) {
            list($channel, $json) = $msgArr;

            if (array_key_exists($channel, $this->channels)) {
                $data = json_decode($json, true);
                $callback = $this->channels[$channel];
                $callback($data);
            }
        }, $this));

        // Execution remains here until the loop is stopped in a closure.
        $this->loop->run();

        // When this has been reached the loop has been stopped internally.
        return $this;
    }

    /**
     *
     */
    public function subscribe($channel, callable $callback)
    {
        $this->channels[$channel] = $callback->bindTo($this);

        // If listener exists then the loop is running.
        if (isset($this->listener)) {
            $this->listener->subscribe($channel);
        }

        return $this;
    }

    /**
     *
     */
    public function unsuscribe($channel)
    {
        if (array_key_exists($channel, $this->channels)) {
            if (isset($this->listener)) {
                $this->listener->unsubscribe($channel);
            }
            unset($this->channels[$channel]);
        }
    }

    /**
     *
     */
    public function sendEvent($eventData)
    {
        if (is_array($eventData)) {
            $event = new Event($eventData);
        } else if (is_a($event, Event::class)) {
            $event = $eventData;
        } else {
            throw new Exception('Invalid event variable.');
        }

        $text = (string) $event;
        $this->flushText($text);

        return $this;
    }

    /**
     *
     */
    public function keepAlive()
    {
        $now = microtime(true);
        if ($this->keepAlive && $now - $this->lastTime > $this->keepAlive) {
            $this->sendHeartbeat();
        }

        return $this;
    }

    /**
     * Send a heartbeat comment to the browser.
     * This keeps the EventSource connection open in the browser
     * and allows PHP to know if the connection has ended.
     */
    public function sendHeartbeat()
    {
        $this->flushText(":heartbeat\n\n");

        return $this;
    }

    /**
     *
     */
    public function end()
    {
        if (!$this->isRunning) {
            throw new Exception('The ServerSent is not running.');
        }

        // Rebuild the output buffering as we found it.
        ob_start();
        ob_start();

        $this->isRunning = false;
    }

    /**
     *
     */
    protected function startResponse()
    {
        $response = $this->getDI()
            ->getResponse();

        // Disable view.
        $this->getDI()
            ->getView()
            ->disable();

        $this->setHeaders();
        $response->setContentType('text/event-stream');

        $content = '';
        if ($this->retryDelay) {
            $content .= 'retry:' . $this->retryDelay . "\n\n";
        }

        // Remove two levels of output buffering
        ob_get_clean();
        ob_get_clean();

        $response->setContent(":empty\n\n");
        $response->send();
        flush();
    }

    /**
     *
     */
    protected function setHeaders()
    {
        $response = $this->getDI()
            ->getResponse();

        // Disable proxy buffering and fastcgi_buffering.
        $response->setHeader('X-Accel-Buffering', 'no');

        // Disable output compression.
        $response->setHeader('Content-Encoding', 'none');

        // Disable caching in browser.
        $response->setHeader('Cache-Control', 'no-cache');
    }

    /**
     *
     */
    protected function flushText($text)
    {
        if (!$this->isRunning) {
            throw new Exception('The ServerSent is not running.');
        }

        echo $text;
        flush();

        $this->lastTime = microtime(true);
    }
}
