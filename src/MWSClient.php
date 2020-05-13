<?php
namespace GlitzHub;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Spatie\ArrayToXml\ArrayToXml;

/**
 * Amazon MWS client
 * @package common\components\amazon
 */
class MWSClient
{
    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.000\Z";
    const APPLICATION_NAME = 'GlitzHub';
    const APPLICATION_VERSION = '0.1';

    protected $config = [
        'accessKeyId' => null,      // AWSAccessKeyId
        'secretKey' => null,        // Secret Key
        'marketplaceId' => null,    // MarketplaceId
        'sellerId' => null,         // SellerId
        'authToken' => null,        // MWSAuthToken
    ];
    protected $regionHost = null;
    protected $regionUrl = null;
    protected $httpClient = null;

    // MarketplaceId values
    public static $marketplaceIds = [
        // North America region
        'A2Q3Y263D00KWC' => 'mws.amazonservices.com',       // Brazil (BR)
        'A2EUQ1WTGCTBG2' => 'mws.amazonservices.ca',        // Canada (CA)
        'A1AM78C64UM0Y8' => 'mws.amazonservices.com.mx',    // Mexico (MX)
        'ATVPDKIKX0DER' => 'mws.amazonservices.com',        // US (US)
        // Europe region
        'A2VIGQ35RCS4UG' => 'mws.amazonservices.ae',        // United Arab Emirates (U.A.E.) (AE)
        'A1PA6795UKMFR9' => 'mws-eu.amazonservices.com',    // Germany (DE)
        'ARBP9OOSHTCHU' => 'mws-eu.amazonservices.com',     // Egypt (EG)
        'A1RKKUPIHCS9HS' => 'mws-eu.amazonservices.com',    // Spain (ES)
        'A13V1IB3VIYZZH' => 'mws-eu.amazonservices.com',    // France (FR)
        'A1F83G8C2ARO7P' => 'mws-eu.amazonservices.com',    // UK (GB)
        'A21TJRUUN4KGV' => 'mws.amazonservices.in',         // India (IN)
        'APJ6JRA9NG5V4' => 'mws-eu.amazonservices.com',     // Italy (IT)
        'A1805IZSGTT6HS' => 'mws-eu.amazonservices.com',    // Netherlands (NL)
        'A17E79C6D8DWNP' => 'mws-eu.amazonservices.com',    // Saudi Arabia (SA)
        'A33AVAJ2PDY3EV' => 'mws-eu.amazonservices.com',    // Turkey (TR)
        // Far East region
        'A19VAU5U5O7RUS' => 'mws-fe.amazonservices.com',    // Singapore (SG)
        'A39IBJ37TRP1C6' => 'mws.amazonservices.com.au',    // Australia (AU)
        'A1VC38T7YXB528' => 'mws.amazonservices.jp',        // Japan (JP)
    ];

    // Amazon MWS endpoints
    public static $endpoints = [
        'ListOrders' => [
            'method' => 'POST',
            'action' => 'ListOrders',
            'path' => '/Orders/2013-09-01',
            'version' => '2013-09-01'
        ],
        'ListOrdersByNextToken' => [
            'method' => 'POST',
            'action' => 'ListOrdersByNextToken',
            'path' => '/Orders/2013-09-01',
            'version' => '2013-09-01'
        ],
        'GetOrder' => [
            'method' => 'POST',
            'action' => 'GetOrder',
            'path' => '/Orders/2013-09-01',
            'version' => '2013-09-01'
        ],
        'ListOrderItems' => [
            'method' => 'POST',
            'action' => 'ListOrderItems',
            'path' => '/Orders/2013-09-01',
            'version' => '2013-09-01'
        ],
        'ListOrderItemsByNextToken' => [
            'method' => 'POST',
            'action' => 'ListOrderItemsByNextToken',
            'path' => '/Orders/2013-09-01',
            'version' => '2013-09-01'
        ],
        'SubmitFeed' => [
            'method' => 'POST',
            'action' => 'SubmitFeed',
            'path' => '/',
            'version' => '2009-01-01'
        ],
        'GetFeedSubmissionResult' => [
            'method' => 'POST',
            'action' => 'GetFeedSubmissionResult',
            'path' => '/',
            'version' => '2009-01-01'
        ],
    ];

    /**
     * MWSClient constructor
     * @param array $config Configuration
     * @throws Exception
     */
    public function __construct($config)
    {
        foreach ($config as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }

        $requiredKeys = ['accessKeyId', 'secretKey', 'marketplaceId', 'sellerId'];
        foreach ($requiredKeys as $requiredKey) {
            if (is_null($this->config[$requiredKey])) {
                throw new Exception("Required field '{$requiredKey}' is not set.");
            }
        }

        if (!isset(static::$marketplaceIds[$this->config['marketplaceId']])) {
            throw new Exception('Invalid Marketplace ID.');
        }

        $this->regionHost = static::$marketplaceIds[$this->config['marketplaceId']];
        $this->regionUrl = 'https://' . $this->regionHost;
    }

