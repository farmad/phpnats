<?php
/**
 * Connection Class
 *
 * PHP version 5
 *
 * @category Class
 * @package  Nats
 * @author   Raül Përez <repejota@gmail.com>
 * @license  http://opensource.org/licenses/MIT The MIT License (MIT)
 * @link     https://github.com/repejota/phpnats
 */
namespace Nats;

/**
 * Connection Class
 * 
 * @category Class
 * @package  Nats\Tests\Unit
 * @author   Raül Përez <repejota@gmail.com>
 * @license  http://opensource.org/licenses/MIT The MIT License (MIT)
 * @link     https://github.com/repejota/phpnats
 */
class Connection
{
    /**
     * Number of PINGS
     *
     * @var int number of pings
     */
    private $_pings = 0;

    /**
     * Return the number of pings
     *
     * @return int Number of pings
     */
    public function getNPings()
    {
        return $this->_pings;
    }

    /**
     * Number of messages published
     *
     * @var int number of messages
     */
    private $_pubs = 0;

    /**
     * Return the number of messages published
     *
     * @return int number of messages published
     */
    public function pubsCount()
    {
        return $this->_pubs;
    }

    /**
     * Number of reconnects to the server
     *
     * @var int Number of reconnects
     */
    private $reconnects = 0;

    /**
     * Return the number of reconnects to the server
     *
     * @return int number of reconnects
     */
    public function getNReconnects()
    {
        return $this->reconnects;
    }

    /**
     * List of available subscriptions
     *
     * @var array list of subscriptions
     */
    private $subscriptions = [];

    /**
     * Return the number of subscriptions available
     *
     * @return int number of subscription
     */
    public function getNSubscription()
    {
        return count($this->subscriptions);
    }

    /**
     * Return subscriptions list
     *
     * @return array list of subscription ids
     */
    public function getSubscriptions()
    {
        return array_keys($this->subscriptions);
    }

    /**
     * Hostname of the server
     * @var string hostname
     */
    private $host;

    /**
     * Por number of the server
     *
     * @var integer port number
     */
    private $port;

    /**
     * Stream File Pointer
     *
     * @var mixed Socket file pointer
     */
    private $fp;

    /**
     * Server address
     *
     * @var string Server address
     */
    private $address = "nats://";

    /**
     * Constructor
     *
     * @param string $host name, by default "localhost"
     * @param int    $port number, by default 4222
     */
    public function __construct($host = "localhost", $port = 4222)
    {
        $this->host = $host;
        $this->port = $port;
        $this->address = "tcp://" . $this->host . ":" . $this->port;
    }

    /**
     * Sends data thought the stream
     *
     * @param  string $payload Message data
     * @return null
     */
    private function send($payload)
    {
        $msg = $payload . "\r\n";
        fwrite($this->fp, $msg, strlen($msg));
    }

    /**
     * Receives a message thought the stream
     *
     * @param  int $len Number of bytes to receive
     * @return string
     */
    private function receive($len = null)
    {
        if ($len) {
            return trim(fgets($this->fp, $len + 1));
        } else {
            return trim(fgets($this->fp));
        }
    }

    /**
     * Returns an stream socket to the desired server.
     *
     * @param  string $address Server url string
     * @return resource
     */
    private function getStream($address) 
    {
        $fp = stream_socket_client($address, $errno, $errstr, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            echo "!!!!!!! " . $errstr . " - " . $errno;
        }
        stream_set_blocking($fp, 0);
        return $fp;
    }

    /**
     * Connect to server.
     *
     * Connect will attempt to connect to the NATS server specified by address.
     *
     * Example:
     *   nats://localhost:4222
     *
     * The url can contain username/password semantics.
     *
     * Example:
     *   nats://user:pass@localhost:4222
     *
     * @return null
     */
    public function connect()
    {
        $this->fp = $this->getStream($this->address);
        $msg = 'CONNECT {}';
        $this->send($msg);
    }

    /**
     * Sends PING message
     */
    public function ping()
    {
        $msg = "PING";
        $this->send($msg);
        $this->_pings += 1;
    }

    /**
     * Publish publishes the data argument to the given subject.
     *
     * @param  $subject (string): a string with the subject
     * @param  $payload (string): payload string
     * @return string
     */
    public function publish($subject, $payload)
    {
        $msg = "PUB " . $subject . " " . strlen($payload);
        $this->send($msg);
        $this->send($payload);
        $this->_pubs += 1;
    }

    /**
     * Subscribes to an specific event given a subject.
     *
     * @param  $subject
     * @param  $callback
     * @return string
     */
    public function subscribe($subject, $callback)
    {
        $sid = uniqid();
        $msg = "SUB " . $subject . " " . $sid;
        $this->send($msg);
        $this->subscriptions[$sid] = $callback;
        return $sid;
    }

    /**
     * Unsubscribe from a event given a subject.
     *
     * @param $sid
     */
    public function unsubscribe($sid)
    {
        $msg = "UNSUB " . $sid;
        $this->send($msg);
    }

    /**
     * Waits for messages
     *
     * @param  int $quantity Number of messages to wait for
     * @return \Exception|void
     */
    public function wait($quantity = 0)
    {
        $count = 0;
        while (!feof($this->fp)) {
            $line = $this->receive();

            // Debug
            if ($line) {
                echo ">>>>>>>>> " . $line . PHP_EOL;
            }

            // PING
            if (strpos($line, 'PING') === 0) {
                $this->send("PONG");
            }

            // MSG
            if (strpos($line, 'MSG') === 0) {
                $count = $count + 1;

                $parts = explode(" ", $line);
                $length = $parts[3];
                $sid = $parts[2];

                $payload = $this->receive($length);

                $func = $this->subscriptions[$sid];
                if (is_callable($func)) {
                    $func($payload);
                } else {
                    return new \Exception("not callable");
                }

                if (($quantity != 0) && ($count >= $quantity)) {
                    return null;
                }
            }
        }
        $this->close();
        return $this;
    }

    /**
     * Reconnects to the server
     */
    public function reconnect()
    {
        $this->reconnects += 1;
        $this->close();
        $this->connect();
    }

    /**
     * Close will close the connection to the server.
     */
    public function close()
    {
        fclose($this->fp);
    }

}
