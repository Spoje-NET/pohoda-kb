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

const APP_NAME = 'PohodaKBTransactions';
error_reporting(\E_ALL & ~\E_DEPRECATED);

require_once __DIR__.'/../vendor/autoload.php';
/**
 * Get today's transactions list.
 */
$options = getopt('o::e::', ['output::environment::']);
$envFile = $options['environment'] ?? $options['e'] ?? (__DIR__.'/../.env');
Shared::init(
    configKeys: [
        'POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO',
        'ACCOUNT_NUMBER', 'ACCOUNT_ID',
        'ACCESS_TOKEN', 'USE_DOTENV_FOR_CLIENT',
        'POHODA_DEBUG',
    ],
    envFile: $envFile,
);
$report = [];
$kbClient = KbClient::createDefault(envFilePath: Shared::cfg('USE_DOTENV_FOR_CLIENT') ? $envFile : null);

$engine = new Transactor(
    kbClient: $kbClient,
    accessToken: Shared::cfg('ACCESS_TOKEN'),
    accountId: Shared::cfg('ACCOUNT_ID'),
    bankAccount: Shared::cfg('ACCOUNT_NUMBER'),
);
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'yesterday'));
$engine->import();
$exitcode = $engine->getExitCode();

$engine->addStageMessage('saving report');
$destination = __DIR__.'/_report.txt';
$report['exitcode'] = $exitcode;
$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
