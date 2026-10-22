<?php

declare(strict_types=1);
/**
 * Enom domain registrar adapter for FOSSBilling.
 *
 * Uses the Enom reseller API (interface.asp, responsetype=xml).
 * Enom requires the calling server's IP to be whitelisted for API access.
 *
 * SPDX-License-Identifier: Apache-2.0.
 */

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

class Registrar_Adapter_Enom extends Registrar_AdapterAbstract
{
    public $config = [
        'uid' => null,
        'pw' => null,
    ];

    public function __construct($options)
    {
        foreach (['uid' => 'Login ID', 'pw' => 'API Token'] as $key => $label) {
            if (empty($options[$key])) {
                throw new Registrar_Exception('The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing', [':domain_registrar' => 'Enom', ':missing' => $label], 3001);
            }
            $this->config[$key] = $options[$key];
        }
    }

    public static function getConfig(): array
    {
        return [
            'label' => 'Manages domains on Enom via the reseller API. Whitelist this server\'s IP address in your Enom account (Resellers > API) before use.',
            'form' => [
                'uid' => [
                    'text', [
                        'label' => 'Enom Login ID',
                        'description' => 'The login ID of your Enom reseller account.',
                        'required' => true,
                    ],
                ],
                'pw' => [
                    'password', [
                        'label' => 'API Token',
                        'description' => 'API token generated in the Enom control panel (your account password also works, but a token is recommended).',
                        'required' => true,
                        'secret' => true,
                    ],
                ],
            ],
        ];
    }

    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $result = $this->_request('check', $this->_domainParams($domain), false);

