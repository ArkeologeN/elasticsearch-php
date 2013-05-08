<?php
/**
 * User: zach
 * Date: 5/1/13
 * Time: 9:51 PM
 */

namespace Elasticsearch;

use Elasticsearch\Common\Exceptions\MaxRetriesException;
use Elasticsearch\Common\Exceptions\TransportException;
use Elasticsearch\ConnectionPool\ConnectionPool;
use Elasticsearch\Common\Exceptions;
use Elasticsearch\Connections\ConnectionInterface;
use Elasticsearch\Serializers\SerializerInterface;
use Elasticsearch\Sniffers\Sniffer;
use Monolog\Logger;

/**
 * Class Transport
 *
 * @category Elasticsearch
 * @package  Elasticsearch
 * @author   Zachary Tong <zachary.tong@elasticsearch.com>
 * @license  http://www.apache.org/licenses/LICENSE-2.0 Apache2
 * @link     http://elasticsearch.org
 */
class Transport
{
    /**
     * @var \Pimple
     */
    private $params;

    /**
     * @var ConnectionPool
     */
    private $connectionPool;

    private $requestCounter = 0;

    private $sniffsDueToFailure = 0;

    private $sniffAfterRequestsOriginal;

    private $sniffAfterRequests;

    private $sniffOnConnectionFail;

    /**
     * @var Sniffer
     */
    private $sniffer;

    private $transportSchema;

    private $maxRetries;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var Logger
     */
    private $log;


    /**
     * Transport class is responsible for dispatching requests to the
     * underlying cluster connections
     *
     * @param array   $hosts  Array of hosts in cluster
     * @param \Pimple $params DIC containing dependencies
     * @param Logger  $log    Monolog logger object
     *
     * @throws Exceptions\InvalidArgumentException
     * @throws Exceptions\BadMethodCallException
     */
    public function __construct($hosts, $params, $log)
    {

        if (isset($hosts) !== true) {
            $this->log->addCritical('Hosts parameter must be set');
            throw new Exceptions\BadMethodCallException('Hosts parameter must be set');
        }

        if (isset($params) !== true) {
            $this->log->addCritical('Params parameter must be set');
            throw new Exceptions\BadMethodCallException('Params parameter must be set');
        }

        if (is_array($hosts) !== true) {
            $this->log->addCritical('Hosts parameter must be an array');
            throw new Exceptions\InvalidArgumentException('Hosts parameter must be an array');
        }

        $this->params = $params;

        $this->sniffAfterRequests         = $params['sniffAfterRequests'];
        $this->sniffAfterRequestsOriginal = $params['sniffAfterRequests'];
        $this->sniffOnConnectionFail      = $params['sniffOnConnectionFail'];
        $this->sniffer                    = $params['sniffer'];
        $this->maxRetries                 = $params['maxRetries'];
        $this->serializer                 = $params['serializer'];
        $this->log                        = $log;
        $this->setConnections($hosts);

        if ($this->params['sniffOnStart'] === true) {
            $this->log->addNotice('Sniff on Start.');
            $this->sniffHosts();
        }

    }//end __construct()


    /**
     * Creates Connection objects and instantiates a ConnectionPool object
     *
     * @param array $hosts Assoc array of hosts to add to connection pool
     *
     * @return void
     */
    public function setConnections($hosts)
    {
        $connections = array();
        foreach ($hosts as $host) {
            if (isset($host['port']) === true) {
                $connections[] = $this->params['connection']($host['host'], $host['port']);
            } else {
                $connections[] = $this->params['connection']($host['host']);
            }
        }

        $this->transportSchema = $connections[0]->getTransportSchema();
        $this->connectionPool = $this->params['connectionPool']($connections);

    }//end setConnections()


