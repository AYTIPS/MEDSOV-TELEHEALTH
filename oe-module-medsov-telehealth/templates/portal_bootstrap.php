<?php

/**
 * Shared patient portal bootstrap for Medsov Telehealth pages.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

$globalsBag = OEGlobalsBag::getInstance();
$session = SessionWrapperFactory::getInstance()->getWrapper();
$siteId = $session->get('site_id', null) ?? ($_GET['site'] ?? 'default');
$webRoot = $globalsBag->getString('web_root');
$landingPage = $webRoot . '/portal/index.php?site=' . urlencode((string)$siteId);

if (!$session->isSymfonySession() || empty($session->get('pid')) || empty($session->get('patient_portal_onsite_two'))) {
    SessionUtil::portalSessionCookieDestroy();
    header('Location: ' . $landingPage . '&w');
    exit;
}

$pid = (int)$session->get('pid');
$ignoreAuth_onsite_portal = true;
global $ignoreAuth_onsite_portal;

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';
require_once __DIR__ . '/../src/Services/MeetingRoomService.php';

return [
    'pid' => $pid,
    'site_id' => (string)$siteId,
    'session' => $session,
    'portal_home_url' => $webRoot . '/portal/home.php?site=' . urlencode((string)$siteId),
];
