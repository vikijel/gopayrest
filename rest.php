<?php

/**
 * Library for Gopay payments integrations via REST API (https://doc.gopay.com/en/#gopay-rest-api-documentation)
 *
 * @author         EasyJoomla.org
 * @copyright      ©2015 EasyJoomla.org
 * @license        http://opensource.org/licenses/LGPL-3.0 LGPL-3.0
 */
class GopayRest
{
	/** @var string Test API base url */
	const API_URL_TEST = 'https://gw.sandbox.gopay.com/api';

	/** @var string Production API base url */
	const API_URL_PRODUCTION = 'https://gate.gopay.cz/api';

	/** @var string Test javascript URL */
	const JS_URL_TEST = 'https://gw.sandbox.gopay.com/gp-gw/js/embed.js';

	/** @var string Production javascript URL */
	const JS_URL_PRODUCTION = 'https://gate.gopay.cz/gp-gw/js/embed.js';

	/** @var int Maximal number of curl redirects */
	const CURL_MAX_LOOPS = 3;

	/** @var array List of possible values of payment_instrument */
	const PAYMENT_INSTRUMENTS = array(
		'BANK_ACCOUNT' => 'Bankovní převody',
		'GOPAY'        => 'GoPay účet',
		'MPAYMENT'     => 'Mplatba',
		'PAYMENT_CARD' => 'Platební karty',
		'PAYPAL'       => 'PayPal účet',
		'PAYSAFECARD'  => 'paysafecard',
		'PRSMS'        => 'Premium SMS',
		'SUPERCASH'    => 'superCASH',
	);

	/** @var array List of possible values of swift */
	const SWIFTS = array(
		'BREXCZPP'     => 'mBank',
		'CEKOCZPP'     => 'ČSOB',
		'CEKOCZPP-ERA' => 'ERA',
		'CEKOSKBX'     => 'ČSOB SK',
		'FIOBCZPP'     => 'FIO Banka',
		'GIBACZPX'     => 'Česká spořitelna',
		'GIBASKBX'     => 'Slovenská spořitelna',
		'KOMBCZPP'     => 'Komerční Banka',
		'LUBASKBX'     => 'Sberbank Slovensko',
		'OTPVSKBX'     => 'OTP Banka',
		'POBNSKBA'     => 'Poštová Banka',
		'RZBCCZPP'     => 'Raiffeisenbank',
		'SUBASKBX'     => 'Všeobecná úverová banka Banka',
		'TATRSKBX'     => 'Tatra Banka',
		'UNCRSKBX'     => 'Unicredit Bank SK',
	);

	/** @var array List of possible values of currency */
	const CURRENCIES = array(
		'CZK' => 'Česká koruna',
		'EUR' => 'Euro'
	);

	/** @var array List of possible values of lang */
	const LANGS = array(
		'CS' => 'Čeština',
		'DE' => 'German',
		'EN' => 'English',
		'RU' => 'Russian',
		'SK' => 'Slovak'
	);

	/** @var string Mode 'production' or 'test' */
	protected $mode = 'test';

	/** @var string Client ID of Gopay account */
	protected $client_id = '';

	/** @var string Client Secret for Gopay account */
	protected $client_secret = '';

	/** @var string GoID of Gopay account */
	protected $go_id = '';

	/** @var string Base URL of REST API */
	protected $api_url = '';

	/** @var string URL of gopay embed javascript */
	protected $js_url = '';

	/** @var array */
	protected $tokens = array();

	/** @var string */
	protected $lang = 'EN';

	/** @var string */
	protected $currency = 'EUR';

	/**
	 * @see __construct()
	 *
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $go_id
	 * @param string $mode
	 * @param bool   $force_new
	 *
	 * @return GopayRest
	 */
	public static function getInstance($client_id, $client_secret, $go_id, $mode = 'test', $force_new = false)
	{
		static $instances;

		if (!isset($instances))
		{
			$instances = array();
		}

		$instance_id = md5($client_id . ':' . $go_id . ':' . $mode);

		if (!isset($instances[$instance_id]) or $force_new)
		{
			$instances[$instance_id] = new GopayRest($client_id, $client_secret, $go_id, $mode);
		}

		return $instances[$instance_id];
	}

