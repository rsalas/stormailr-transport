<?php

namespace Rsalas\StormailrTransport\Transport;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

class StorMailRTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if (str_contains($dsn->getScheme(), 'stormailr')) {
            return new StorMailRTransport($dsn, $this->dispatcher, $this->logger);
        }

        throw new UnsupportedSchemeException($dsn, 'null', $this->getSupportedSchemes());
    }

    protected function getSupportedSchemes(): array
    {
        return ['stormailr', 'stormailr+http', 'stormailr+https'];
    }

}