    /**
     * List orders
     * @param int $startTimestamp Start timestamp
     * @param int|null $endTimestamp End timestamp
     * @param array $statuses Statuses
     *  Pending - Pending
     *  PendingAvailability - Pending Availability（Japan only）
     *  Unshipped - Unshipped
     *  PartiallyShipped - Partially Shipped
     *  Shipped - Shipped
     *  Canceled - Canceled
     *  Unfulfillable - Unfulfillable
     * @param array $channels Fulfillment channel
     *  AFN - Fulfilled by Amazon
     *  MFN - Fulfilled by the seller
     * @param int $pageSize Page size
     * @param bool $fetchAll Fetch all results
     * @return array Order list
     * @throws Exception
     */
    public function listOrders(
        $startTimestamp,
        $endTimestamp = null,
        $statuses = ['Unshipped', 'PartiallyShipped'],
        $channels = ['AFN', 'MFN'],
        $pageSize = 100,
        $fetchAll = true
    )
    {
        $orders = [];
        $nextToken = null;

        do {
            if ($nextToken === null) {
                $endpoint = 'ListOrders';
                $queryParams = [
                    'MaxResultsPerPage' => (int)$pageSize,
                    'LastUpdatedAfter' => gmdate(self::DATE_FORMAT, $startTimestamp),
                ];
                if ($endTimestamp !== null) {
                    $queryParams['LastUpdatedBefore'] = gmdate(self::DATE_FORMAT, $endTimestamp);
                }
                foreach ($statuses as $key => $status) {
                    $queryParams['OrderStatus.Status.' . ($key + 1)] = $status;
                }
                foreach ($channels as $key => $channel) {
                    $queryParams['FulfillmentChannel.Channel.' . ($key + 1)] = $channel;
                }
            } else {
                $endpoint = 'ListOrdersByNextToken';
                $queryParams = ['NextToken' => $nextToken];
            }

            $response = $this->sendRequest($endpoint, $queryParams);
            if (!empty($response[$endpoint . 'Result']['Orders']['Order'])) {
                if (isset($response[$endpoint . 'Result']['Orders']['Order']['AmazonOrderId'])) {
                    $orders[] = $response[$endpoint . 'Result']['Orders']['Order'];
                } else {
                    $orders = array_merge($orders, $response[$endpoint . 'Result']['Orders']['Order']);
                }
            }
            if ($fetchAll && !empty($response[$endpoint . 'Result']['NextToken'])) {
                $nextToken = $response[$endpoint . 'Result']['NextToken'];
            } else {
                $nextToken = null;
            }
        } while ($nextToken !== null);

        return $orders;
    }

    /**
     * Get order by Amazon order ID
     * @param string|array $amazonOrderIds Amazon order ID value(s)
     * @return array|null Order detail
     * @throws Exception
     */
    public function getOrder($amazonOrderIds)
    {
        if (is_string($amazonOrderIds)) {
            $amazonOrderIds = [$amazonOrderIds];
        }
        $queryParams = [];
        foreach ($amazonOrderIds as $key => $value) {
            $queryParams['AmazonOrderId.Id.' . ($key + 1)] = $value;
        }
        $response = $this->sendRequest('GetOrder', $queryParams);
        if (!empty($response['GetOrderResult']['Orders'])) {
            if (isset($response['GetOrderResult']['Orders']['Order']['AmazonOrderId'])) {
                $orders = [$response['GetOrderResult']['Orders']['Order']];
            } else if (isset($response['GetOrderResult']['Orders']['Order'])) {
                $orders = $response['GetOrderResult']['Orders']['Order'];
            }
        }

        return isset($orders) ? $orders : null;
    }

    /**
     * List order items
     * @param string $amazonOrderId Amazon order ID
     * @return array Order items
     * @throws Exception
     */
    public function listOrderItems($amazonOrderId)
    {
        $items = [];
        $nextToken = null;

        do {
            if ($nextToken === null) {
                $endpoint = 'ListOrderItems';
                $queryParams = ['AmazonOrderId' => $amazonOrderId];
            } else {
                $endpoint = 'ListOrderItemsByNextToken';
                $queryParams = ['NextToken' => $nextToken];
            }

            $response = $this->sendRequest($endpoint, $queryParams);
            if (!empty($response[$endpoint . 'Result']['OrderItems']['OrderItem'])) {
                if (isset($response[$endpoint . 'Result']['OrderItems']['OrderItem']['ASIN'])) {
                    $items[] =$response[$endpoint . 'Result']['OrderItems']['OrderItem'];
                } else {
                    $items = array_merge($items, $response[$endpoint . 'Result']['OrderItems']['OrderItem']);
                }
            }
            if (!empty($response[$endpoint . 'Result']['NextToken'])) {
                $nextToken = $response[$endpoint . 'Result']['NextToken'];
            } else {
                $nextToken = null;
            }
        } while ($nextToken !== null);

        return $items;
    }