	/**
	 * @see getInstance()
	 *
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $go_id
	 * @param string $mode
	 *
	 * @throws GopayRestException
	 */
	protected function __construct($client_id, $client_secret, $go_id, $mode = 'test')
	{
		$this->setClientId($client_id);
		$this->setClientSecret($client_secret);
		$this->setGoId($go_id);
		$this->setMode($mode);
	}

	/**
	 * @param array $params
	 *
	 * @throws GopayRestException
	 */
	public function createPayment($params)
	{
		//validate important params
		if (!isset($params['amount']) or (float) $params['amount'] <= 0)
		{
			throw new GopayRestException('Missing amount or amount is invalid');
		}

		if (!isset($params['order_number']) or empty($params['order_number']))
		{
			throw new GopayRestException('Missing order_number');
		}

		if (!isset($params['items']) or empty($params['items'])
		    or !isset($params['items'][0]['name']) or !isset($params['items'][0]['amount'])
		)
		{
			throw new GopayRestException('Missing item properties');
		}

		if (!isset($params['callback']['return_url']) or empty($params['callback']['return_url']))
		{
			throw new GopayRestException('Missing callback return_url');
		}

		if (!isset($params['callback']['notification_url']) or empty($params['callback']['notification_url']))
		{
			throw new GopayRestException('Missing callback notification_url');
		}

		//fill common params
		if (!isset($params['order_description']) or empty($params['order_description']))
		{
			$params['order_description'] = $params['order_number'];
		}

		if (!isset($params['currency']))
		{
			$params['currency'] = $this->getCurrency();
		}

		if (!isset($params['lang']))
		{
			$params['lang'] = $this->getLang();
		}

		$params['target'] = array(
			'type' => 'ACCOUNT',
			'goid' => $this->getGoId()
		);

		//validate optional params
		if (isset($params['payer']))
		{
			if (isset($params['payer']['default_payment_instrument']) and empty($params['payer']['default_payment_instrument']))
			{
				throw new GopayRestException('default_payment_instrument cannot be set to empty');
			}

			if (isset($params['payer']['allowed_payment_instruments']) and empty($params['payer']['allowed_payment_instruments']))
			{
				throw new GopayRestException('allowed_payment_instruments cannot be set to empty');
			}

			if (isset($params['payer']['default_swift']) and empty($params['payer']['default_swift']))
			{
				throw new GopayRestException('default_swift cannot be set to empty');
			}

			if (isset($params['payer']['allowed_swifts']) and empty($params['payer']['allowed_swifts']))
			{
				throw new GopayRestException('allowed_swifts cannot be set to empty');
			}
		}

		if (isset($params['payer']['contact']))
		{
			if (!isset($params['payer']['contact']['email']) or empty($params['payer']['contact']['email']))
			{
				throw new GopayRestException('Missing contact\'s email');
			}
		}

		if (isset($params['recurrence']))
		{
			if (!isset($params['recurrence']['recurrence_cycle']))
			{
				throw new GopayRestException('Missing recurrence_cycle');
			}

			if (!isset($params['recurrence']['recurrence_period']))
			{
				throw new GopayRestException('Missing recurrence_period');
			}

			if (!isset($params['recurrence']['recurrence_date_to']))
			{
				throw new GopayRestException('Missing recurrence_date_to');
			}
		}

		$response = $this->apiRequest('/payments/payment', 'post', $params);

		if (!isset($response->json)
		    or !isset($response->json->state) or $response->json->state != 'CREATED'
		    or !isset($response->json->id) or !(int) $response->json->id
		    or !isset($response->json->gw_url) or $response->json->gw_url == ''
		)
		{
			throw new GopayRestException("Payment creation failed, response: " . print_r($response, true));
		}

		return $response->json;
	}

