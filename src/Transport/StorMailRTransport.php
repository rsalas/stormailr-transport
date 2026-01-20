<?php

namespace Rsalas\StormailrTransport\Transport;

use Rsalas\StormailrTransport\Enum\EmailPriorityEnum;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

final class StorMailRTransport extends AbstractTransport
{
    private Dsn $dsn;
    private Client $client;
    private SluggerInterface $slugger;
    private int $timeout;

    public function __construct(
        Dsn $dsn,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null,
        Client $client = null,
        SluggerInterface $slugger = null,
        int $timeout = 30
    ) {
        parent::__construct($dispatcher, $logger);
        $this->dsn = $dsn;
        $this->client = $client ?? new Client();
        $this->slugger = $slugger ?? new AsciiSlugger();
        $this->timeout = $timeout;
    }

    /**
     * @throws GuzzleException
     */
    protected function doSend(SentMessage $message): void
    {
        /** @var TemplatedEmail $original */
        $original = $message->getOriginalMessage();
        
        $this->validateEmail($original);
        
        $priority = $original->getContext()['_priority'] ?? EmailPriorityEnum::LOW;

        $recipients = $this->prepareEmail($original->getTo());
        $cc = $this->prepareEmail($original->getCc());
        $bcc = $this->prepareEmail($original->getBcc());

        $sender = $message->getEnvelope()->getSender();

        $project = $this->dsn->getOption('project');
        $project = $project ? $project . ' - ': '';

        $data = [
            'from_email' => $sender->getAddress(),
            'from_name'  => $sender->getName(),
            'priority'   => $priority,
            'campaign'   => $this->slugger->slug($project . (new DateTime())->format('Y-m-d')),
            'recipients' => $recipients,
            'cc'         => $cc,
            'bcc'        => $bcc,
            'template'   => [
                'subject' => $original->getSubject(),
                'html'    => $original->getHtmlBody(),
                'text'    => $original->getTextBody()
            ]
        ];

        $protocol = str_contains($this->dsn->getScheme(), 'https') ? 'https' : 'http';
        $endpoint = $this->dsn->getOption('endpoint', '/api/v1/emails');
        $url = $protocol . '://' . $this->dsn->getHost() . $endpoint;

        try {
            $response = $this->client->request(
                'POST',
                $url,
                [
                    RequestOptions::HEADERS => $this->generateToken(),
                    RequestOptions::JSON    => $data,
                    RequestOptions::TIMEOUT => $this->timeout
                ]
            );
            
            $this->getLogger()->info('Email sent successfully via StorMailR', [
                'to' => array_column($recipients, 'email'),
                'subject' => $original->getSubject(),
                'status_code' => $response->getStatusCode()
            ]);
        } catch (GuzzleException $e) {
            $errorMessage = sprintf(
                'Failed to send email via StorMailR: %s',
                $e->getMessage()
            );
            $this->getLogger()->error($errorMessage, [
                'exception' => get_class($e),
                'to' => array_column($recipients, 'email'),
                'subject' => $original->getSubject()
            ]);
            throw new TransportException($errorMessage, 0, $e);
        } catch (Exception $e) {
            $errorMessage = sprintf(
                'Unexpected error sending email via StorMailR: %s',
                $e->getMessage()
            );
            $this->getLogger()->error($errorMessage);
            throw new TransportException($errorMessage, 0, $e);
        }
    }

    public function __toString(): string
    {
        $project = $this->dsn->getOption('project');
        $projectInfo = $project ? ' [' . $project . ']' : '';
        return sprintf('stormailr://%s%s', $this->dsn->getHost(), $projectInfo);
    }

    private function prepareEmail(array $listAddress): array
    {
        $recipients = [];
        foreach ($listAddress as $address) {
            $recipients[] = [
                'email' => $address->getAddress(),
                'name'  => $address->getName() ?? ''
            ];
        }

        return $recipients;
    }

    /**
     * @throws Exception
     */
    private function generateToken(): array
    {
        $entropy = bin2hex(random_bytes(32));
        $algorithm = 'sha512';
        $timestamp = time();

        $hash = $this->dsn->getUser() . ':' .
            base64_encode(
                hash_hmac($algorithm, $this->dsn->getUser() . $entropy . $timestamp, $this->dsn->getPassword()) .
                '.' . $entropy .
                '.' . $timestamp
            );

        return [
            'X-AUTH-TOKEN' => $hash,
            'X-AUTH-ALGORITHM' => $algorithm
        ];
    }

    private function validateEmail(TemplatedEmail $email): void
    {
        $hasRecipients = !empty($email->getTo()) || !empty($email->getCc()) || !empty($email->getBcc());
        
        if (!$hasRecipients) {
            throw new TransportException('Email must have at least one recipient (To, Cc, or Bcc)');
        }

        if (empty($email->getSubject())) {
            throw new TransportException('Email must have a subject');
        }

        if (empty($email->getHtmlBody()) && empty($email->getTextBody())) {
            throw new TransportException('Email must have either HTML or text body');
        }
    }
}
