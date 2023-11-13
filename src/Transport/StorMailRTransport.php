<?php

namespace App\Transport;

use App\Enum\EmailPriorityEnum;
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

        $recipients = [];
        foreach ($message->getEnvelope()->getRecipients() as $to) {
            $recipients[] = [
                'email' => $to->getAddress(),
                'name'  => $to->getName() ?? ''
            ];
        }
        $sender = $message->getEnvelope()->getSender();

        $slugger = new AsciiSlugger();


        $data = [
            'from_email' => $sender->getAddress(),
            'from_name'  => $sender->getName(),
            'priority'   => $priority,
            'campaign'   => $slugger->slug('GDI - ' . (new DateTime())->format('Y-m-d')),
            'recipients' => $recipients,
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
                $protocol . '://'. $this->dsn->getHost() . '/api/v1/emails',
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