	/**
	 * @param $id_payment
	 *
	 * @return object
	 * @throws GopayRestException
	 */
	public function getPaymentState($id_payment)
	{
		$response = $this->apiRequest('/payments/payment/' . $id_payment, 'get', array(), 'payment-all');

		if (!isset($response->json)
		    or !isset($response->json->id) or !(int) $response->json->id or $response->json->id != $id_payment
		    or !isset($response->json->state)
		)
		{
			throw new GopayRestException("Cannot get payment status, response: " . print_r($response, true));
		}

		return $response->json;
	}

	/**
	 * @param int        $id_payment
	 * @param null|float $amount Refunded amount, if null, then full amount will be refunded
	 *
	 * @return object
	 * @throws GopayRestException
	 */
	public function refundPayment($id_payment, $amount = null)
	{
		if ($amount === null)
		{
			$payment = $this->getPaymentState($id_payment);
			$amount  = $payment->amount;
		}

		$params   = array('amount' => $amount);
		$response = $this->apiRequest('/payments/payment/' . $id_payment . '/refund', 'post', $params, 'payment-all', 'application/x-www-form-urlencoded');

		if (!isset($response->json)
		    or !isset($response->json->id) or !(int) $response->json->id or $response->json->id != $id_payment
		    or !isset($response->json->result) or $response->json->result != 'FINISHED'
		)
		{
			throw new GopayRestException("Cannot capture preauthorized payment, response: " . print_r($response, true));
		}

		return $response->json;
	}

	/**
	 * @param int   $id_parent_payment
	 * @param array $params
	 *
	 * @return object
	 * @throws GopayRestException
	 */
	public function demandRecurrence($id_parent_payment, $params)
	{
		//validate important params
		if (!isset($params['amount']) or (float) $params['amount'] <= 0)
		{
			throw new GopayRestException('Missing amount or amount is invalid');
		}

		if (!isset($params['order_number']) or empty($params['order_number']))
		{
			throw new GopayRestException('Missing order_number');
		}

		if (!isset($params['items']) or empty($params['items'])
		    or !isset($params['items'][0]['name']) or !isset($params['items'][0]['amount'])
		)
		{
			throw new GopayRestException('Missing item properties');
		}

		//fill common params
		if (!isset($params['order_description']) or empty($params['order_description']))
		{
			$params['order_description'] = $params['order_number'];
		}

		if (!isset($params['currency']))
		{
			$params['currency'] = $this->getCurrency();
		}

		$response = $this->apiRequest('/payments/payment/' . $id_parent_payment . '/create-recurrence', 'post', $params, 'payment-all');

		if (!isset($response->json)
		    or !isset($response->json->state) or $response->json->state != 'CREATED'
		    or !isset($response->json->id) or !(int) $response->json->id
		)
		{
			throw new GopayRestException("Recurrence demand failed, response: " . print_r($response, true));
		}

		return $response->json;
	}

	/**
	 * @param int $id_payment
	 *
	 * @return object
	 * @throws GopayRestException
	 */
	public function voidRecurrence($id_payment)
	{
		$response = $this->apiRequest('/payments/payment/' . $id_payment . '/void-recurrence', 'post', array(), 'payment-all');

		if (!isset($response->json)
		    or !isset($response->json->id) or !(int) $response->json->id or $response->json->id != $id_payment
		    or !isset($response->json->result) or $response->json->result != 'FINISHED'
		)
		{
			throw new GopayRestException("Cannot void recurrence, response: " . print_r($response, true));
		}

		return $response->json;
	}

	/**
	 * @param $id_payment
	 *
	 * @return object
	 * @throws GopayRestException
	 */
	public function capturePreauthorizedPayment($id_payment)
	{
		$response = $this->apiRequest('/payments/payment/' . $id_payment . '/capture', 'post', array(), 'payment-all');

		if (!isset($response->json)
		    or !isset($response->json->id) or !(int) $response->json->id or $response->json->id != $id_payment
		    or !isset($response->json->result) or $response->json->result != 'FINISHED'
		)
		{
			throw new GopayRestException("Cannot capture preauthorized payment, response: " . print_r($response, true));
		}

		return $response->json;
	}

