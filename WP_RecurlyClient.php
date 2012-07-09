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
            'method'        => $method,
            'sslverify'     => false,
            // Bug with recurly's webservice where there is a 'location' header along
            // with the 200 response. Wordpress triggers a redirect loop.
            // we add redirection = 0 to get around this
            'redirection'  => 0,
            'headers'       => array(
                'Accept'        => 'application/xml',
                'Content-Type'  => 'application/xml; charset=utf-8',
                'Authorization' => 'Basic ' . base64_encode($this->apikey),
            ),
        );

        $args = array_merge($args, $more_args);
        $res = wp_remote_request($this->gateway.$resource, $args);
        if(is_wp_error($res) || $res['response']['code'] != "200") {
            if(is_wp_error($res)) {
                $this->error = $res;
            } else {
                $this->error = array(
                    'code'          => $res['response']['code'],
                    'message'       => $res['response']['message'],
                    'raw_response'  => $res
                );
            }
            return false;
        }
        return $res['body'];
    }
    public function update_plan($plan, $args) {
        $xml = '<plan>';
        foreach($args as $k => $val) {
            $xml .= sprintf("<%s>%s</%s>", $k, $val, $k);
        }
        $xml .= '</plan>';
        $post_args['body'] = $xml;
        $res = $this->request('PUT', "plans/$plan", $post_args);
        if(!$res) {
            return false;
        }
        return $res;
    }
    public function get_plan($plan_code) {
        $xml = $this->request('GET', "plans/$plan_code");
        $doc =  simplexml_load_string($xml);

        $plan = array(
            'plan_code'         => (string) $doc->plan_code,
            'name'              => (string) $doc->name,
            'description'       => (string) $doc->description,
            'success_url'       => (string) $doc->success_url,
            'cancel_url'        => (string) $doc->cancel_url,
            'accounting_code'   => (string) $doc->accounting_code,
        );
        return $plan;
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
    public function get_account($account_code) {
        $xml = $this->request('GET', "accounts/$account_code");
        if($xml === false) {
            return false;
        }
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $user = array(
            'account_code'          => $doc->getElementsByTagName('account_code')->item(0)->nodeValue,
            'state'                 => $doc->getElementsByTagName('state')->item(0)->nodeValue,
            'username'              => $doc->getElementsByTagName('username')->item(0)->nodeValue,
            'email'                 => $doc->getElementsByTagName('email')->item(0)->nodeValue,
            'first_name'            => $doc->getElementsByTagName('first_name')->item(0)->nodeValue,
            'last_name'             => $doc->getElementsByTagName('last_name')->item(0)->nodeValue,
            'company_name'          => $doc->getElementsByTagName('company_name')->item(0)->nodeValue,
            'hosted_login_token'    => $doc->getElementsByTagName('hosted_login_token')->item(0)->nodeValue,

          );
        return $user;
    }
    public function get_subscriptions($account_code) {
        $xml = $this->request('GET', "accounts/$account_code/subscriptions");
        if($xml === false) {
            return false;
        }

        //using simplexml now.. we'll rewrite the methods above later
        $doc =  simplexml_load_string($xml);


        $subscriptions = array();
        foreach($doc->xpath('subscription') as $subscription) {
            $tmp = array(
                'name'                  => (string) $subscription->plan->name,
                'plan_code'             => (string) $subscription->plan->plan_code,
                'uuid'                  => (string) $subscription->uuid,
                'state'                 => (string) $subscription->state,
                'unit_amount_in_cents'  => (string) $subscription->unit_amount_in_cents,
                'currency'              => (string) $subscription->currency,
                'quantity'              => (string) $subscription->quantity,
                'activated_at'          => (string) $subscription->activated_at,
                'canceled_at'           => (string) $subscription->canceled_at,
                'expires_at'            => (string) $subscription->expires_at,
                'total_billing_cycles'  => (string) $subscription->total_billing_cycles,
                'remaining_billing_cycles'  => (string) $subscription->remaining_billing_cycles,
                'current_period_started_at' => (string) $subscription->current_period_started_at,
                'current_period_ends_at'    => (string) $subscription->current_period_ends_at,
                'trial_started_at'          => (string) $subscription->trial_started_at,
                'trial_ends_at'             => (string) $subscription->trial_ends_at,
            );
            $subscriptions[] = $tmp;
        }
        return $subscriptions;
    }
    /**
     * Returns the type of notification
     * @param string $notif the notification body(xml)
     * @return string the type of notification. Possible values are
     * new_account_notification | canceled_account_notification |
     * billing_info_updated_notification | reactivated_account_notification | new_subscription_notification
     * updated_subscription_notification | canceled_subscription_notification |
     * expired_subscription_notification | renewed_subscription_notification | successful_payment_notification
     * failed_payment_notification | successful_refund_notification | void_payment_notification
     *
     */
    public function get_notification_type($notif) {
        $types = array(
            'new_account_notification',
            'canceled_account_notification',
            'billing_info_updated_notification',
            'reactivated_account_notification',
            'new_subscription_notification',
            'updated_subscription_notification',
            'canceled_subscription_notification',
            'expired_subscription_notification',
            'renewed_subscription_notification',
            'successful_payment_notification',
        );

        //no biggie let's just use stripos
        foreach($types as $t) {
            if(stripos($notif, $t) !== false) {
                return $t;
            }
        }
        return false;
    }
    public function get_subscription_from_notif($notif) {
        $doc = simplexml_load_string($notif);
        $subscription = $doc->xpath('subscription');
        $subscription = current($subscription);
        $tmp = array(
            'name'                  => (string) $subscription->plan->name,
            'plan_code'             => (string) $subscription->plan->plan_code,
            'uuid'                  => (string) $subscription->uuid,
            'state'                 => (string) $subscription->state,
            'unit_amount_in_cents'  => (string) $subscription->unit_amount_in_cents,
            'currency'              => (string) $subscription->currency,
            'quantity'              => (string) $subscription->quantity,
            'activated_at'          => (string) $subscription->activated_at,
            'canceled_at'           => (string) $subscription->canceled_at,
            'expires_at'            => (string) $subscription->expires_at,
            'total_billing_cycles'  => (string) $subscription->total_billing_cycles,
            'remaining_billing_cycles'  => (string) $subscription->remaining_billing_cycles,
            'current_period_started_at' => (string) $subscription->current_period_started_at,
            'current_period_ends_at'    => (string) $subscription->current_period_ends_at,
            'trial_started_at'          => (string) $subscription->trial_started_at,
            'trial_ends_at'             => (string) $subscription->trial_ends_at,
        );
        return $tmp;
    }
}