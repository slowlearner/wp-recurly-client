<?php
/**
 * Class for communicating with recurly api
 * not for use outside wordpress environment
 */
class WP_RecurlyClient {
	private $apikey;
	private $gateway = 'https://api.recurly.com/v2/';
	private $error;
	public function __construct($apikey) {
		$this->apikey = $apikey;
	}
	public function last_error() {
		return $this->error();
	}

	public function request($method = 'GET', $resource='accounts', $more_args = array()) {
		$args = array(
			'method'	=> $method,
			'sslverify'		=> false,
			'headers'   => array(
				'Accept' => 'application/xml',
				'Content-Type' => 'application/xml; charset=utf-8',
				'Authorization' => 'Basic ' . base64_encode($this->apikey),
			),
		);

		$args = array_merge($args, $more_args);
		$res = wp_remote_request($this->gateway.$resource, $args);
		if($res['response']['code'] == "200") {
			return $res['body'];
		}
		$this->error = array(
			'code' 			=> $res['response']['code'],
			'message'		=> $res['response']['message'],
			'raw_response'	=> $res
		);
		return false;
	}
	public function update_plan($plan, $args) {
		$xml = '<plan>';
		foreach($args as $k => $val) {
			$xml .= sprintf("<%s>%s</%s>", $k, $val, $k);
		}
		$xml .= '</plan>';
		$args['body'] = $xml;
		$res = $this->request('PUT', "plans/$plan", $args);
		if(!$res) {
			return false;
		}
		return $res;
	}
	public function get_plans() {
		$xml = $this->request('GET', 'plans');
		$doc = new DOMDocument();
		$doc->loadXML($xml);

		$plan = $doc->getElementsByTagName('plan');

		$plans = array();
		foreach($plan as $p) {
			$tmp = array(
				'name' => $p->getElementsByTagName('name')->item(0)->nodeValue,
				'plan_code' => $p->getElementsByTagName('plan_code')->item(0)->nodeValue
			);
			$plans[] = $tmp;
		}
		return $plans;
	}
	//@todo
	public function get_account($account_code) {
		$xml = $this->request('GET', "accounts/$account_code");
		if($xml === false) {
			return false;
		}
		$doc = new DOMDocument();
		$doc->loadXML($xml);

		$user = array(
			'account_code'	=> $doc->getElementsByTagName('account_code')->item(0)->nodeValue,
			'state'			=> $doc->getElementsByTagName('state')->item(0)->nodeValue,
			'username'		=> $doc->getElementsByTagName('username')->item(0)->nodeValue,
			'email'			=> $doc->getElementsByTagName('email')->item(0)->nodeValue,
			'first_name'	=> $doc->getElementsByTagName('first_name')->item(0)->nodeValue,
			'last_name'		=> $doc->getElementsByTagName('last_name')->item(0)->nodeValue,
			'company_name'	=> $doc->getElementsByTagName('company_name')->item(0)->nodeValue
		);
		return $user;
	}
}