    /**
     * Add a new connection to the connectionPool
     *
     * @param array $host Assoc. array describing the host:port combo
     *
     * @throws Common\Exceptions\InvalidArgumentException
     * @return void
     */
    public function addConnection($host)
    {
        if (is_array($host) !== true) {
            $this->log->addCritical('Host parameter must be an array');
            throw new Exceptions\InvalidArgumentException('Host parameter must be an array');
        }

        if (isset($host['host']) !== true) {
            $this->log->addCritical('Host must be provided in host parameter');
            throw new Exceptions\InvalidArgumentException('Host must be provided in host parameter');
        }

        if (isset($host['port']) === true && is_numeric($host['port']) === false) {
            $this->log->addCritical('Port must be numeric');
            throw new Exceptions\InvalidArgumentException('Port must be numeric');
        }

        $connection = $this->params['connection']($host['host'], $host['port']);
        $this->connectionPool->addConnection($connection);

    }//end addConnection()


    /**
     * Return an array of all connections in the ConnectionPool
     *
     * @return array
     */
    public function getAllConnections()
    {
        return $this->connectionPool->getConnections();

    }//end getAllConnections()


    /**
     * Returns a single connection from the connection pool
     * Potentially performs a sniffing step before returning
     *
     * @return ConnectionInterface Connection
     */
    public function getConnection()
    {
        if ($this->sniffAfterRequests === true) {
            if ($this->requestCounter > $this->sniffAfterRequests) {
                $this->sniffHosts();
            }

            $this->requestCounter += 1;
        }

        return $this->connectionPool->getConnection();

    }//end getConnection()


    /**
     * Sniff the cluster topology through the Cluster State API
     *
     * @param bool $failure Set to true if we are sniffing
     *                      because of a failed connection
     *
     * @return void
     */
    public function sniffHosts($failure=false)
    {
        $this->requestCounter = 0;
        $nodeInfo = $this->performRequest('GET', '/_cluster/nodes');
        $hosts = $this->sniffer->parseNodes($this->transportSchema, $nodeInfo);
        $this->setConnections($hosts);

        if ($failure === true) {
            $this->log->addNotice('Sniffing cluster state due to failure.');
            $this->sniffsDueToFailure += 1;
            $this->sniffAfterRequests  = (1 + ($this->sniffAfterRequestsOriginal / pow(2,$this->sniffsDueToFailure)));

        } else {
            $this->log->addNotice('Sniffing cluster state.');
            $this->sniffsDueToFailure = 0;
            $this->sniffAfterRequests = $this->sniffAfterRequestsOriginal;
        }

    }//end sniffHosts()

    /**
     * Marks a connection dead, or initiates a cluster resniff
     *
     * @param ConnectionInterface $connection The connection to mark as dead
     *
     * @return void
     */
    public function markDead($connection)
    {
        if ($this->sniffOnConnectionFail === true) {
            $this->sniffHosts(true);
        } else {
            $this->connectionPool->markDead($connection);
        }

    }//end markDead()


    /**
     * Perform a request to the Cluster
     *
     * @param string $method HTTP method to use
     * @param string $uri    HTTP URI to send request to
     * @param null   $params Optional query parameters
     * @param null   $body   Optional query body
     *
     * @throws MaxRetriesException
     * @return array
     */
    public function performRequest($method, $uri, $params=null, $body=null)
    {
        foreach (range(1, $this->maxRetries) as $attempt) {
            $connection = $this->getConnection();

            if (isset($body) === true) {
                $body = $this->serializer->serialize($body);
            }

            try {
                $response = $connection->performRequest(
                    $method,
                    $uri,
                    $params,
                    $body
                );

            } catch (TransportException $e) {

                $this->log->addWarning('Transport exception, retrying.', array($e->getMessage()));

                $this->markDead($connection);
                if ($attempt === $this->maxRetries) {
                    $this->log->addError('The maxinum number of request retries has been reached');
                    throw new MaxRetriesException('The maximum number of request retries has been reached.');
                }

                // Skip the return below and continue retrying.
                continue;
            }//end try

            $data = $this->serializer->deserialize($response['text']);

            return array(
                    'status' => $response['status'],
                    'data'   => $data,
                    'info'   => $response['info'],
                   );

        }//end foreach

    }//end performRequest()


}//end class