    /**
     * Submit feed
     * @param string $feedType Feed type
     * @param array|string $feedContent Feed content
     * @param bool $purgeAndReplace Purge and replace
     * @return array Result
     * @throws Exception
     */
    public function submitFeed($feedType, $feedContent, $purgeAndReplace = false)
    {
        // Feed content
        if (is_array($feedContent)) {
            $feedContent = array_merge(
                [
                    'Header' => [
                        'DocumentVersion' => 1.01,
                        'MerchantIdentifier' => $this->config['sellerId']
                    ]
                ],
                $feedContent
            );
            $feedContent = ArrayToXml::convert($feedContent, 'AmazonEnvelope');
        }

        // Request parameters
        $queryParams = [
            'FeedType' => $feedType,
            'PurgeAndReplace' => $purgeAndReplace,
        ];
        if ($this->config['marketplaceId'] != 'A1VC38T7YXB528') {
            $queryParams['MarketplaceIdList.Id.1'] = $this->config['marketplaceId'];
        }

        // Request and return
        $response = $this->sendRequest('SubmitFeed', $queryParams, $feedContent);
        return [
            'feedSubmissionId' => $response['SubmitFeedResult']['FeedSubmissionInfo']['FeedSubmissionId'],
            'feedType' => $response['SubmitFeedResult']['FeedSubmissionInfo']['FeedType'],
            'submittedDate' => $response['SubmitFeedResult']['FeedSubmissionInfo']['SubmittedDate'],
            'feedProcessingStatus' => $response['SubmitFeedResult']['FeedSubmissionInfo']['FeedProcessingStatus'],
        ];
    }

    /**
     * Send request
     * @param string $requestAction Request action
     * @param array $queryParams Query parameters
     * @param null|string $requestBody Request body
     * @param bool $responseRaw Response raw data
     * @return array|string Response data
     * @throws Exception
     */
    protected function sendRequest($requestAction, $queryParams = [], $requestBody = null, $responseRaw = false)
    {
        // Endpoint
        if (isset(static::$endpoints[$requestAction])) {
            $endPoint = static::$endpoints[$requestAction];
        } else {
            throw new Exception("Call to undefined action '{$requestAction}'.");
        }

        // Merge common query parameters
        $commonQueryParams = [
            'AWSAccessKeyId' => $this->config['accessKeyId'],
            'Action' => $endPoint['action'],
            'SellerId' => $this->config['sellerId'],
            'SignatureMethod' => static::SIGNATURE_METHOD,
            'SignatureVersion' => static::SIGNATURE_VERSION,
            'Timestamp' => gmdate(static::DATE_FORMAT, time()),
            'Version' => $endPoint['version'],
        ];
        $queryParams = array_merge($commonQueryParams, $queryParams);

        // Auth Token and Marketplace ID
        if (!empty($this->config['authToken'])) {
            $queryParams['MWSAuthToken'] = $this->config['authToken'];
        }
        if (isset($queryParams['MarketplaceId'])) {
            $marketplaceIdKey = 'MarketplaceId';
        } else if (isset($queryParams['MarketplaceId.Id.1'])) {
            $marketplaceIdKey = 'MarketplaceId.Id.1';
        } else if (isset($queryParams['MarketplaceIdList.Id.1'])) {
            $marketplaceIdKey = 'MarketplaceIdList.Id.1';
        } else {
            $marketplaceIdKey = 'MarketplaceId.Id.1';
            $queryParams[$marketplaceIdKey] = $this->config['marketplaceId'];
        }
        ksort($queryParams);

        try {
            // Calculate signature
            $queryParams['Signature'] = base64_encode(
                hash_hmac(
                    'sha256',
                    "{$endPoint['method']}\n{$this->regionHost}\n{$endPoint['path']}\n"
                    . http_build_query($queryParams, null, '&', PHP_QUERY_RFC3986),
                    $this->config['secretKey'],
                    true
                )
            );

            // Send HTTP request
            if ($this->httpClient === null) {
                $this->httpClient = new Client();
            }
            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => static::APPLICATION_NAME . '/' . static::APPLICATION_VERSION,
            ];
            if ($endPoint['action'] == 'SubmitFeed') {
                $headers['Content-MD5'] = base64_encode(md5($requestBody, true));
                $headers['Content-Type'] = $queryParams[$marketplaceIdKey] == 'A1VC38T7YXB528' ?
                    'text/xml; charset=Shift_JIS' : 'text/xml; charset=iso-8859-1';
            }
            $response = $this->httpClient->request(
                $endPoint['method'],
                $this->regionUrl . $endPoint['path'],
                [
                    'headers' => $headers,
                    'body' => $requestBody,
                    'query' => $queryParams
                ]
            );

            // Process response data
            $responseBody = (string)$response->getBody();
            if ($responseRaw || !strpos(strtolower($response->getHeader('Content-Type')[0]), 'xml')) {
                return $responseBody;
            } else {
                return json_decode(json_encode(simplexml_load_string($responseBody)), true);
            }
        } catch (BadResponseException $exception) {
            if ($exception->hasResponse()) {
                $responseBody = (string)$exception->getResponse()->getBody();
                if (strpos($responseBody, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($responseBody);
                    $message = $error->Error->Message;
                }
            }
            throw new Exception(isset($message) ? $message : 'An error occurred.');
        }
    }
}