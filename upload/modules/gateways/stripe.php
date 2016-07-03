<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see http://docs.whmcs.com/Gateway_Module_Meta_Data_Parameters
 *
 * @return array
 */
function stripe_MetaData()
{
    return [
        'DisplayName'                 => 'Stripe',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => false,
        'TokenisedStorage'            => true,
    ];
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @return array
 */
function stripe_config()
{
    return [
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Stripe',
        ],
        // a password field type allows for masked text input
        'testSecretKey' => [
            'FriendlyName' => 'Test Secret Key',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => '',
        ],
        // a text field type allows for single line text input
        'testPublicKey' => [
            'FriendlyName' => 'Test Public Key',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => '',
        ],
        // a password field type allows for masked text input
        'liveSecretKey' => [
            'FriendlyName' => 'Live Secret Key',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => '',
        ],
        // a text field type allows for single line text input
        'livePublicKey' => [
            'FriendlyName' => 'Live Public Key',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => '',
            'Description'  => '',
        ],
        // the yesno field type displays a single checkbox option
        'testMode' => [
            'FriendlyName' => 'Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Tick to enable test mode',
        ],
    ];
}

/**
 * Create database table
 *
 * This function checks database table and creates if not exists
 *
 * @return void
 */
function stripe_schema()
{
    if (!Capsule::schema()->hasTable('stripe_customers')) {
        try {
            Capsule::schema()->create('stripe_customers', function ($table) {
                $table->increments('id');
                $table->string('stripe_id')->unique();
                $table->string('email')->unique();
                $table->timestamps();
            });
        } catch (Exception $e) {
            die('Unable to create stripe_customers table: {$e->getMessage()}');
        }
    }
}

/**
 * Capture payment.
 *
 * Called when a payment is to be processed and captured.
 *
 * The card cvv number will only be present for the initial card holder present
 * transactions. Automated recurring capture attempts will not provide it.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return array Transaction response status
 */
function stripe_capture($params)
{
    stripe_schema();

    if ($params['testMode'] == 'on') {
        $key = $params['testSecretKey'];
    } else {
        $key = $params['liveSecretKey'];
    }

    $customer = Capsule::table('stripe_customers')->where('email', $params['clientdetails']['email'])->first();

    $data = [
        'amount'      => $params['amount'] * 100,
        'currency'    => strtolower($params['currency']),
        'customer'    => $customer->stripe_id,
        'description' => $params['description'],
    ];

    $ch = curl_init('https://api.stripe.com/v1/charges');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $key . ':');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $rawdata = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($rawdata);
    if ($status == 200 && !empty($result->id)) {
        return [
            'status'  => 'success',
            'transid' => $result->id,
            'rawdata' => $rawdata,
        ];
    } else {
        return [
            'status'  => 'declined',
            'rawdata' => $rawdata,
        ];
    }
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return array Transaction response status
 */
function stripe_refund($params)
{
    stripe_schema();

    if ($params['testMode'] == 'on') {
        $key = $params['testSecretKey'];
    } else {
        $key = $params['liveSecretKey'];
    }

    $data = [
        'charge' => $params['transid'],
        'amount' => $params['amount'] ? $params['amount'] * 100 : null,
    ];

    $ch = curl_init('https://api.stripe.com/v1/refunds');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $key . ':');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $rawdata = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($rawdata);
    if ($status == 200 && !empty($result->id)) {
        return [
            'status'  => 'success',
            'transid' => $result->id,
            'rawdata' => $rawdata,
        ];
    } else {
        return [
            'status'  => 'failed',
            'rawdata' => $rawdata,
        ];
    }
}

/**
 * Store credit card.
 *
 * Called when a token is requested for a transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see http://docs.whmcs.com/Payment_Gateway_Module_Parameters
 *
 * @return array Transaction response status
 */
