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

final class StorMailRTransport extends AbstractTransport
{
    private Dsn $dsn;

    public function __construct(Dsn $dsn, EventDispatcherInterface $dispatcher = null, LoggerInterface $logger = null)
    {
        parent::__construct($dispatcher, $logger);
        $this->dsn = $dsn;
    }

    /**
     * @throws GuzzleException
     */
    protected function doSend(SentMessage $message): void
    {
        /** @var TemplatedEmail $original */
        $original = $message->getOriginalMessage();
        $priority = $original->getContext()['_priority'] ?? EmailPriorityEnum::LOW;

        $recipients = $this->prepareEmail($original->getTo());
        $cc = $this->prepareEmail($original->getCc());
        $bcc = $this->prepareEmail($original->getBcc());

        $sender = $message->getEnvelope()->getSender();

        $slugger = new AsciiSlugger();
        $project = $this->dsn->getOption('project');
        $project = $project ? $project . ' - ': '';

        $data = [
            'from_email' => $sender->getAddress(),
            'from_name'  => $sender->getName(),
            'priority'   => $priority,
            'campaign'   => $slugger->slug($project . (new DateTime())->format('Y-m-d')),
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

        try {
            $client = new Client();
            $client->request(
                'POST',
                $protocol . '://' . $this->dsn->getHost() . '/api/v1/emails',
                [
                    RequestOptions::HEADERS => $this->generateToken(),
                    RequestOptions::JSON    => $data
                ]
            );
        } catch (Exception $e) {
            $this->getLogger()->error($e->getMessage());
            throw new TransportException($e->getMessage());
        }
    }

    public function __toString(): string
    {
        return 'stormailr://';
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
}
