<?php

declare(strict_types=1);

/**
 * This file is part of the PohodaKB package
 *
 * https://github.com/Spoje-NET/pohoda-kb
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pohoda\KB;

use Ease\Shared;
use SpojeNet\KbAccountsApi\KbClient;

abstract class PohodaBankClient extends \mServer\Bank
{
    public const ErrorCodeUnknown = 254;
    public const ErrorCodeNotAdded = 400;
    public const ErrorCodeNotProcessed = 401;
    public const ErrorCodeDuplicate = 409;

    /**
     * DateTime Formating eg. 2021-08-01T10:00:00.0Z.
     */
    public static string $dateTimeFormat = 'Y-m-d\\TH:i:s.0\\Z';

    /**
     * DateTime Formating eg. 2021-08-01T10:00:00.0Z.
     */
    public static string $dateFormat = 'Y-m-d';
    public string $currency;
    protected \DateTimeImmutable $since;
    protected ?\DateTimeImmutable $until = null;
    private int $exitCode = 0;

    /**
     * @param array<string, string> $options
     */
    public function __construct(
        protected readonly KbClient $kbClient,
        protected string $accessToken,
        protected string $accountId,
        string $bankAccount,
        array $options = [],
    ) {
        parent::__construct(null, $options);

        $this->setDataValue('account', $bankAccount);
    }

    /**
     * Source identifier.
     */
    public function sourceString(): string
    {
        return \substr(__FILE__.'@'.\gethostname(), offset: -50);
    }

    /**
     * Try to check certificate.
     */
    public static function checkCertificate(string $certFile, string $password): bool
    {
        return self::checkCertificatePresence($certFile) && self::checkCertificatePassword($certFile, $password);
    }

    /**
     * Try to check certificate readability.
     */
    public static function checkCertificatePresence(string $certFile): bool
    {
        if ((\file_exists($certFile) === false) || (\is_readable($certFile) === false)) {
            \fwrite(\STDERR, 'Cannot read specified certificate file: '.$certFile.\PHP_EOL);

            return false;
        }

        return true;
    }

    /**
     * Try to check certificate readability.
     */
    public static function checkCertificatePassword(string $certFile, string $password): bool
    {
        $certContent = \file_get_contents($certFile);

        if (\openssl_pkcs12_read($certContent, $certs, $password) === false) {
            \fwrite(\STDERR, 'Cannot read PKCS12 certificate file: '.$certFile.\PHP_EOL);

            return false;
        }

        return true;
    }

    /**
     * Prepare processing interval.
     *
     * @throws \Exception
     */
    abstract public function setScope(string $scope): void;

    /**
     * Is Record with current remoteNumber already present in Pohoda?
     * It's a bit of a shame, but the transaction ID must be stored in an internal note. No other field is suitable yet.
     *
     * @todo Implement using Pohoda API UserList
     */
    public function checkForTransactionPresence(): bool
    {
        /** @var null|string[] $transactions */
        static $transactions = null;

        if ($transactions === null) {
            $columns = $this->getColumnsFromPohoda(['intNote'], ['dateFrom' => $this->since->format(self::$dateFormat)]);
            $transactions = \array_unique(\array_filter(\array_column($columns, 'intNote')));
        }

        return \in_array($this->getDataValue('intNote'), $transactions, strict: true);
    }

    /**
     * Insert Transaction to Pohoda.
     *
     * @return array<string, mixed>
     */
    public function insertTransactionToPohoda(string $bankIDS = ''): array
    {
        $result = [];
        $transactionId = $this->getDataValue('intNote');

        if ($this->checkForTransactionPresence()) {
            $this->addStatusMessage("Transaction with ID '{$transactionId}' already present in Pohoda", 'warning');
            $result['message'] = "Duplicate transaction: {$transactionId}";
            $result['success'] = false;
            $this->exitCode = self::ErrorCodeDuplicate;
        } else {
            try {
                $cache = $this->getData();
                $result['id'] = $transactionId;
                $this->reset();

                // TODO: $result = $this->sync();
                if ($bankIDS) {
                    $cache['account'] = $bankIDS;
                }

                $this->takeData($cache);

                if ($this->addToPohoda() && $this->commit() && isset($this->response->producedDetails) && \is_array($this->response->producedDetails)) {
                    $producedId = $this->response->producedDetails['id'];
                    $producedNumber = $this->response->producedDetails['number'];
                    $producedAction = $this->response->producedDetails['actionType'];
                    $result['details'] = $this->response->producedDetails;
                    $result['messages'] = $this->response->messages;
                    $this->automaticLiquidation($producedNumber);
                    $this->addStatusMessage('Bank #'.$producedId.' '.$producedAction.' '.$producedNumber, 'success'); // TODO: Parse response for docID
                    $result['success'] = true;
                } else {
                    $result['success'] = false;
                    $resultMessages = $this->messages;
                    $this->exitCode = self::ErrorCodeNotAdded;

                    if (\array_key_exists('error', $resultMessages) && \count($resultMessages['error'])) {
                        foreach ($resultMessages['error'] as $errMsg) {
                            $result['messages'][] = 'error: '.$errMsg;
                        }

                        $this->exitCode = self::ErrorCodeNotProcessed;
                    }
                }
            } catch (\Exception $exc) {
                $result['message'] = $exc->getMessage();
                $result['success'] = false;
                $this->exitCode = $exc->getCode() ?: self::ErrorCodeUnknown;
            }
        }

        return $result;
    }

    /**
     * Enable automatic liquidation.
     *
     * @see https://www.stormware.cz/schema/version_2/liquidation.xsd for details
     * @see https://www.stormware.cz/xml/samples/version_2/import/Banka/Bank_03_v2.0.xml
     * @see https://github.com/riesenia/pohoda/issues/49
     */
    public function automaticLiquidation(mixed $producedNumber): bool
    {
        /*
          <lqd:automaticLiquidation version="2.0">
          <!-- výběr agendy -->
          <lqd:record>
          <!-- Výběr záznamu z agendy agenda -->
          <!-- budou vybrány pouze záznamy/pohyby v agendě, které mají částku k likvidaci > 0kč a dále splňují podmínku filtru -->
          <ftr:filter>
          <!-- výběr záznamů dle čísla účtu --><!-- <ftr:bankAccount> <typ:id>2</typ:id> <typ:ids>CS</typ:ids> </ftr:bankAccount> -->
          <!-- výběr záznamů dle datum pohybu --><!-- <ftr:dateFrom>2022-12-27</ftr:dateFrom> --><!-- datum od --><!-- <ftr:dateTill>2022-12-31</ftr:dateTill> -->
          <!-- datum do --><!-- výběr záznamů dle nové a změně záznamy/pohybu -->
          <!-- <ftr:lastChanges>2023-01-09T08:30:00</ftr:lastChanges> -->
          <!-- záznamy změněné od zadaného data a času -->
          <!-- <ftr:selectedNumbers> <ftr:number> <typ:numberRequested>KB0010003</typ:numberRequested> </ftr:number> </ftr:selectedNumbers> -->
          <!-- <ftr:bankAccount> <typ:id>3</typ:id> </ftr:bankAccount> -->
          </ftr:filter>
          </lqd:record>
          <!-- Výber pravidla párování dokladů -->
          <lqd:ruleOfPairing>
          <typ:id>1</typ:id>
          <!-- <typ:ids>Výpisy</typ:ids> -->
          </lqd:ruleOfPairing>
          </lqd:automaticLiquidation>

               <ftr:selectedNumbers>
                    <ftr:number>
                      <typ:numberRequested>KB0010003</typ:numberRequested>
                    </ftr:number>
                </ftr:selectedNumbers>
         */

        \file_put_contents($this->xmlCache, $this->generateAutomaticLiquidationXML($producedNumber));

        $this->addStatusMessage('Automatic liquidation', 'success');

        $this->setPostFields(\file_get_contents($this->xmlCache));

        if ($this->debug) {
            $this->addStatusMessage('validate request by: xmllint --schema '.\dirname(__DIR__, 3).'/vendor/vitexsoftware/pohoda-connector/doc/xsd/data.xsd '.$this->xmlCache.' --noout', 'debug');
        }

        return $this->performRequest('/xml');
    }

    /**
     * Generate XML for automatic liquidation.
     */
    public function generateAutomaticLiquidationXML(mixed $producedNumber): string
    {
        $companyId = $this->getCompanyId();
        $xmlString = <<<XML
          <?xml version="1.0" encoding="Windows-1250"?>
          <dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
                        xmlns:lqd="http://www.stormware.cz/schema/version_2/automaticLiquidation.xsd"
                        xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd"
                        xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
                        version="2.0" id="01" ico="{$companyId}" application="Tisk"
                        note="aut. livkidace dokladů tisk z programu Pohoda">
          </dat:dataPack>
          XML;

        $xml = new \SimpleXMLElement($xmlString);

        $dataPackItem = $xml->addChild('dat:dataPackItem');
        $dataPackItem->addAttribute('version', '2.0');
        $dataPackItem->addAttribute('id', '001');

        $automaticLiquidation = $dataPackItem->addChild('automaticLiquidation', null, 'http://www.stormware.cz/schema/version_2/automaticLiquidation.xsd');
        $automaticLiquidation->addAttribute('version', '2.0');

        $record = $automaticLiquidation->addChild('record', null, 'http://www.stormware.cz/schema/version_2/automaticLiquidation.xsd');

        $filter = $record->addChild('filter', null, 'http://www.stormware.cz/schema/version_2/filter.xsd');

        $selectedNumbers = $filter->addChild('selectedNumbers');

        $ftrNumber = $selectedNumbers->addChild('number');

        $numberRequested = $ftrNumber->addChild('numberRequested', $producedNumber, 'http://www.stormware.cz/schema/version_2/type.xsd');

        $ruleOfPairing = $automaticLiquidation->addChild('lqd:ruleOfPairing', null, 'http://www.stormware.cz/schema/version_2/automaticLiquidation.xsd');
        $ruleOfPairing->addChild('id', '1', 'http://www.stormware.cz/schema/version_2/type.xsd');

        return $xml->asXML();
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getCompanyId(): string
    {
        return Shared::cfg('POHODA_ICO');
    }

    public function addStageMessage(string $message): void
    {
        static $stage = 0;
        ++$stage;

        $this->addStatusMessage("stage {$stage}: {$message}", 'debug');
    }

    protected function createSymPar(string $entryReference): string
    {
        return \sprintf('%u', \crc32($entryReference));
    }
}
