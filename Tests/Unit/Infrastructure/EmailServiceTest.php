<?php

declare(strict_types=1);

namespace NEOSidekick\LinkChecker\Tests\Unit\Infrastructure;

use NEOSidekick\LinkChecker\Infrastructure\EmailService;
use Neos\Flow\Tests\UnitTestCase;
use Neos\SwiftMailer\Message;

class EmailServiceTest extends UnitTestCase
{
    /** @test */
    public function sendEmailAddsConfiguredDefaultCcRecipient(): void
    {
        $service = $this->createService([
            'default' => [
                'name' => 'Client',
                'address' => 'client@example.com',
            ],
        ]);

        $this->sendEmailWithoutVendorDeprecationOutput($service);

        self::assertSame(
            ['client@example.com' => 'Client'],
            $service->sentMessage->getCc()
        );
    }

    /** @test */
    public function sendEmailLeavesCcEmptyWhenNoDefaultCcRecipientIsConfigured(): void
    {
        $service = $this->createService([]);

        $this->sendEmailWithoutVendorDeprecationOutput($service);

        self::assertSame([], $service->sentMessage->getCc() ?? []);
    }

    private function createService(array $ccRecipient): TestableEmailService
    {
        $service = new TestableEmailService();
        $this->inject($service, 'sender', [
            'default' => [
                'name' => 'Link Checker',
                'address' => 'no-reply@example.com',
            ],
        ]);
        $this->inject($service, 'recipient', [
            'default' => [
                'name' => 'Support',
                'address' => 'support@example.com',
            ],
        ]);
        $this->inject($service, 'ccRecipient', $ccRecipient);
        $this->inject($service, 'template', []);

        return $service;
    }

    private function sendEmailWithoutVendorDeprecationOutput(TestableEmailService $service): void
    {
        ob_start();
        try {
            $service->sendEmail('Link checker results');
        } finally {
            ob_end_clean();
        }
    }
}

class TestableEmailService extends EmailService
{
    public Message $sentMessage;

    public function renderEmailBody(string $format, array $variables): string
    {
        return $format . ' body';
    }

    protected function sendMail(Message $mail): bool
    {
        $this->sentMessage = $mail;
        return true;
    }
}
