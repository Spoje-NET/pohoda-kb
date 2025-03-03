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

require_once __DIR__.'/../vendor/autoload.php';
/**
 * Get today's transactions list.
 */
$options = getopt('o::e::', ['output::environment::']);

Shared::init(
    configKeys: [
        'POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO',
        'ACCOUNT_NUMBER',
        'ACCESS_TOKEN', 'USE_DOTENV_FOR_CLIENT',
    ],
    envFile: \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$report = [];
$kbClient = KbClient::createDefault(envFilePath: Shared::cfg('USE_DOTENV_FOR_CLIENT') ? $envFile : null);

$engine = new Transactor($kbClient, Shared::cfg('ACCESS_TOKEN'), Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'yesterday'));
$engine->import();

$engine->addStatusMessage('stage 6/6: saving report', 'debug');

$report['exitcode'] = $exitcode;
$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