	/**
	 * @param $id_payment
	 *
	 * @return object
	 * @throws GopayRestException
	 */
	public function voidPreauthorizedPayment($id_payment)
	{
		$response = $this->apiRequest('/payments/payment/' . $id_payment . '/void-authorization', 'post', array(), 'payment-all');

		if (!isset($response->json)
		    or !isset($response->json->id) or !(int) $response->json->id or $response->json->id != $id_payment
		    or !isset($response->json->result) or $response->json->result != 'FINISHED'
		)
		{
			throw new GopayRestException("Cannot capture preauthorized payment, response: " . print_r($response, true));
		}

		return $response->json;
	}

	/**
	 * @param string $gw_url Property gw_url from json returned by createPayment()
	 * @param string $label  Button label
	 *
	 * @return string
	 */
	public function getPaymentForm($gw_url, $label = 'Zaplatit')
	{
		$html   = array();
		$html[] = '<form action="' . $gw_url . '" method="post" id="gopay-payment-button">';
		$html[] = '	<button name="pay" type="submit">' . $label . '</button>';
		$html[] = '	<script type="text/javascript" src="' . $this->getJsUrl() . '"></script>';
		$html[] = '</form>';

		return implode("\n", $html);
	}

	/**
	 * @param string $scope
	 *
	 * @return string
	 * @throws GopayRestException
	 */
	protected function getToken($scope = 'payment-create')
	{
		if (!isset($this->tokens[$scope]))
		{
			$response = $this->httpRequest(
				$this->getApiUrl('/oauth2/token'),
				'post',
				array(
					'grant_type' => 'client_credentials',
					'scope'      => $scope
				),
				array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded; charset="utf-8"',
					'Authorization' => 'Basic ' . base64_encode($this->getClientId() . ':' . $this->getClientSecret())
				)
			);

			if (!isset($response->json->access_token) or trim($response->json->access_token) == '')
			{
				throw new GopayRestException('Invalid response, missing token: ' . print_r($response, true));
			}

			$this->tokens[$scope] = $response->json->access_token;
		}

