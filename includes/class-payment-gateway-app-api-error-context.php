<?php

defined('ABSPATH') || exit;

final class Payment_Gateway_App_Api_Error_Context
{
    const MAX_IDENTIFIER_LENGTH = 128;
    const MAX_PROVIDER_LENGTH = 64;
    const MAX_PROVIDER_COUNT = 20;

    public static function parse($response_body, $http_status = null)
    {
        $data = self::object_data($response_body);
        $field = function ($paths) use ($data) {
            return self::scalar($data, $paths);
        };
        return array(
            'http_status' => is_numeric($http_status) ? (int)$http_status : null,
            'code' => self::identifier($field(array('code', 'error.code'))),
            'request_id' => self::identifier($field(array('requestId', 'requestID', 'error.requestId', 'error.requestID', 'chargeback.requestId', 'chargeback.requestID', 'error.chargeback.requestId', 'error.chargeback.requestID'))),
            'transaction_id' => self::identifier($field(array('id', 'transactionId', 'error.id', 'error.transactionId', 'chargeback.transactionId', 'chargeback.gatewayTransactionId', 'error.chargeback.transactionId', 'error.chargeback.gatewayTransactionId'))),
            'external_reference' => self::identifier($field(array('externalReference', 'error.externalReference', 'chargeback.externalReference', 'error.chargeback.externalReference'))),
            'amount' => self::numeric_value(self::value($data, array('amount', 'error.amount'))),
            'currency' => self::identifier($field(array('currency', 'error.currency'))),
            'dispute_date' => self::identifier($field(array('disputeDate', 'transactionDate', 'error.disputeDate', 'error.transactionDate'))),
            'gateway_status' => self::identifier($field(array('status', 'error.status'))),
            'dispute_id' => self::identifier($field(array('disputeId', 'chargebackId', 'error.disputeId', 'error.chargebackId', 'chargeback.disputeId', 'chargeback.id', 'error.chargeback.disputeId', 'error.chargeback.id'))),
            'dispute_status' => self::identifier($field(array('disputeStatus', 'error.disputeStatus', 'chargeback.disputeStatus', 'chargeback.status', 'error.chargeback.disputeStatus', 'error.chargeback.status'))),
            'chargeback_status' => self::identifier($field(array('chargebackStatus', 'error.chargebackStatus', 'chargeback.chargebackStatus', 'error.chargeback.chargebackStatus'))),
            'credit_note_id' => self::identifier($field(array('creditNoteId', 'error.creditNoteId', 'chargeback.creditNoteId', 'creditNote.id', 'error.chargeback.creditNoteId', 'error.creditNote.id'))),
            'credit_note_number' => self::identifier($field(array('creditNoteNumber', 'error.creditNoteNumber', 'chargeback.creditNoteNumber', 'creditNote.number', 'error.chargeback.creditNoteNumber', 'error.creditNote.number'))),
            'customer_risk_hold_id' => self::identifier($field(array('customerRiskHoldId', 'holdId', 'customerRiskHold.id', 'error.customerRiskHoldId', 'error.holdId', 'error.customerRiskHold.id'))),
            'customer_risk_action' => self::action($field(array('customerRiskAction', 'action', 'customerRiskHold.action', 'error.customerRiskAction', 'error.action', 'error.customerRiskHold.action'))),
            'customer_risk_reason' => self::identifier($field(array('customerRiskReason', 'reason', 'customerRiskHold.reason', 'error.customerRiskReason', 'error.reason', 'error.customerRiskHold.reason'))),
            'allowed_provider_types' => self::identifier_list(self::array_value($data, array('allowedProviderTypes', 'customerRiskHold.allowedProviderTypes', 'error.allowedProviderTypes', 'error.customerRiskHold.allowedProviderTypes'))),
            'allowed_provider_ids' => self::identifier_list(self::array_value($data, array('allowedProviderIds', 'customerRiskHold.allowedProviderIds', 'error.allowedProviderIds', 'error.customerRiskHold.allowedProviderIds'))),
        );
    }

