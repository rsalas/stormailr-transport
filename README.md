# StorMailR Transport for Symfony Mailer

Transport bridge para integrar StorMailR con Symfony Mailer.

## Instalación

```bash
composer require rsalas/stormailr-transport
```

## Configuración

### 1. Registrar el Transport Factory

En `config/services.yaml`:

```yaml
services:
    Rsalas\StormailrTransport\Transport\StorMailRTransportFactory:
        parent: mailer.transport_factory.abstract
        tags:
            - { name: mailer.transport_factory }
```

### 2. Configurar el DSN

En tu archivo `.env`:

```env
MAILER_DSN=stormailr+https://user:password@host?project=myproject
```

O en `config/packages/mailer.yaml`:

```yaml
framework:
    mailer:
        dsn: '%env(MAILER_DSN)%'
```

## Uso

```php
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class YourService
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function sendEmail(): void
    {
        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test Email')
            ->text('Plain text content')
            ->html('<p>HTML content</p>');

        $this->mailer->send($email);
    }
}
```

### Prioridades de Email

Puedes especificar la prioridad del email usando el contexto:

```php
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Rsalas\StormailrTransport\Enum\EmailPriorityEnum;

$email = (new TemplatedEmail())
    ->from('sender@example.com')
    ->to('recipient@example.com')
    ->subject('High Priority Email')
    ->context([
        '_priority' => EmailPriorityEnum::HIGH,
        // otros datos del contexto...
    ]);
```

Prioridades disponibles:
- `EmailPriorityEnum::LOW`
- `EmailPriorityEnum::NORMAL`
- `EmailPriorityEnum::HIGH`

## Esquemas soportados

- `stormailr://` - HTTP básico
- `stormailr+http://` - HTTP explícito
- `stormailr+https://` - HTTPS seguro
