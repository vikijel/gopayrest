#   GopayRest (lib_gopay)
-   PHP Library for Gopay payments integrations
    -   Just include the rest.php file and use GopayRest class in your PHP code
-   Installation xml manifest for Joomla! CMS attached 
    1.  Download repo zip
    2.  Install zip to Joomla
    3.  Use ```jimport('gopay.rest');``` to include GopayRest class to your extension

##  Requires:
-   cURL extension in PHP

##  Example - Create Payment:
```php
try
{
	$response = GopayRest::getInstance('YOUR_CLIENT_ID',
									   'YOUR_CLIENT_SECRET',
									   'YOUR_GOID',
									   'test')
						 ->setLang('CS')
						 ->setCurrency('CZK')
						 ->createPayment(
							 array(
								 'amount'            => '1000', //cents
								 'order_number'      => '001',
								 'order_description' => 'pojisteni01',
								 'items'             => array(
									 array('name' => 'item01', 'amount' => '500'),
									 array('name' => 'item02', 'amount' => '1500')
								 ),
								 'payer'             => array(
									 'contact' => array(
										 'email' => 'example@example.com',
									 ),
								 ),
								 'callback'          => array(
									 'return_url'       => 'https://example.com/gopay-return',
									 'notification_url' => 'http://example.com/gopay-notify'
								 )
							 )
						 );

	echo 'Payment ID = ' . $response->id;
	echo 'Gate URL = ' . $response->gw_url; //todo: redirect to this url
}
catch (GopayRestException $e)
{
	die($e->getMessage());
}
```

##  Example - Get Payment State:
```php
try
{
	$response = GopayRest::getInstance('YOUR_CLIENT_ID',
									   'YOUR_CLIENT_SECRET',
									   'YOUR_GOID',
									   'test')
						 ->getPaymentState('PAYMENT_ID');

	echo 'Payment state = ' . $response->state;
}
catch (GopayRestException $e)
{
	die($e->getMessage());
}
```

##  Example - Get Payment State:
```php
try
{
	$response = GopayRest::getInstance('YOUR_CLIENT_ID',
									   'YOUR_CLIENT_SECRET',
									   'YOUR_GOID',
									   'test')
						 ->getPaymentState('PAYMENT_ID');

	echo 'Payment state = ' . $response->state;
}
catch (GopayRestException $e)
{
	die($e->getMessage());
}
```

##  Example - Void Recurrence:
```php
try
{
	$response = GopayRest::getInstance('YOUR_CLIENT_ID',
									   'YOUR_CLIENT_SECRET',
									   'YOUR_GOID',
									   'test')
						 ->voidRecurrence('PAYMENT_ID');

	echo 'VOID OK';
}
catch (GopayRestException $e)
{
	die($e->getMessage());
}
```
