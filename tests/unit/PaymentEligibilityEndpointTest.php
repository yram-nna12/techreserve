<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class PaymentEligibilityEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testRejectsUnsignedAndTamperedInquiriesBeforeDatabaseAccess(): void
    {
        $config = config('PaymentGateway');
        $original = $config->webhookSecret;
        try {
            $config->webhookSecret = 'synthetic-only-secret';
            $this->assertSame(401, $this->withBody('{}')->post('/api/v1/payments/eligibility')->response()->getStatusCode());
            $this->assertSame(401, $this->withHeaders(['X-Eligibility-Nonce'=>str_repeat('a', 64), 'X-Eligibility-Timestamp'=>(string) time(), 'X-Eligibility-Signature'=>'v1=invalid'])
                ->withBody('{}')->post('/api/v1/payments/eligibility')->response()->getStatusCode());
        } finally {
            $config->webhookSecret = $original;
        }
    }

    public function testSignedNonceCannotBeReplayed(): void
    {
        $config = config('PaymentGateway'); $original = $config->webhookSecret;
        $secret = 'synthetic-only-secret'; $nonce = bin2hex(random_bytes(32)); $timestamp = (string) time(); $body = '{}';
        $headers = ['X-Eligibility-Nonce'=>$nonce, 'X-Eligibility-Timestamp'=>$timestamp,
            'X-Eligibility-Signature'=>'v1=' . hash_hmac('sha256', "eligibility-request-v1\n".$timestamp."\n".$nonce."\n".$body, $secret)];
        try {
            $config->webhookSecret = $secret;
            $this->assertSame(400, $this->withHeaders($headers)->withBody($body)->post('/api/v1/payments/eligibility')->response()->getStatusCode());
            $this->assertSame(409, $this->withHeaders($headers)->withBody($body)->post('/api/v1/payments/eligibility')->response()->getStatusCode());
        } finally {
            $config->webhookSecret = $original;
            $file = WRITEPATH . 'eligibility-nonces' . DIRECTORY_SEPARATOR . hash('sha256', $nonce);
            if (is_file($file)) unlink($file);
        }
    }
}