function stripe_storeremote($params)
{
    stripe_schema();

    if ($params['testMode'] == 'on') {
        $key = $params['testSecretKey'];
    } else {
        $key = $params['liveSecretKey'];
    }

    $customer = Capsule::table('stripe_customers')->where('email', $params['clientdetails']['email'])->first();

    if (empty($params['cardnum'])) {
        if (!$customer || empty($params['gatewayid'])) {
            return [
                'status' => 'success',
            ];
        }
        $ch = curl_init('https://api.stripe.com/v1/customers/' . $customer->stripe_id . '/sources/' . $params['gatewayid']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $key . ':');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        $rawdata = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status'  => 'success',
            'rawdata' => $rawdata,
        ];
    }

    if ($_POST['stripeToken']) {
        $data = [
            'email'  => $params['clientdetails']['email'],
            'source' => $_POST['stripeToken'],
        ];
    } else {
        $data = [
            'email'  => $params['clientdetails']['email'],
            'source' => [
                'object'    => 'card',
                'number'    => $params['cardnum'],
                'exp_month' => substr($params['cardexp'], 0, 2),
                'exp_year'  => substr($params['cardexp'], 2),
                'cvc'       => $params['cardcvv'],
            ],
        ];
    }

    $ch = curl_init('https://api.stripe.com/v1/customers' . ($customer ? '/' . $customer->stripe_id : ''));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $key . ':');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $rawdata = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($rawdata);

    if ($status == 200 && !empty($result->id)) {
        if (!$customer) {
            Capsule::table('stripe_customers')->insert([
                'stripe_id'  => $result->id,
                'email'      => $params['clientdetails']['email'],
                'created_at' => new DateTime,
                'updated_at' => new DateTime,
            ]);
        }
        return [
            'status'    => 'success',
            'gatewayid' => $result->sources->data[0]->id,
            'rawdata'   => $rawdata,
        ];
    } else {
        return [
            'status'  => 'failed',
            'rawdata' => $rawdata,
        ];
    }
}

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (in_array($vars['templatefile'], ['viewcart', 'creditcard'])) {
        $gateway = getGatewayVariables('stripe');
        if ($gateway['testMode'] == 'on') {
            $key = $gateway['testPublicKey'];
        } else {
            $key = $gateway['livePublicKey'];
        }
        return <<< END
            <script type="text/javascript" src="https://js.stripe.com/v2/"></script>
            <script type="text/javascript">
            (function() {
                Stripe.setPublishableKey('{$key}');
                $('body').on('submit', 'form[name="orderfrm"], form[action="creditcard.php"]', function(e) {
                    var form = $(this);
                    var method = $('input[name="paymentmethod"]:checked').val();
                    if ((!$('#useexisting').length || ($('#useexisting').length && !$('#useexisting').prop('checked'))) && (!$('input[name="ccinfo"]').length || ($('input[name="ccinfo"]').length && $('input[name="ccinfo"]:checked').val() != 'useexisting')) && (method == 'stripe' || !method)) {
                        if ($('#stripeToken').length) {
                            return true;
                        }
                        Stripe.card.createToken({
                            number: $('#inputCardNumber').val(),
                            cvc: $('#inputCardCvv').length ? $('#inputCardCvv').val() : $('#inputCardCVV').val(),
                            exp_month: $('#inputCardExpiryYear').length ? $('#inputCardExpiry').val() : $('#inputCardExpiry').val().substr(0, 2),
                            exp_year: $('#inputCardExpiryYear').length ? $('#inputCardExpiryYear').val() : $('#inputCardExpiry').val().substr(5)
                        }, function (status, response) {
                            $('#cctype').val(Stripe.card.cardType($('#inputCardNumber').val()));
                            form.append($('<input type="hidden" id="stripeToken" name="stripeToken" />').val(response.id));
                            if (form.find('input[name="submit"]').length) {
                                $('#btnCompleteOrder').click();
                            } else {
                                form.get(0).submit();
                            }
                        });
                        return false;
                    } else {
                        return true;
                    }
                });
            })();
            </script>
END;
    }
});
