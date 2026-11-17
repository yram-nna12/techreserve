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
                ->with('error', 'Choose a supported mock payment method.');
        }

        try {
            $payment = (new PaymentService())->initiate((int) session()->get('user_id'), $reservationId, $method);
            if ($payment['payment_status'] === 'pending' && is_string($payment['checkout_url'] ?? null)) {
                return redirect()->to($payment['checkout_url']);
            }
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('success', $this->statusMessage($payment['payment_status']));
        } catch (DomainException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))->with('error', $exception->getMessage());
        } catch (PaymentGatewayException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('error', $exception->getMessage());
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
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('success', $this->statusMessage($payment['payment_status']));
        } catch (DomainException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))->with('error', $exception->getMessage());
        } catch (PaymentGatewayException $exception) {
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('error', 'No new status was applied. The saved payment status is unchanged; try verification again later.');
        } catch (Throwable $exception) {
            log_message('warning', 'Payment status could not be verified ({exceptionClass}).', ['exceptionClass' => $exception::class]);
            return redirect()->to(site_url('borrower/reservations/' . $reservationId))
                ->with('error', 'Payment status could not be confirmed. The saved status is unchanged; check again when the gateway is available.');
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
            return redirect()->to(site_url('borrower/reservations/' . $payment['reservation_id']))
                ->with('success', $this->statusMessage($payment['payment_status']));
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
            'paid' => 'The gateway confirmed payment.',
            'failed' => 'The gateway confirmed that payment failed. You may start a new attempt.',
            'expired' => 'The gateway confirmed that the payment attempt expired. You may start a new attempt.',
            default => 'The gateway confirms that payment is still pending.',
        };
    }
}
