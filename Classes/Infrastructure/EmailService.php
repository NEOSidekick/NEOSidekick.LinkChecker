<?php

namespace NEOSidekick\LinkChecker\Infrastructure;

use NEOSidekick\LinkChecker\Domain\Notification\NotificationServiceInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\FluidAdaptor\View\StandaloneView;
use Neos\SwiftMailer\Message;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class EmailService implements NotificationServiceInterface
{
    public const LOG_LEVEL_NONE = 'none';
    public const LOG_LEVEL_LOG = 'log';
    public const LOG_LEVEL_THROW = 'throw';

    /**
     * @var LoggerInterface
     * @Flow\Inject
     */
    protected $logger;

    /**
     * @var ConfigurationManager
     * @Flow\Inject
     */
    protected $configurationManager;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="notifications.mail.sender")
     */
    protected $sender;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="notifications.mail.recipient")
     */
    protected $recipient;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="notifications.mail.ccRecipient")
     */
    protected $ccRecipient = [];

    /**
     * @var array
     * @Flow\InjectConfiguration(path="notifications.mail.template")
     */
    protected $template;

    /**
     * @var string
     * @Flow\InjectConfiguration(path="notifications.mail.logging.errors")
     */
    protected $logErrors;

    /**
     * @var string
     * @Flow\InjectConfiguration(path="notifications.mail.logging.success")
     */
    protected $logSuccess;

    public function sendNotification(string $subject, array $variables = []): void
    {
        try {
            $this->sendEmail($subject, $variables);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
        }
    }

    /**
     * @param string $subject
     * @param array $variables
     * @param string|array $sender
     * @param string|array $recipient
     * @param string|array|null $ccRecipient
     * @return bool
     * @throws \Exception
     */
    public function sendEmail(
        string $subject,
        array $variables = [],
        $sender = 'default',
        $recipient = 'default',
        $ccRecipient = 'default'
    ): bool {
        $plainTextBody = $this->renderEmailBody('txt', $variables);
        $htmlBody = $this->renderEmailBody('html', $variables);
        $senderAddress = $this->resolveSenderAddress($sender);
        $recipientAddress = $this->resolveRecipientAddress($recipient);
        $ccRecipientAddress = $this->resolveCcRecipientAddress($ccRecipient);

        $mail = new Message();
        $mail->setFrom($senderAddress)
            ->setTo($recipientAddress)
            ->setSubject($subject)
            ->setBody($plainTextBody)
            ->addPart($htmlBody, 'text/html');

        if ($ccRecipientAddress !== []) {
            $mail->setCc($ccRecipientAddress);
        }

        return $this->sendMail($mail);
    }

    /**
     * Renders the email body of a template.
     */
    public function renderEmailBody(string $format, array $variables): string
    {
        $packageName = $this->template['package'] ?? 'NEOSidekick.LinkChecker';
        $templateName = $this->template['file'] ?? 'NotificationMail';
        $rootPath = sprintf('resource://%s/Private/Notification/', $packageName);
        $templatePathAndFilename = $rootPath . sprintf('Templates/%s.%s', $templateName, $format);

        $standaloneView = new StandaloneView();
        $request = $standaloneView->getRequest();
        $request->setFormat($format);

        $standaloneView->setTemplatePathAndFilename($templatePathAndFilename);
        $standaloneView->setLayoutRootPath($rootPath . 'Layouts');
        $standaloneView->setPartialRootPath($rootPath . 'Partials');
        $standaloneView->assignMultiple($variables);

        return $standaloneView->render();
    }

    /**
     * @param array|string $sender
     * @return array
     */
    protected function resolveSenderAddress($sender): array
    {
        return $this->resolveAddress($sender, $this->sender, 'senderAddresses');
    }

    /**
     * @param array|string $recipient
     * @return array
     */
    protected function resolveRecipientAddress($recipient): array
    {
        return $this->resolveAddress($recipient, $this->recipient, 'recipientAddresses');
    }

    /**
     * @param array|string|null $ccRecipient
     * @return array
     */
    protected function resolveCcRecipientAddress($ccRecipient): array
    {
        if ($ccRecipient === null || $ccRecipient === []) {
            return [];
        }
        if ($ccRecipient === 'default' && !isset($this->ccRecipient['default'])) {
            return [];
        }
        return $this->resolveAddress($ccRecipient, $this->ccRecipient, 'ccRecipientAddresses');
    }

    /**
     * @param array|string $addressKeyOrAddresses
     * @param array $addressesConfig
     * @param string $description
     * @return array
     * @throws \RuntimeException
     */
    protected function resolveAddress($addressKeyOrAddresses, array $addressesConfig, string $description): array
    {
        if (is_array($addressKeyOrAddresses)) {
            return $addressKeyOrAddresses;
        }
        if (!isset($addressesConfig[$addressKeyOrAddresses])) {
            $errorMessage = 'The given address string was not found in config. Please check config path';
            $errorMessage .= ' "NEOSidekick.LinkChecker.%s.%s".';
            throw new \RuntimeException(sprintf($errorMessage, $description, $addressKeyOrAddresses), 1540192171);
        }
        if (!isset($addressesConfig[$addressKeyOrAddresses]['name'], $addressesConfig[$addressKeyOrAddresses]['address'])) {
            $errorMessage = 'The given sender is not correctly configured - "name" or "address" are missing.';
            $errorMessage .= ' Please check config path "NEOSidekick.LinkChecker.%s.%s".';
            throw new \RuntimeException(sprintf($errorMessage, $description, $addressKeyOrAddresses), 1540192180);
        }
        return [$addressesConfig[$addressKeyOrAddresses]['address'] => $addressesConfig[$addressKeyOrAddresses]['name']];
    }

    /**
     * Sends a mail and logs or throws any errors
     *
     * @throws \Exception
     */
    protected function sendMail(Message $mail): bool
    {
        $allRecipients = array_merge($mail->getTo() ?? [], $mail->getCc() ?? [], $mail->getBcc() ?? []);
        $totalNumberOfRecipients = \count($allRecipients);
        $actualNumberOfRecipients = 0;
        $exceptionMessage = '';

        try {
            $actualNumberOfRecipients = $mail->send();
        } catch (\Exception $exception) {
            $exceptionMessage = $exception->getMessage();
            if ($this->logErrors === self::LOG_LEVEL_LOG) {
                $this->logger->error($exception->getMessage());
            } elseif ($this->logErrors === self::LOG_LEVEL_THROW) {
                throw $exception;
            }
        }

        $emailInfo = [
            'recipients' => array_keys($mail->getTo()),
            'ccRecipients' => array_keys($mail->getCc() ?? []),
            'failedRecipients' => $mail->getFailedRecipients(),
            'subject' => $mail->getSubject(),
            'id' => (string)$mail->getHeaders()->get('Message-ID')
        ];
        if ($exceptionMessage !== '') {
            $emailInfo['exception'] = $exceptionMessage;
        }

        if ($actualNumberOfRecipients < $totalNumberOfRecipients && $this->logErrors === self::LOG_LEVEL_LOG) {
            $this->logger->error(
                sprintf('Could not send an email to all given recipients. Given %s, sent to %s', $totalNumberOfRecipients, $actualNumberOfRecipients),
                $emailInfo
            );
            return false;
        }

        if ($this->logSuccess === self::LOG_LEVEL_LOG) {
            $this->logger->info('Email sent successfully.', $emailInfo);
        }
        return true;
    }
}
