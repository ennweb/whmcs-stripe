<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function widget_stripe()
{
    if (function_exists('getGatewayVariables')) {
        $gateway = getGatewayVariables('stripe');
        if ($gateway['testMode'] == 'on') {
            $key = $gateway['testSecretKey'];
        } else {
            $key = $gateway['liveSecretKey'];
        }
        $ch = curl_init('https://api.stripe.com/v1/balance');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $key . ':');
        $rawdata = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($rawdata);
        $content = '<div style="margin:10px;padding:10px;background-color:#00afe1;text-align:center;font-size:16px;color:#fff;">';
        $content .= 'Stripe Balance: <b>' . number_format($result->available[0]->amount / 100, 2) . ' USD</b> <small>(' . number_format($result->pending[0]->amount / 100, 2) . ' USD Pending)</small>';
        $content .= '</div>';

        return [
            'title'   => 'Stripe Balance',
            'content' => $content,
        ];
    }
}

add_hook('AdminHomeWidgets', 1, 'widget_stripe');
