# Amazon Marketplace Web Service

Library to interface with Amazon Marketplace Web Service (Amazon MWS).

### Installation
```bash
$ composer require glitzhub/amazon-mws
```

### Usage
```php
$mwsClient = new GlitzHub\MWSClient([
    'accessKeyId' => 'AWS_ACCESS_KEY_ID',   // AWSAccessKeyId
    'secretKey' => 'SECRET_KEY',            // Secret Key
    'marketplaceId' => 'MARKETPLACE_ID',    // MarketplaceId
    'sellerId' => 'SELLER_ID',              // SellerId
    'authToken' => 'MWS_AUTH_TOKEN',        // MWSAuthToken
]);

// List orders
$orders = $mwsClient->listOrders(
    time() - 3600,
    null,
    ['Unshipped', 'PartiallyShipped'],
    ['AFN', 'MFN']
);

// List order items
$items = $mwsClient->listOrderItems('AMAZON_ORDER_ID');

// Get order by Amazon order ID
$order = $mwsClient->getOrder('AMAZON_ORDER_ID');
$orders = $mwsClient->getOrder(['AMAZON_ORDER_ID_1', 'AMAZON_ORDER_ID_2']);

// Send order fulfillment feed
$feedType = '_POST_ORDER_FULFILLMENT_DATA_';
$feedContent = [
    'MessageType' => 'OrderFulfillment',
    'Message' => [
        [
            'MessageID' => 1,
            'OperationType' => 'Update',
            'OrderFulfillment' => [
                'AmazonOrderID' => 'AMAZON_ORDER_ID',
                'FulfillmentDate' => date(DATE_W3C, time()),
                'FulfillmentData' => [
                    'CarrierName' => 'CARRIER_NAME',
                    'ShippingMethod' => 'SHIPPING_METHOD',
                    'ShipperTrackingNumber' => 'TRACKING_NUMBER',
                ],
                'Item' => [
                    [
                        'AmazonOrderItemCode' => 'AMAZON_ORDER_ITEM_CODE_1',
                        'Quantity' => 'QUANTITY_1'
                    ],
                    [
                        'AmazonOrderItemCode' => 'AMAZON_ORDER_ITEM_CODE_2',
                        'Quantity' => 'QUANTITY_2'
                    ]
                ]
            ]
        ]
    ]
];
$response = $mwsClient->submitFeed($feedType, $feedContent);

// Send inventory feed
$feedType = '_POST_INVENTORY_AVAILABILITY_DATA_';
$feedContent = [
    'MessageType' => 'Inventory',
    'Message' => [
        [
            'MessageID' => 1,
            'OperationType' => 'Update',
            'Inventory' => [
                'SKU' => 'SKU_1',
                'Quantity' => 'QUANTITY_1'
            ]
        ],
        [
            'MessageID' => 2,
            'OperationType' => 'Update',
            'Inventory' => [
                'SKU' => 'SKU_2',
                'Quantity' => 'QUANTITY_2'
            ]
        ]
    ]
];
$response = $mwsClient->submitFeed($feedType, $feedContent);
```