		return $this->tokens[$scope];
	}

	/**
	 * @param string      $path
	 * @param string      $method
	 * @param array       $params
	 * @param string      $token_scope
	 * @param string|null $content_type
	 *
	 * @return object
	 * @throws GopayRestException
	 */
	protected function apiRequest($path, $method = 'get', $params = array(), $token_scope = 'payment-create', $content_type = null)
	{
		if (trim($path) == '')
		{
			throw new GopayRestException("Api path cannot be empty");
		}

		if ($content_type === null)
		{
			$content_type = (empty($params) ? 'application/x-www-form-urlencoded' : 'application/json');
		}

		$url     = $this->getApiUrl($path);
		$params  = json_encode($params);
		$headers = array(
			'Accept'        => 'application/json',
			'Content-Type'  => $content_type . '; charset="utf-8"',
			'Authorization' => 'Bearer ' . $this->getToken($token_scope)
		);

		return $this->httpRequest($url, $method, $params, $headers);
	}

	/**
	 * @param string $url
	 * @param string $method
	 * @param mixed  $params
	 * @param array  $headers
	 *
	 * @return object
	 * @throws GopayRestException
	 */
	protected function httpRequest($url, $method = 'get', $params = null, $headers = array())
	{
		if (trim($url) == '')
		{
			throw new GopayRestException("Request url cannot be empty");
		}

		$method = strtolower($method);
		$ch     = curl_init($url);

		curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));

		switch ($method)
		{
			case 'get':
				curl_setopt($ch, CURLOPT_HTTPGET, 1);
				break;

			case 'post':
				if (!is_string($params))
				{
					$params = http_build_query($params);
				}

				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
				break;

			default:
				throw new GopayRestException("Request method '{$method}' is not supported");
				break;
		}

		$response = $this->curlExec($ch);
		$r_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$con_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
		$arr      = explode(';', $con_type, 2);
		$r_type   = reset($arr);

		curl_close($ch);

		return (object) array(
			'body' => $response,
			'code' => $r_code,
			'type' => $r_type,
			'json' => (object) json_decode($response)
		);
	}

	/**
	 * @see curl_exec()
	 *
	 * @param resource $ch
	 * @param int      $done_loops Internal recursions counter
	 *
	 * @return mixed Response
	 * @throws GopayRestException
	 */
	protected function curlExec($ch, $done_loops = 0)
	{
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);

		if (ini_get('open_basedir') == '' && strtolower(ini_get('safe_mode')) == 'off')
		{
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, self::CURL_MAX_LOOPS);

			$data    = curl_exec($ch);
			$res_arr = explode("\r\n\r\n", $data, 2);

			return (isset($res_arr[1]) ? $res_arr[1] : '');
		}
		else
		{
			if ($done_loops > self::CURL_MAX_LOOPS)
			{
				throw new GopayRestException('Maximum number of ' . self::CURL_MAX_LOOPS . ' curl redirects exhausted');
			}

			$data    = curl_exec($ch);
			$res_arr = explode("\r\n\r\n", $data, 2);

			if (isset($res_arr[1]))
			{
				$header = $res_arr[0];
				$data   = $res_arr[1];
			}
			else
			{
				$header = $res_arr[0];
				$data   = '';
			}

			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			if ($http_code == 301 || $http_code == 302)
			{
				$matches = array();

				preg_match('/Location: (.*)/', $header, $matches);

				$url = @parse_url(trim(array_pop($matches)));

				if (!$url)
				{
					return $data;
				}

				$last_url = parse_url(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));

				if (!isset($url['scheme']))
				{
					$url['scheme'] = $last_url['scheme'];
				}

				if (!isset($url['host']))
				{
					$url['host'] = $last_url['host'];
				}

				if (!isset($url['path']))
				{
					$url['path'] = $last_url['path'];
				}

				$new_url = $url['scheme'] . '://' . $url['host'] . $url['path'] . (isset($url['query']) ? '?' . $url['query'] : '');

				curl_setopt($ch, CURLOPT_URL, $new_url);

				$done_loops++;

				return $this->curlExec($ch, $done_loops);
			}
			else
			{
				return $data;
			}
		}
	}

	/**
	 * @param array $headers Associative array of header names and values
	 *
	 * @return array Flat array of headers for curl
	 */
	protected function formatHeaders($headers)
	{
		$headers = (array) $headers;
		$hdrs    = array();

		foreach ($headers as $key => $val)
		{
			$hdrs[] = $key . ': ' . $val;
		}

		return $hdrs;
	}

	/**
	 * @return GopayRest
	 */
	public function setApiUrlByMode()
	{
		if ($this->isMode('production'))
		{
			$this->setApiUrl(self::API_URL_PRODUCTION);
		}
		else
		{
			$this->setApiUrl(self::API_URL_TEST);
		}

		return $this;
	}

	/**
	 * @param string $api_url
	 *
	 * @return GopayRest
	 * @throws GopayRestException
	 */
	public function setApiUrl($api_url = null)
	{
		if ($api_url !== null)
		{
			if (trim($api_url) == '')
			{
				throw new GopayRestException("Api url cannot be empty");
			}

			$this->api_url = rtrim($api_url, '/');
		}
		else
		{
			$this->setApiUrlByMode();
		}

		return $this;
	}

	/**
	 * @param string $path
	 *
	 * @return string
	 */
	public function getApiUrl($path = '')
	{
		return $this->api_url . $path;
	}

	/**
	 * @param string $client_secret
	 *
	 * @return GopayRest
	 * @throws GopayRestException
	 */
	public function setClientSecret($client_secret)
	{
		if (trim($client_secret) == '')
		{
			throw new GopayRestException("Client secret cannot be empty");
		}

		$this->client_secret = $client_secret;

		return $this;
	}

	/**
	 * @return string
	 */
	protected function getClientSecret()
	{
		return $this->client_secret;
	}

	/**
	 * @param string $client_id
	 *
	 * @return GopayRest
	 * @throws GopayRestException
	 */
	public function setClientId($client_id)
	{
		if (trim($client_id) == '')
		{
			throw new GopayRestException("Client id cannot be empty");
		}

		$this->client_id = $client_id;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getClientId()
	{
		return $this->client_id;
	}

	/**
	 * @param string $mode Gopay integration mode: 'test' or 'production'
	 * @param bool   $set_urls_by_mode
	 *
	 * @return GopayRest
	 * @throws GopayRestException
	 */
	public function setMode($mode, $set_urls_by_mode = true)
	{
		if (!in_array($mode, array('test', 'production')))
		{
			throw new GopayRestException("Mode '{$mode}' is not supported");
		}

		$this->mode = $mode;

		if ($set_urls_by_mode)
		{
			$this->setApiUrlByMode();
			$this->setJsUrlByMode();
		}

		return $this;
	}

	/**
	 * @return string
	 */
	public function getMode()
	{
		return $this->mode;
	}

	/**
	 * @param $mode
	 *
	 * @return bool
	 */
	public function isMode($mode)
	{
		return ($this->getMode() == $mode);
	}

	/**
	 * Object info - in production mode hides client_secret
	 *
	 * @return string
	 */
	public function __toString()
	{
		$object = clone $this;

		if ($object->isMode('production'))
		{
			$object->setClientSecret(str_repeat('*', strlen($object->getClientSecret())));
		}

		return '<pre>' . print_r($object, true) . '</pre>';
	}

	/**
	 * @param string $go_id
	 *
	 * @return GopayRest
	 * @throws GopayRestException
	 */
	public function setGoId($go_id)
	{
		if (trim($go_id) == '')
		{
			throw new GopayRestException("GoID cannot be empty");
		}

		$this->go_id = $go_id;

		return $this;
	}

	/**
	 * @return string
	 */
	public function getGoId()
	{
		return $this->go_id;
	}

	/**
	 * @param string $lang
	 *
	 * @return GopayRest
	 * @throws GopayRestException
	 */
	public function setLang($lang)
	{
		if (trim($lang) == '')
		{
			throw new GopayRestException("Lang cannot be empty");
		}

		$this->lang = strtoupper(substr($lang, 0, 2));

		return $this;
	}

	/**
	 * @return string
	 */
	public function getLang()
	{
		return $this->lang;
	}

	/**
	 * @param string $currency
	 *
	 * @return GopayRest
	 * @throws GopayRestException
	 */
	public function setCurrency($currency)
	{
		if (strlen($currency) != 3)
		{
			throw new GopayRestException("Invalid currency code");
		}

		$this->currency = strtoupper($currency);

		return $this;
	}

	/**
	 * @return string
	 */
	public function getCurrency()
	{
		return $this->currency;
	}

	/**
	 * @param string $js_url
	 *
	 * @return GopayRest
	 * @throws GopayRestException
	 */
	public function setJsUrl($js_url = null)
	{
		if ($js_url !== null)
		{
			if (trim($js_url) == '')
			{
				throw new GopayRestException("JS url cannot be empty");
			}

			$this->js_url = $js_url;
		}
		else
		{
			$this->setJsUrlByMode();
		}

		return $this;
	}

	/**
	 * @return GopayRest
	 */
	public function setJsUrlByMode()
	{
		if ($this->isMode('production'))
		{
			$this->setJsUrl(self::JS_URL_PRODUCTION);
		}
		else
		{
			$this->setJsUrl(self::JS_URL_TEST);
		}

		return $this;
	}

	/**
	 * @return string
	 */
	public function getJsUrl()
	{
		return $this->js_url;
	}
}

/**
 * Class GopayRestException
 */
class GopayRestException extends Exception
{
}
