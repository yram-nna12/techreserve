<?php

namespace App\Controllers;

use App\Libraries\PaymentGatewayException;
use App\Services\PaymentService;
use DomainException;
use Throwable;

class PaymentController extends BaseController
{
    public function initiate(int $reservationId)
    {
        $method = $this->request->getPost('payment_method');
        if (!is_string($method)) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('error', 'Choose a supported payment method.');
        }

        try {
            $payment = (new PaymentService())->initiate((int) session()->get('user_id'), $reservationId, $method);
            if ($payment['payment_status'] === 'pending' && is_string($payment['checkout_url'] ?? null)) {
                return redirect()->to($payment['checkout_url']);
            }
            return redirect()->to(site_url('borrower/reservations/' . $reservationId) . '#payment-information')
                ->with('payment_success', $this->statusMessage($payment['payment_status']));
        } catch (DomainException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))->with('error', $exception->getMessage());
        } catch (PaymentGatewayException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId) . '#payment-information')
                ->with('payment_error', 'We could not resume your existing payment. Please check its status before attempting another payment.');
        } catch (Throwable $exception) {
            log_message('error', 'Payment initiation could not be completed ({exceptionClass}).', ['exceptionClass' => $exception::class]);
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('error', 'No payment success was assumed. Open the reservation and check the saved payment status before retrying.');
        }
    }

    public function verify(int $reservationId, int $paymentId)
    {
        try {
            $payment = (new PaymentService())->verify((int) session()->get('user_id'), $reservationId, $paymentId);
            return redirect()->to(site_url('borrower/reservations/' . $reservationId) . '#payment-information')
                ->with('payment_success', $this->statusMessage($payment['payment_status']));
        } catch (DomainException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))->with('error', $exception->getMessage());
        } catch (PaymentGatewayException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId) . '#payment-information')
                ->with('payment_error', 'We could not verify your payment at this time. Your existing transaction has been retained. Please try again later.');
        } catch (Throwable $exception) {
            log_message('warning', 'Payment status could not be verified ({exceptionClass}).', ['exceptionClass' => $exception::class]);
            return redirect()->to(site_url('borrower/reservations/' . $reservationId) . '#payment-information')
                ->with('payment_error', 'We could not verify your payment at this time. Your existing transaction has been retained. Please try again later.');
        }
    }

    public function returnFromGateway()
    {
        $reference = $this->request->getGet('payment_reference');
        if (!is_string($reference)) {
            return redirect()->to(site_url('borrower/reservations'))->with('error', 'No payment reference was returned.');
        }

        try {
            $payment = (new PaymentService())->verifyByReference((int) session()->get('user_id'), $reference);
            return redirect()->to(site_url('borrower/reservations/' . $payment['reservation_id']) . '#payment-information')
                ->with('payment_success', $this->statusMessage($payment['payment_status']));
        } catch (DomainException $exception) {
            return redirect()->to(site_url('borrower/reservations'))->with('error', $exception->getMessage());
        } catch (PaymentGatewayException $exception) {
            return redirect()->to(site_url('borrower/reservations'))
                ->with('error', 'Payment status could not be confirmed. Open the reservation and retry verification later.');
        } catch (Throwable $exception) {
            log_message('warning', 'Returned payment status could not be verified ({exceptionClass}).', ['exceptionClass' => $exception::class]);
            return redirect()->to(site_url('borrower/reservations'))->with('error', 'Payment status could not be confirmed. Open the reservation and check again when the gateway is available.');
        }
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'paid' => 'Your payment has been verified and confirmed.',
            'failed' => 'The gateway confirmed that payment failed. You may start a new attempt.',
            'cancelled' => 'The gateway confirmed that payment was cancelled. You may start a new attempt.',
            'expired' => 'The gateway confirmed that the payment attempt expired. You may start a new attempt.',
            default => 'Your payment has not yet been completed. You may continue your existing payment.',
        };
    }
}
