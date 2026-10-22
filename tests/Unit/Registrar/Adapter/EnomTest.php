<?php

declare(strict_types=1);

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @param array<string, string> $responses Enom command (lowercase) => XML body
 */
function createEnomAdapter(array $responses, array &$requests = []): Registrar_Adapter_Enom
{
    $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responses, &$requests): MockResponse {
        parse_str($options['body'], $body);
        $requests[] = ['url' => $url, 'body' => $body];

        return new MockResponse($responses[strtolower($body['command'])] ?? enomXml('<ErrCount>1</ErrCount><errors><Err1>Unexpected command</Err1></errors>'));
    });

    $adapter = new class(['uid' => 'reseller', 'pw' => 'token'], $httpClient) extends Registrar_Adapter_Enom {
        public function __construct(array $options, private readonly MockHttpClient $httpClient)
        {
            parent::__construct($options);
        }

        public function getHttpClient(): Symfony\Contracts\HttpClient\HttpClientInterface
        {
            return $this->httpClient;
        }
    };
    $adapter->setLog(new Psr\Log\NullLogger());

    return $adapter;
}

function enomXml(string $inner): string
{
    if (!str_contains($inner, '<ErrCount>')) {
        $inner .= '<ErrCount>0</ErrCount>';
    }

    return '<?xml version="1.0" encoding="utf-8"?><interface-response>' . $inner . '<Done>true</Done></interface-response>';
}

function enomDomain(): Registrar_Domain
{
    return (new Registrar_Domain())->setSld('Example')->setTld('.com');
}

test('the Enom adapter requires a login ID and API token', function (): void {
    new Registrar_Adapter_Enom(['uid' => 'reseller']);
})->throws(Registrar_Exception::class);

test('the API token is marked as a secret field', function (): void {
    expect(Registrar_Adapter_Enom::getConfig()['form']['pw'][1]['secret'])->toBeTrue();
});

test('availability maps Enom RRP codes', function (string $code, bool $available): void {
    $adapter = createEnomAdapter(['check' => enomXml("<RRPCode>{$code}</RRPCode>")]);

    expect($adapter->isDomainAvailable(enomDomain()))->toBe($available);
})->with([['210', true], ['211', false]]);

test('requests are authenticated and sent to the live or test endpoint', function (): void {
    $requests = [];
    $adapter = createEnomAdapter(['check' => enomXml('<RRPCode>210</RRPCode>')], $requests);
    $adapter->isDomainAvailable(enomDomain());
    $adapter->enableTestMode()->isDomainAvailable(enomDomain());

    expect($requests[0]['url'])->toBe('https://reseller.enom.com/interface.asp')
        ->and($requests[1]['url'])->toBe('https://resellertest.enom.com/interface.asp')
        ->and($requests[0]['body'])->toMatchArray(['uid' => 'reseller', 'pw' => 'token', 'responsetype' => 'xml', 'SLD' => 'example', 'TLD' => 'com']);
});

test('Enom errors are raised as registrar exceptions with the Enom message', function (): void {
    $adapter = createEnomAdapter(['getreglock' => enomXml('<ErrCount>1</ErrCount><errors><Err1>Domain name not found</Err1></errors>')]);

    expect(fn () => $adapter->getLock(enomDomain()))->toThrow(Registrar_Exception::class, 'Domain name not found');
});

test('domain details are read from GetDomainInfo, GetContacts, GetDNS, GetRegLock and GetWPPSInfo', function (): void {
    $adapter = createEnomAdapter([
        'getdomaininfo' => enomXml('<GetDomainInfo><status><expiration>5/29/2027 5:55:40 PM</expiration><registrationstatus>Registered</registrationstatus></status></GetDomainInfo>'),
        'getcontacts' => enomXml('<GetContacts><Registrant><RegistrantFirstName>Jane</RegistrantFirstName><RegistrantLastName>Doe</RegistrantLastName><RegistrantCountry>US</RegistrantCountry><RegistrantEmailAddress>jane@example.com</RegistrantEmailAddress></Registrant></GetContacts>'),
        'getdns' => enomXml('<dns>NS1.EXAMPLE.NET</dns><dns>ns2.example.net</dns>'),
        'getreglock' => enomXml('<reg-lock>1</reg-lock>'),
        'getwppsinfo' => enomXml('<GetWPPSInfo><WPPSExists>1</WPPSExists><WPPSEnabled>0</WPPSEnabled></GetWPPSInfo>'),
    ]);

    $domain = $adapter->getDomainDetails(enomDomain());

    expect(date('Y-m-d', $domain->getExpirationTime()))->toBe('2027-05-29')
        ->and($domain->getNs1())->toBe('ns1.example.net')
        ->and($domain->getNs2())->toBe('ns2.example.net')
        ->and($domain->getLocked())->toBeTrue()
        ->and($domain->getPrivacyEnabled())->toBeFalse()
        ->and($domain->getContactRegistrar()->getFirstName())->toBe('Jane')
        ->and($domain->getContactRegistrar()->getCountry())->toBe('US');
});

test('registration sends nameservers, contacts and the requested period', function (): void {
    $requests = [];
    $adapter = createEnomAdapter(['purchase' => enomXml('<RRPCode>200</RRPCode><OrderID>123</OrderID>')], $requests);

    $contact = (new Registrar_Domain_Contact())->setFirstName('Jane')->setLastName('Doe')->setEmail('jane@example.com')
        ->setTelCc('1')->setTel('(555) 010-0000')->setAddress1('1 Main St')->setCity('Denver')->setState('CO')->setZip('80202')->setCountry('US');
    $domain = enomDomain()->setRegistrationPeriod(2)->setNs1('ns1.example.net')->setNs2('ns2.example.net')->setContactRegistrar($contact);

    expect($adapter->registerDomain($domain))->toBeTrue()
        ->and($requests[0]['body'])->toMatchArray([
            'command' => 'Purchase',
            'NumYears' => '2',
            'NS1' => 'ns1.example.net',
            'NS2' => 'ns2.example.net',
            'RegistrantFirstName' => 'Jane',
            'AdminEmailAddress' => 'jane@example.com',
            'RegistrantPhone' => '+1.5550100000',
        ]);
});

test('renewal reports success only for RRP code 200', function (string $code, bool $renewed): void {
    $adapter = createEnomAdapter(['extend' => enomXml("<RRPCode>{$code}</RRPCode>")]);

    expect($adapter->renewDomain(enomDomain()->setRegistrationPeriod(1)))->toBe($renewed);
})->with([['200', true], ['540', false]]);
