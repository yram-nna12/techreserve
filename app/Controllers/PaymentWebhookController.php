<?php

namespace App\Controllers;

use App\Libraries\PaymentWebhookException;
use App\Services\PaymentWebhookService;
use Throwable;

final class PaymentWebhookController extends BaseController
{
    public function receive()
    {
        try {
            $result = (new PaymentWebhookService())->receive(
                $this->request->getBody(),
                $this->request->getHeaderLine('X-Payment-Event-Id'),
                $this->request->getHeaderLine('X-Payment-Timestamp'),
                $this->request->getHeaderLine('X-Payment-Signature')
            );

            return $this->response->setJSON([
                'data' => [
                    'accepted' => true,
                    'duplicate' => $result['duplicate'],
                ],
            ]);
        } catch (PaymentWebhookException $exception) {
            return $this->response->setStatusCode($exception->httpStatus)->setJSON([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Payment webhook processing failed ({exceptionClass}).', [
                'exceptionClass' => $exception::class,
            ]);

            return $this->response->setStatusCode(503)->setJSON([
                'error' => [
                    'code' => 'webhook_processing_unavailable',
                    'message' => 'The payment event could not be processed.',
                ],
            ]);
        }
    }
}
