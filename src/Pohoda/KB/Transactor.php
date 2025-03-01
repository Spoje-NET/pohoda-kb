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
use SpojeNet\KbAccountsApi\Entity\CreditDebit;
use SpojeNet\KbAccountsApi\Entity\Transaction;
use SpojeNet\KbAccountsApi\Exception\KbClientException;
use SpojeNet\KbAccountsApi\KbClient;
use SpojeNet\KbAccountsApi\Selection\TransactionSelection;

/**
 * Transactions handler.
 */
class Transactor extends PohodaBankClient
{
    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly KbClient $kbClient,
        string $accessToken,
        string $bankAccount,
        array $options = [],
    ) {
        parent::__construct($accessToken, $bankAccount, $options);
    }

    /**
     * Obtain Transactions from RB.
     *
     * @return Transaction[]
     */
    public function getTransactions(): array
    {
        $page = 0;
        $output = [];
        $this->addStatusMessage(sprintf(_('Request transactions from %s to %s'), $this->since->format(self::$dateTimeFormat), $this->until->format(self::$dateTimeFormat)), 'debug');

        try {
            do {
                $transactions = $this->kbClient->transactions($this->accessToken, new TransactionSelection(
                    accountId: $this->getDataValue('account'),
                    page: $page++,
                    fromDate: $this->since,
                    toDate: $this->until,
                ));

                if ($transactions->empty) {
                    $this->addStatusMessage(sprintf(_('No transactions from %s to %s'), $this->since->format(self::$dateTimeFormat), $this->until->format(self::$dateTimeFormat)));

                    return [];
                }

                $output = \array_merge($output, $transactions->content);
            } while ($transactions->last === false);
        } catch (KbClientException $e) {
            $this->addStatusMessage('Exception when calling GetTransactionListApi->getTransactionList: '.$e->getMessage(), 'error', $this->kbClient);

            exit(1);
        }

        return $output;
    }

    /**
     * Import process itself.
     */
    public function import(): void
    {
        // $allMoves = $this->getColumnsFromPohoda('id', ['limit' => 0, 'banka' => $this->bank]);
        $allTransactions = $this->getTransactions();
        $countTransactions = \count($allTransactions);
        $this->addStatusMessage("{$countTransactions} transactions obtained via API", 'debug');
        $success = 0;

        foreach ($allTransactions as $transaction) {
            // $this->dataReset();
            $this->takeTransaction($transaction);

            if ($this->insertTransactionToPohoda()) {
                ++$success;
            }

            $this->reset();
        }

        $this->addStatusMessage("Import done {$success} of {$countTransactions} imported");
    }

    /**
     * Use Transaction data for Bank record.
     */
    public function takeTransaction(Transaction $transaction): void
    {
        // $this->setMyKey(\Pohoda\RO::code('RB' . $transactionData->entryReference));
        $bankType = match ($transaction->creditDebitIndicator) {
            CreditDebit::Debit => 'expense',
            CreditDebit::Credit => 'receipt',
        };
        $this->setDataValue('bankType', $bankType);
        $this->setDataValue('account', Shared::cfg('POHODA_BANK_IDS'));
        $this->setDataValue('datePayment', $transaction->valueDate?->format(self::$dateFormat) ?? $transaction->lastUpdated->format(self::$dateFormat));
        $this->setDataValue('intNote', _('Automatic Import').': '.Shared::appName().' '.Shared::appVersion().' '.($transaction->entryReference ?? ''));
        $this->setDataValue('statementNumber', ['statementNumber' => $transaction->bankTransactionCode->code]);
        $this->setDataValue('symPar', $transaction->entryReference ?? '');

        // $bankRecord = [
        // //    "MOSS" => ['ids' => 'AB'],
        //    'account' => 'KB',
        // //    "accounting",
        // //    "accountingPeriodMOSS",
        // //    "activity" => 'testing',
        //    'bankType' => 'receipt',
        // //    "centre",
        // //    "classificationKVDPH",
        // //    "classificationVAT",
        //    "contract" => 'n/a',
        //    "datePayment" => date('Y-m-d'),
        //    "dateStatement" => date('Y-m-d'),
        // //    "evidentiaryResourcesMOSS",
        //    "intNote" => 'Import works well',
        // //    "myIdentity",
        //    "note" => 'Automated import',
        //    'partnerIdentity' => ['address' => ['street' => 'dlouha'], 'shipToAddress' => ['street' => 'kratka']],
        //    "paymentAccount" => ['accountNo' => '1234', 'bankCode' => '5500'],
        //    'statementNumber' => [
        //        'statementNumber' => (string) time(),
        //    //'numberMovement' => (string) time()
        //    ],
        // //    "symConst" => 'XX',
        // // ?"symPar",
        //    "symSpec" => '23',
        //    "symVar" => (string) time(),
        //    "text" => 'Testing income ' . time(),
        //    'homeCurrency' => ['priceNone' => '1001']
        // ];

        // $this->setDataValue('cisDosle', $transactionData->entryReference);
        if (isset($transaction->references?->variable)) {
            $this->setDataValue('symVar', $transaction->references->variable);
        }

        if (isset($transaction->references?->constant) && (int) $transaction->references->constant) {
            $constantSymbol = sprintf('%04d', $transaction->references->constant);
            // TODO Not exists method ensureKSExists() and class \Pohoda\RO
            // $this->ensureKSExists($constantSymbol);
            // $this->setDataValue('konSym', \Pohoda\RO::code($constantSymbol));
            $this->setDataValue('konSym', $constantSymbol);
        }

        if (isset($transaction->bookingDate)) {
            $this->setDataValue('datVyst', $transaction->bookingDate);
        }

        if (isset($transaction->valueDate)) {
            $this->setDataValue('duzpPuv', $transaction->valueDate);
        }

        if (isset($transaction->references->myDescription)) {
            $this->setDataValue('text', $transaction->references->myDescription);
        }

        $this->setDataValue('note', 'Import Job '.Shared::cfg('JOB_ID', 'n/a'));

        if (isset($transaction->counterParty)) {
            $counterAccount = $transaction->counterParty;

            if (isset($transaction->counterParty->name)) {
                // TODO  $this->setDataValue('nazFirmy', $transaction->counterParty->name);
            }

            $counterAccountNumber = $counterAccount->accountNo;
            $accountNumber = $counterAccountNumber;

            $this->setDataValue('paymentAccount', ['accountNo' => $accountNumber, 'bankCode' => $counterAccount->bankCode]);

            $amount = (string) abs($transaction->amount->value);

            if ($transaction->amount->currency === 'CZK') {
                $this->setDataValue('homeCurrency', ['priceNone' => $amount]);
            } else {
                $this->setDataValue('foreignCurrency', ['priceNone' => $amount]); // TODO: Not tested
            }
        }

        // $this->setDataValue('source', $this->sourceString());
        // echo $this->getJsonizedData() . "\n";
    }

    /**
     * Prepare processing interval.
     *
     * @throws \Exception
     */
    public function setScope(string $scope): void
    {
        switch ($scope) {
            case 'today':
                $this->since = (new \DateTimeImmutable())->setTime(0, 0);
                $this->until = (new \DateTimeImmutable())->setTime(23, 59, 59, 999);

                break;
            case 'yesterday':
                $this->since = (new \DateTimeImmutable('yesterday'))->setTime(0, 0);
                $this->until = (new \DateTimeImmutable('yesterday'))->setTime(23, 59, 59, 999);

                break; // TODO Why no break?
            case 'last_week':
                $this->since = new \DateTimeImmutable('first day of last week');
                $this->until = new \DateTimeImmutable('last day of last week');

                break;
            case 'auto':
                //  $latestRecord = $this->getColumnsFromPohoda(['id', 'lastUpdate'], ['limit' => 1, 'order' => 'lastUpdate@A', 'source' => $this->sourceString(), 'banka' => $this->bank]);
                //
                //  if (\array_key_exists(0, $latestRecord) && \array_key_exists('lastUpdate', $latestRecord[0])) {
                //      $this->since = $latestRecord[0]['lastUpdate'];
                //  } else {
                //      $this->addStatusMessage('Previous record for "auto since" not found. Defaulting to today\'s 00:00', 'warning');
                //      $this->since = (new \DateTime('yesterday'))->setTime(0, 0);
                //  }

                $this->since = (new \DateTimeImmutable('89 days ago'))->setTime(0, 0);
                $this->until = new \DateTimeImmutable();

                break;

            default:
                if (str_contains($scope, '>')) {
                    [$begin, $end] = explode('>', $scope, limit: 2);
                    $this->since = new \DateTimeImmutable($begin);
                    $this->until = new \DateTimeImmutable($end);
                } else {
                    if (\preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/', $scope)) {
                        $this->since = (new \DateTimeImmutable($scope))->setTime(0, 0);
                        $this->until = (new \DateTimeImmutable($scope))->setTime(23, 59, 59, 999);

                        break;
                    }

                    throw new \Exception('Unknown scope '.$scope);
                }

                break;
        }

        if (!\in_array($scope, ['auto', 'today', 'yesterday'], strict: true)) {
            $this->since = $this->since->setTime(0, 0);
            $this->until = $this->until->setTime(23, 59, 59, 999);
        }
    }
}