    public static function customer_message(array $context, $fallback, array $messages = array())
    {
        $messages += array(
            'CHECKOUT_BLOCKED_BY_DISPUTE' => 'Payment cannot be started because an unresolved dispute is being reviewed. Please contact support.',
            'CHECKOUT_BLOCKED_BY_CUSTOMER_HOLD' => 'Payment cannot be started because this customer account is under merchant review. Please contact support.',
            'CHECKOUT_RESTRICTED_BY_CUSTOMER_HOLD' => 'Only bank transfer payment methods are available for this account. Please choose an available bank transfer option or contact support.',
        );
        $code = isset($context['code']) ? (string)$context['code'] : '';
        $message = isset($messages[$code]) ? $messages[$code] : trim((string)$fallback);
        if ($message === '') {
            $message = 'Payment session creation failed due to an unexpected gateway response.';
        }
        return $message . (!empty($context['request_id']) ? ' Request ID: ' . $context['request_id'] : '');
    }

    public static function log_context(array $context, array $extra = array())
    {
        $result = array();
        foreach (array('source', 'order_id', 'event_key', 'reason') as $key) {
            $value = array_key_exists($key, $extra) ? self::identifier($extra[$key]) : '';
            if ($value !== '') {
                $result[$key] = $value;
            }
        }
        foreach (array('response_code', 'raw_body_length') as $key) {
            if (array_key_exists($key, $extra) && is_numeric($extra[$key])) {
                $result[$key] = (int)$extra[$key];
            }
        }
        foreach (array('http_status', 'code', 'request_id', 'transaction_id', 'external_reference', 'amount', 'currency', 'dispute_date', 'gateway_status', 'dispute_id', 'dispute_status', 'chargeback_status', 'credit_note_id', 'credit_note_number', 'customer_risk_hold_id', 'customer_risk_action', 'customer_risk_reason', 'allowed_provider_types', 'allowed_provider_ids') as $key) {
            if (array_key_exists($key, $context) && $context[$key] !== '' && $context[$key] !== null && $context[$key] !== array()) {
                $result[$key] = $context[$key];
            }
        }
        return $result;
    }

    private static function object_data($response_body)
    {
        if (is_array($response_body)) {
            return self::is_object_array($response_body) ? $response_body : array();
        }
        if (!is_string($response_body) || trim($response_body) === '') {
            return array();
        }
        $decoded = json_decode($response_body, true);
        return is_array($decoded) && self::is_object_array($decoded) ? $decoded : array();
    }

    private static function is_object_array(array $value)
    {
        return $value === array() || array_keys($value) !== range(0, count($value) - 1);
    }

    private static function value(array $data, array $paths)
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    continue 2;
                }
                $value = $value[$part];
            }
            return $value;
        }
        return null;
    }

    private static function scalar(array $data, array $paths)
    {
        $value = self::value($data, $paths);
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private static function array_value(array $data, array $paths)
    {
        $value = self::value($data, $paths);
        return is_array($value) ? $value : array();
    }

    private static function identifier($value, $max_length = self::MAX_IDENTIFIER_LENGTH)
    {
        $value = trim((string)$value);
        if ($value === '' || strlen($value) > $max_length || !preg_match('/\A[A-Za-z0-9._:-]+\z/', $value)) {
            return '';
        }
        return $value;
    }

    private static function action($value)
    {
        $value = self::identifier($value);
        return in_array($value, array('block_all', 'manual_review', 'allow_provider_types'), true) ? $value : '';
    }

    private static function identifier_list(array $values)
    {
        $result = array();
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $identifier = self::identifier($value, self::MAX_PROVIDER_LENGTH);
            if ($identifier === '' || in_array($identifier, $result, true)) {
                continue;
            }
            $result[] = $identifier;
            if (count($result) >= self::MAX_PROVIDER_COUNT) {
                break;
            }
        }
        return $result;
    }

    private static function numeric_value($value)
    {
        return is_numeric($value) ? $value + 0 : null;
    }
}

if (!class_exists('WC_Payment_Gateway_App_Api_Error_Context', false)) {
    class_alias('Payment_Gateway_App_Api_Error_Context', 'WC_Payment_Gateway_App_Api_Error_Context');
}
