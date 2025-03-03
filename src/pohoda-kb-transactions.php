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
$envFile = $argv[1] ?? (__DIR__.'/../.env');
Shared::init(
  configKeys: [
    'POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO',
    'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER',
    'ACCESS_TOKEN', 'USE_DOTENV_FOR_CLIENT'
  ],
  envFile: $envFile,
);
PohodaBankClient::checkCertificate(Shared::cfg('CERT_FILE'), Shared::cfg('CERT_PASS'));

$kbClient = KbClient::createDefault(envFilePath: Shared::cfg('USE_DOTENV_FOR_CLIENT') ? $envFile : null);

$engine = new Transactor($kbClient, Shared::cfg('ACCESS_TOKEN'), Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'yesterday'));
$engine->import();
