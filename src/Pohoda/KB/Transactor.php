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
use SpojeNet\KbAccountsApi\Selection\TransactionSelection;

/**
 * Transactions handler.
 */
class Transactor extends PohodaBankClient
{
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
                    accountId: $this->accountId,
                    page: $page++,
                    fromDateTime: $this->since,
                    toDateTime: $this->until,
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
        $this->addStageMessage('loading transactions');
        $allTransactions = $this->getTransactions();
        $countTransactions = \count($allTransactions);
        $this->addStatusMessage("{$countTransactions} transactions obtained via API", 'debug');
        $success = 0;

        $this->addStageMessage('importing transactions');

        foreach ($allTransactions as $transaction) {
            $this->takeTransaction($transaction);
            $result = $this->insertTransactionToPohoda();

            if ($result['success'] ?? false) {
                ++$success;
            } elseif ($result['message'] ?? false) {
                $this->addStatusMessage($result['message'], 'error');
            } elseif ($result['messages'] ?? false) {
                foreach ($result['messages'] as $message) {
                    $this->addStatusMessage($message, 'error');
                }
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
        $bankType = match ($transaction->creditDebitIndicator) {
            CreditDebit::Debit => 'expense',
            CreditDebit::Credit => 'receipt',
        };
        // For now is field intNote reserved for transaction ID.
        $intNote = sprintf('%s: %s %s #%s', _('Automatic Import'), Shared::appName(), Shared::appVersion(), $transaction->references->accountServicer);
        $amount = abs($transaction->amount->value);

        $this->setDataValue('intNote', "#{$transaction->references->accountServicer}");
        $this->setDataValue('note', 'Import Job '.Shared::cfg('JOB_ID', 'n/a'));
        $this->setDataValue('datePayment', ($transaction->valueDate ?? $transaction->bookingDate ?? $transaction->lastUpdated)->format(self::$dateTimeFormat));
        $this->setDataValue('dateStatement', (new \DateTimeImmutable())->format(self::$dateTimeFormat));
        $this->setDataValue('bankType', $bankType);
        $this->setDataValue('statementNumber', ['statementNumber' => $transaction->bankTransactionCode->code]);
        $this->setDataValue('account', Shared::cfg('POHODA_BANK_IDS'));

        $transaction->amount->currency === 'CZK'
            ? $this->setDataValue('homeCurrency', ['priceNone' => $amount])
            : $this->setDataValue('foreignCurrency', ['priceSum' => $amount, 'currency' => $transaction->amount->currency]);

        if (isset($transaction->references)) {
            $refs = $transaction->references;
            isset($refs->myDescription) && $this->setDataValue('text', $refs->myDescription);
            isset($refs->variable) && $this->setDataValue('symVar', $refs->variable);
            isset($refs->specific) && $this->setDataValue('symSpec', $refs->specific);
            isset($refs->constant) && $this->setDataValue('symConst', sprintf('%04d', $refs->constant));
        }

        if (isset($transaction->counterParty)) {
            $party = $transaction->counterParty;
            isset($party->name) && $this->setDataValue('partnerIdentity', ['address' => ['name' => $party->name]]);
            isset($party->accountNo, $party->bankCode) && $this->setDataValue('paymentAccount', ['accountNo' => $party->accountNo, 'bankCode' => $party->bankCode]);
        }
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
