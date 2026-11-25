<?php

namespace App\Controllers;

use App\Services\PaymentRules;
use App\Services\EligibilityNonceStore;
use Config\Database;
use Throwable;

final class PaymentEligibilityController extends BaseController
{
    public function check()
    {
        $secret = config('PaymentGateway')->webhookSecret;
        $body = $this->request->getBody();
        $nonce = $this->request->getHeaderLine('X-Eligibility-Nonce');
        $timestamp = $this->request->getHeaderLine('X-Eligibility-Timestamp');
        $signature = $this->request->getHeaderLine('X-Eligibility-Signature');
        if ($secret === '' || !preg_match('/\A[a-f0-9]{64}\z/', $nonce)
            || !preg_match('/\A[0-9]{10}\z/', $timestamp) || abs(time() - (int) $timestamp) > 30
            || !hash_equals('v1=' . hash_hmac('sha256', "eligibility-request-v1\n" . $timestamp . "\n" . $nonce . "\n" . $body, $secret), $signature)) {
            return $this->response->setStatusCode(401)->setJSON(['error' => 'unauthorized']);
        }
        try {
            if (!EligibilityNonceStore::consume(WRITEPATH . 'eligibility-nonces', $nonce)) {
                return $this->response->setStatusCode(409)->setJSON(['error' => 'replayed_request']);
            }
            $input = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($input) || !is_string($input['transaction_reference'] ?? null)
                || !preg_match('/\ATRX-[a-fA-F0-9]{32}\z/', $input['transaction_reference'])
                || !is_string($input['gateway_transaction_id'] ?? null)) {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'invalid_request']);
            }
            $db = Database::connect();
            $row = $db->table('payments p')->select('p.id, p.reservation_id, p.gateway_transaction_id, p.amount, p.currency, p.payment_method, p.payment_status, r.status AS reservation_status, r.start_datetime, r.total_amount, r.currency AS reservation_currency, u.role AS user_role, u.status AS user_status')
                ->join('reservations r', 'r.id = p.reservation_id')->join('users u', 'u.id = r.user_id')
                ->where('p.transaction_reference', $input['transaction_reference'])->get()->getRowArray();
            $eligible = false;
            $reason = 'transaction_mismatch';
            if ($row && ($row['gateway_transaction_id'] === null || hash_equals($row['gateway_transaction_id'], $input['gateway_transaction_id']))
                && ($input['amount'] ?? null) === $row['amount'] && ($input['currency'] ?? null) === $row['currency']
                && ($input['payment_method'] ?? null) === $row['payment_method'] && $row['amount'] === $row['total_amount']
                && $row['currency'] === $row['reservation_currency']) {
                $doc = $db->table('reservation_documents')->select('status')->where('reservation_id', $row['reservation_id'])->orderBy('id', 'DESC')->get(1)->getRowArray();
                $other = $db->table('payments')->where('reservation_id', $row['reservation_id'])->where('id !=', $row['id'])->whereIn('payment_status', ['pending', 'paid'])->countAllResults();
                $ended = PaymentRules::paymentPeriodEnded($row['start_datetime']);
                $eligible = $row['payment_status'] === 'pending' && $other === 0 && $row['user_role'] === 'borrower'
                    && $row['user_status'] === 'active' && PaymentRules::canInitiate($row['reservation_status'], $row['total_amount'], $row['currency'], $doc['status'] ?? null, $row['start_datetime']);
                $reason = $eligible ? 'eligible' : ($ended ? 'deadline_passed' : 'ineligible');
            }
            $data = ['eligible' => $eligible, 'reason' => $reason, 'transaction_reference' => $input['transaction_reference'],
                'gateway_transaction_id' => $input['gateway_transaction_id'], 'reservation_reference' => $row ? 'RES-' . (int) $row['reservation_id'] : null,
                'amount' => $row['amount'] ?? null, 'currency' => $row['currency'] ?? null,
                'deadline_utc' => $row['start_datetime'] ?? null, 'issued_at' => time()];
            $response = $this->response->setJSON(['data' => $data]);
            return $response->setHeader('X-Eligibility-Signature', 'v1=' . hash_hmac('sha256', "eligibility-response-v1\n" . $nonce . "\n" . $response->getBody(), $secret));
        } catch (Throwable $exception) {
            log_message('error', 'Payment eligibility check failed ({exceptionClass}).', ['exceptionClass' => $exception::class]);
            return $this->response->setStatusCode(503)->setJSON(['error' => 'unavailable']);
        }
    }
}