        // 210 = available, 211 = not available
        return (string) $this->_find($result, 'RRPCode') === '210';
    }

    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        return !$this->isDomainAvailable($domain);
    }

    public function modifyNs(Registrar_Domain $domain): bool
    {
        $params = $this->_domainParams($domain) + $this->_nsParams($domain);
        if (!isset($params['NS1'])) {
            $params['UseDNS'] = 'default';
        }
        $this->_request('ModifyNS', $params);

        return true;
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        foreach ($this->_contactsByType($domain) as $type => $contact) {
            $params = $this->_domainParams($domain) + ['ContactType' => strtoupper($type)] + $this->_contactParams($type, $contact);
            $this->_request('Contacts', $params);
        }

        return true;
    }

    public function transferDomain(Registrar_Domain $domain): bool
    {
        $params = [
            'OrderType' => 'Autoverification',
            'DomainCount' => 1,
            'SLD1' => strtolower((string) $domain->getSld()),
            'TLD1' => $domain->getTld(false),
            'AuthInfo1' => $domain->getEpp(),
            'UseContacts' => 1,
            'Lock' => 1,
            'Renew' => 1,
        ];
        $result = $this->_request('TP_CreateOrder', $params);

        return $this->_find($result, 'transferorderid') !== null || $this->_find($result, 'TransferOrderID') !== null;
    }

    public function getDomainDetails(Registrar_Domain $domain): Registrar_Domain
    {
        $info = $this->_request('GetDomainInfo', $this->_domainParams($domain));

        $expiration = $this->_find($info, 'expiration');
        if ($expiration !== null) {
            $time = strtotime((string) $expiration);
            $domain->setExpirationTime($time === false ? null : $time);
        }

        $contacts = $this->_request('GetContacts', $this->_domainParams($domain));
        $map = ['Registrant' => 'setContactRegistrar', 'Admin' => 'setContactAdmin', 'Tech' => 'setContactTech', 'AuxBilling' => 'setContactBilling'];
        foreach ($map as $type => $setter) {
            $c = new Registrar_Domain_Contact();
            $c->setFirstName((string) $this->_find($contacts, $type . 'FirstName'));
            $c->setLastName((string) $this->_find($contacts, $type . 'LastName'));
            $c->setCompany((string) $this->_find($contacts, $type . 'OrganizationName'));
            $c->setEmail((string) $this->_find($contacts, $type . 'EmailAddress'));
            $c->setTel((string) $this->_find($contacts, $type . 'Phone'));
            $c->setAddress1((string) $this->_find($contacts, $type . 'Address1'));
            $c->setAddress2((string) $this->_find($contacts, $type . 'Address2'));
            $c->setCity((string) $this->_find($contacts, $type . 'City'));
            $c->setState((string) $this->_find($contacts, $type . 'StateProvince'));
            $c->setCountry((string) $this->_find($contacts, $type . 'Country'));
            $c->setZip((string) $this->_find($contacts, $type . 'PostalCode'));
            $domain->$setter($c);
        }

        $dns = $this->_request('GetDNS', $this->_domainParams($domain));
        $nameservers = $dns->xpath('//dns') ?: [];
        for ($i = 1; $i <= 4; ++$i) {
            if (isset($nameservers[$i - 1])) {
                $domain->{'setNs' . $i}(strtolower((string) $nameservers[$i - 1]));
            }
        }

        try {
            $domain->setLocked($this->getLock($domain));
            $domain->setPrivacyEnabled($this->_getPrivacy($domain)['enabled']);
        } catch (Registrar_Exception) {
            // Lock and privacy status are informational; ignore lookup failures.
        }

        return $domain;
    }

    /**
     * Enom does not return the EPP code via the API; it is emailed to the registrant.
     */
    public function getEpp(Registrar_Domain $domain): string
    {
        $this->_request('SynchAuthInfo', $this->_domainParams($domain) + ['EmailEPP' => 'True', 'RunSynchAutoInfo' => 'True']);

        throw new Registrar_Exception('The transfer code for :domain has been emailed to the registrant contact.', [':domain' => $domain->getName()]);
    }

    public function registerDomain(Registrar_Domain $domain): bool
    {
        $params = $this->_domainParams($domain) + ['NumYears' => $domain->getRegistrationPeriod() ?: 1, 'IgnoreNSFail' => 'Yes'];

        $ns = $this->_nsParams($domain);
        $params += $ns ?: ['UseDNS' => 'default'];

        foreach ($this->_contactsByType($domain) as $type => $contact) {
            $params += $this->_contactParams($type, $contact);
        }

        if ($domain->getTld() === '.us') {
            $params['us_nexus'] = 'C11';
            $params['us_purpose'] = 'P3';
            $params['global_cc_us'] = 'US';
        }

        $result = $this->_request('Purchase', $params);

        return (string) $this->_find($result, 'RRPCode') === '200';
    }

    public function renewDomain(Registrar_Domain $domain): bool
    {
        $params = $this->_domainParams($domain) + ['NumYears' => $domain->getRegistrationPeriod() ?: 1];
        $result = $this->_request('Extend', $params);

        return (string) $this->_find($result, 'RRPCode') === '200';
    }

    /**
     * Enom only allows deleting a registration within the 5-day add grace period.
     */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        $this->_request('DeleteRegistration', $this->_domainParams($domain) + ['EndUserIP' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);

        return true;
    }

    /**
     * Turns on ID Protect (WPPS). If the domain has no ID Protect subscription yet,
     * one year is purchased on the Enom account first.
     */
    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        $privacy = $this->_getPrivacy($domain);
        if ($privacy['enabled']) {
            return true;
        }
        if (!$privacy['exists']) {
            $this->_request('PurchaseServices', $this->_domainParams($domain) + ['Service' => 'WPPS', 'NumYears' => 1]);
        }
        $this->_request('EnableServices', $this->_domainParams($domain) + ['Service' => 'WPPS']);

        return true;
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        if (!$this->_getPrivacy($domain)['enabled']) {
            return true;
        }
        $this->_request('DisableServices', $this->_domainParams($domain) + ['Service' => 'WPPS']);

        return true;
    }

    public function getLock(Registrar_Domain $domain): bool
    {
        $result = $this->_request('GetRegLock', $this->_domainParams($domain));
        $lock = $this->_find($result, 'reg-lock') ?? $this->_find($result, 'RegLock');

        return (string) $lock === '1';
    }

    public function lock(Registrar_Domain $domain): bool
    {
        $this->_request('SetRegLock', $this->_domainParams($domain) + ['UnlockRegistrar' => 0]);
        $domain->setLocked(true);

        return true;
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        $this->_request('SetRegLock', $this->_domainParams($domain) + ['UnlockRegistrar' => 1]);
        $domain->setLocked(false);

        return true;
    }

    private function _getPrivacy(Registrar_Domain $domain): array
    {
        $result = $this->_request('GetWPPSInfo', $this->_domainParams($domain), false);

        return [
            'exists' => in_array(strtolower((string) $this->_find($result, 'WPPSExists')), ['1', 'true', 'yes'], true),
            'enabled' => in_array(strtolower((string) $this->_find($result, 'WPPSEnabled')), ['1', 'true', 'yes'], true),
        ];
    }

    private function _domainParams(Registrar_Domain $domain): array
    {
        return ['SLD' => strtolower((string) $domain->getSld()), 'TLD' => $domain->getTld(false)];
    }

    private function _nsParams(Registrar_Domain $domain): array
    {
        $params = [];
        $n = 1;
        foreach ([$domain->getNs1(), $domain->getNs2(), $domain->getNs3(), $domain->getNs4()] as $ns) {
            if (!empty($ns)) {
                $params['NS' . $n++] = $ns;
            }
        }

        return $params;
    }

    /**
     * @return array<string, Registrar_Domain_Contact> keyed by Enom contact prefix
     */
    private function _contactsByType(Registrar_Domain $domain): array
    {
        $registrant = $domain->getContactRegistrar();

        return [
            'Registrant' => $registrant,
            'Admin' => $domain->getContactAdmin() instanceof Registrar_Domain_Contact ? $domain->getContactAdmin() : $registrant,
            'Tech' => $domain->getContactTech() instanceof Registrar_Domain_Contact ? $domain->getContactTech() : $registrant,
            'AuxBilling' => $domain->getContactBilling() instanceof Registrar_Domain_Contact ? $domain->getContactBilling() : $registrant,
        ];
    }

    private function _contactParams(string $prefix, Registrar_Domain_Contact $c): array
    {
        $params = [
            $prefix . 'FirstName' => $c->getFirstName(),
            $prefix . 'LastName' => $c->getLastName(),
            $prefix . 'OrganizationName' => $c->getCompany(),
            $prefix . 'Address1' => $c->getAddress1(),
            $prefix . 'Address2' => $c->getAddress2(),
            $prefix . 'City' => $c->getCity(),
            $prefix . 'StateProvince' => $c->getState(),
            $prefix . 'PostalCode' => $c->getZip(),
            $prefix . 'Country' => $c->getCountry(),
            $prefix . 'EmailAddress' => $c->getEmail(),
            $prefix . 'Phone' => '+' . ltrim((string) $c->getTelCc(), '+') . '.' . preg_replace('/\D/', '', (string) $c->getTel()),
        ];
        if ($c->getCompany()) {
            $params[$prefix . 'JobTitle'] = 'Owner';
        }

        return $params;
    }

    /**
     * Case-insensitive lookup of the first element with the given name anywhere in the response.
     */
    private function _find(SimpleXMLElement $xml, string $name): ?SimpleXMLElement
    {
        $lower = strtolower($name);
        $nodes = $xml->xpath("//*[translate(local-name(), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='{$lower}']");

        return $nodes[0] ?? null;
    }

    private function _getApiUrl(): string
    {
        return $this->_testMode
            ? 'https://resellertest.enom.com/interface.asp'
            : 'https://reseller.enom.com/interface.asp';
    }

    /**
     * Perform a call to the Enom API.
     *
     * @param bool $failOnError throw when Enom reports errors (some lookups report "errors" for normal negative answers)
     *
     * @throws Registrar_Exception
     */
    protected function _request(string $command, array $params = [], bool $failOnError = true): SimpleXMLElement
    {
        $query = ['uid' => $this->config['uid'], 'pw' => $this->config['pw'], 'responsetype' => 'xml', 'command' => $command, ...$params];

        $this->getLog()->debug('Enom API REQUEST: ' . $command . ' ' . json_encode($params));

        try {
            $response = $this->getHttpClient()->withOptions(['timeout' => 120])->request('POST', $this->_getApiUrl(), ['body' => $query]);
            $data = $response->getContent();
        } catch (ExceptionInterface $error) {
            $e = new Registrar_Exception("HttpClientException: {$error->getMessage()}.");
            $this->getLog()->error($e->getMessage());

            throw $e;
        }

        $this->getLog()->info('Enom API RESULT: ' . $data);

        $xml = @simplexml_load_string($data);
        if ($xml === false) {
            throw new Registrar_Exception('Invalid response from the :type: registrar API', [':type:' => 'Enom']);
        }

        $errCount = (int) ($this->_find($xml, 'ErrCount') ?? 0);
        if ($failOnError && $errCount > 0) {
            $errors = [];
            foreach ($xml->errors->children() ?? [] as $err) {
                $errors[] = (string) $err;
            }
            $message = implode('; ', array_unique($errors)) ?: 'Unknown error';
            $this->getLog()->error("Enom {$command} error: {$message}");

            throw new Registrar_Exception('Enom :command failed: :message', [':command' => $command, ':message' => $message]);
        }

        return $xml;
    }
}
