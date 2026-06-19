<?php

/**
 * Admin setup page for Medsov Telehealth.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../src/MedsovTelehealthGlobalConfig.php';
require_once __DIR__ . '/../src/Services/ModuleService.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Modules\MedsovTelehealth\MedsovTelehealthGlobalConfig;
use OpenEMR\Modules\MedsovTelehealth\Services\ModuleService;

if (!AclMain::aclCheckCore('admin', 'super')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl('Must be an Admin')]);
    exit;
}

$service = new ModuleService();
$saved = false;

if (!empty($_POST['form_save'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $service->saveSettings($_POST);
    $saved = true;
}

$settings = $service->getSettings();
$siteId = $_GET['site'] ?? $_SESSION['site_id'] ?? 'default';
$siteQuery = 'site=' . urlencode((string)$siteId);
$testRoomUrl = $GLOBALS['webroot'] . '/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/waiting_room.php?' . $siteQuery;

function medsov_checked(array $settings, string $key): string
{
    return !empty($settings[$key]) ? ' checked' : '';
}

function medsov_flag_state(array $settings, string $key): string
{
    return !empty($settings[$key]) ? xl('Enabled') : xl('Disabled');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Medsov Telehealth Configuration'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        body {
            background: #f7f7f8;
            color: #231f20;
        }
        .medsov-config {
            max-width: 1180px;
            margin: 1rem auto 2rem;
            padding: 0 1rem;
        }
        .medsov-hero,
        .medsov-panel {
            border: 1px solid #ead7d9;
            border-radius: .5rem;
            background: #fff;
            box-shadow: 0 .5rem 1.25rem rgba(35, 31, 32, .06);
        }
        .medsov-hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1.25rem;
        }
        .medsov-brand {
            display: flex;
            align-items: center;
            gap: .75rem;
            min-width: 0;
        }
        .medsov-mark {
            width: 2rem;
            height: 2rem;
            border-radius: .375rem;
            background: linear-gradient(90deg, #f4212e 0 45%, transparent 45% 55%, #f4212e 55% 100%);
            flex: 0 0 auto;
        }
        .medsov-eyebrow {
            color: #f4212e;
            font-size: .75rem;
            font-weight: 800;
            line-height: 1;
            text-transform: uppercase;
        }
        .medsov-title {
            margin: .25rem 0 0;
            color: #231f20;
            font-size: 1.5rem;
            font-weight: 800;
            line-height: 1.2;
        }
        .medsov-subtitle {
            margin: .375rem 0 0;
            color: #5d5557;
        }
        .medsov-status {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            justify-content: flex-end;
        }
        .medsov-pill {
            display: inline-flex;
            align-items: center;
            gap: .375rem;
            min-height: 2rem;
            padding: .375rem .625rem;
            border: 1px solid #ead7d9;
            border-radius: 999px;
            background: #fffafa;
            color: #231f20;
            font-size: .8rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .medsov-pill.is-on {
            border-color: #b8e2c0;
            background: #eefaf0;
            color: #1f7a3a;
        }
        .medsov-pill.is-off {
            border-color: #f7c3c8;
            background: #fff1f2;
            color: #a70d18;
        }
        .medsov-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(280px, .9fr);
            gap: 1rem;
        }
        .medsov-panel {
            overflow: hidden;
        }
        .medsov-panel-head {
            padding: 1rem 1.25rem;
            border-left: .375rem solid #f4212e;
            border-bottom: 1px solid #ead7d9;
            background: #fffafa;
        }
        .medsov-panel-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
        }
        .medsov-panel-note {
            margin: .25rem 0 0;
            color: #61585a;
            font-size: .875rem;
        }
        .medsov-panel-body {
            padding: 1.25rem;
        }
        .medsov-switch-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: .75rem;
        }
        .medsov-switch {
            display: flex;
            gap: .75rem;
            min-height: 5.75rem;
            padding: .875rem;
            border: 1px solid #ece4e5;
            border-radius: .5rem;
            background: #fbfafb;
        }
        .medsov-switch input {
            margin-top: .25rem;
        }
        .medsov-switch strong {
            display: block;
            color: #231f20;
            font-size: .95rem;
        }
        .medsov-switch span {
            display: block;
            margin-top: .25rem;
            color: #635a5c;
            font-size: .8125rem;
            line-height: 1.35;
        }
        .medsov-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
        .medsov-form-grid .is-wide {
            grid-column: 1 / -1;
        }
        .medsov-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            margin-top: 1rem;
        }
        .medsov-primary,
        .medsov-primary:hover,
        .medsov-primary:focus {
            border-color: #f4212e;
            background: #f4212e;
            color: #fff;
            font-weight: 800;
        }
        .medsov-secondary,
        .medsov-secondary:hover,
        .medsov-secondary:focus {
            border-color: #231f20;
            background: #fff;
            color: #231f20;
            font-weight: 800;
        }
        .medsov-summary-list {
            display: grid;
            gap: .75rem;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .medsov-summary-list li {
            padding: .75rem;
            border: 1px solid #ece4e5;
            border-radius: .5rem;
            background: #fbfafb;
        }
        .medsov-summary-list strong {
            display: block;
            margin-bottom: .25rem;
        }
        .medsov-summary-list span {
            color: #61585a;
            font-size: .875rem;
        }
        @media (max-width: 900px) {
            .medsov-grid,
            .medsov-form-grid {
                grid-template-columns: 1fr;
            }
            .medsov-hero {
                align-items: flex-start;
                flex-direction: column;
            }
            .medsov-status {
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body class="body_top">
<main class="medsov-config">
    <section class="medsov-hero">
        <div class="medsov-brand">
            <span class="medsov-mark" aria-hidden="true"></span>
            <div>
                <div class="medsov-eyebrow"><?php echo xlt('Medsov Telehealth'); ?></div>
                <h1 class="medsov-title"><?php echo xlt('Telehealth Configuration'); ?></h1>
                <p class="medsov-subtitle"><?php echo xlt('Manage virtual care feature flags, Jitsi service settings, notification channels, and session capacity.'); ?></p>
            </div>
        </div>
        <div class="medsov-status" aria-label="<?php echo xla('Current configuration status'); ?>">
            <span class="medsov-pill <?php echo !empty($settings[MedsovTelehealthGlobalConfig::ENABLED]) ? 'is-on' : 'is-off'; ?>">
                <i class="fa fa-power-off" aria-hidden="true"></i>
                <?php echo text(medsov_flag_state($settings, MedsovTelehealthGlobalConfig::ENABLED)); ?>
            </span>
            <span class="medsov-pill <?php echo !empty($settings[MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED]) ? 'is-on' : 'is-off'; ?>">
                <i class="fa fa-users" aria-hidden="true"></i>
                <?php echo !empty($settings[MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED]) ? xlt('Waiting Room On') : xlt('Direct Join'); ?>
            </span>
            <span class="medsov-pill">
                <i class="fa fa-link" aria-hidden="true"></i>
                <?php echo text((string)$settings[MedsovTelehealthGlobalConfig::JITSI_DOMAIN]); ?>
            </span>
        </div>
    </section>

    <?php if ($saved) { ?>
        <div class="alert alert-success"><?php echo xlt('Medsov Telehealth settings saved.'); ?></div>
    <?php } ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">

        <div class="medsov-grid">
            <div>
                <section class="medsov-panel mb-3">
                    <div class="medsov-panel-head">
                        <h2 class="medsov-panel-title"><?php echo xlt('Feature Flags'); ?></h2>
                        <p class="medsov-panel-note"><?php echo xlt('Turn major telehealth behavior on or off without code changes.'); ?></p>
                    </div>
                    <div class="medsov-panel-body">
                        <div class="medsov-switch-grid">
                            <label class="medsov-switch" for="enabled">
                                <input type="checkbox" id="enabled" name="<?php echo attr(MedsovTelehealthGlobalConfig::ENABLED); ?>" value="1"<?php echo medsov_checked($settings, MedsovTelehealthGlobalConfig::ENABLED); ?>>
                                <span>
                                    <strong><?php echo xlt('Enable Medsov Telehealth'); ?></strong>
                                    <span><?php echo xlt('Controls whether the module appears and accepts provider/patient telehealth requests.'); ?></span>
                                </span>
                            </label>
                            <label class="medsov-switch" for="waiting">
                                <input type="checkbox" id="waiting" name="<?php echo attr(MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED); ?>" value="1"<?php echo medsov_checked($settings, MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED); ?>>
                                <span>
                                    <strong><?php echo xlt('Enable Waiting Room'); ?></strong>
                                    <span><?php echo xlt('Requires patients to wait until the assigned provider admits them.'); ?></span>
                                </span>
                            </label>
                            <label class="medsov-switch" for="video">
                                <input type="checkbox" id="video" name="<?php echo attr(MedsovTelehealthGlobalConfig::VIDEO_ENABLED); ?>" value="1"<?php echo medsov_checked($settings, MedsovTelehealthGlobalConfig::VIDEO_ENABLED); ?>>
                                <span>
                                    <strong><?php echo xlt('Enable Video'); ?></strong>
                                    <span><?php echo xlt('Controls video device checks and whether Jitsi starts with video available.'); ?></span>
                                </span>
                            </label>
                            <label class="medsov-switch" for="audio">
                                <input type="checkbox" id="audio" name="<?php echo attr(MedsovTelehealthGlobalConfig::AUDIO_ENABLED); ?>" value="1"<?php echo medsov_checked($settings, MedsovTelehealthGlobalConfig::AUDIO_ENABLED); ?>>
                                <span>
                                    <strong><?php echo xlt('Enable Audio'); ?></strong>
                                    <span><?php echo xlt('Controls microphone checks and whether Jitsi starts with audio available.'); ?></span>
                                </span>
                            </label>
                            <label class="medsov-switch" for="email">
                                <input type="checkbox" id="email" name="<?php echo attr(MedsovTelehealthGlobalConfig::EMAIL_ENABLED); ?>" value="1"<?php echo medsov_checked($settings, MedsovTelehealthGlobalConfig::EMAIL_ENABLED); ?>>
                                <span>
                                    <strong><?php echo xlt('Enable Email Notifications'); ?></strong>
                                    <span><?php echo xlt('Sends provider and patient telehealth emails through OpenEMR SMTP.'); ?></span>
                                </span>
                            </label>
                            <label class="medsov-switch" for="sms">
                                <input type="checkbox" id="sms" name="<?php echo attr(MedsovTelehealthGlobalConfig::SMS_ENABLED); ?>" value="1"<?php echo medsov_checked($settings, MedsovTelehealthGlobalConfig::SMS_ENABLED); ?>>
                                <span>
                                    <strong><?php echo xlt('Enable SMS Notifications'); ?></strong>
                                    <span><?php echo xlt('Reserved for Medsov Notification Module SMS integration.'); ?></span>
                                </span>
                            </label>
                        </div>
                    </div>
                </section>

                <section class="medsov-panel">
                    <div class="medsov-panel-head">
                        <h2 class="medsov-panel-title"><?php echo xlt('Telehealth Service'); ?></h2>
                        <p class="medsov-panel-note"><?php echo xlt('Configure the Jitsi service used to generate embedded meeting sessions.'); ?></p>
                    </div>
                    <div class="medsov-panel-body">
                        <div class="medsov-form-grid">
                            <div class="form-group">
                                <label for="domain"><?php echo xlt('Jitsi Domain'); ?></label>
                                <input class="form-control" id="domain" name="<?php echo attr(MedsovTelehealthGlobalConfig::JITSI_DOMAIN); ?>" value="<?php echo attr($settings[MedsovTelehealthGlobalConfig::JITSI_DOMAIN]); ?>" placeholder="meet.jit.si">
                            </div>
                            <div class="form-group">
                                <label for="max_participants"><?php echo xlt('Maximum Participants'); ?></label>
                                <input class="form-control" id="max_participants" type="number" min="2" max="20" name="<?php echo attr(MedsovTelehealthGlobalConfig::MAX_PARTICIPANTS); ?>" value="<?php echo attr($settings[MedsovTelehealthGlobalConfig::MAX_PARTICIPANTS]); ?>">
                            </div>
                            <div class="form-group is-wide">
                                <label for="base_url"><?php echo xlt('Jitsi Base URL'); ?></label>
                                <input class="form-control" id="base_url" name="<?php echo attr(MedsovTelehealthGlobalConfig::JITSI_BASE_URL); ?>" value="<?php echo attr($settings[MedsovTelehealthGlobalConfig::JITSI_BASE_URL]); ?>" placeholder="https://meet.jit.si">
                            </div>
                            <div class="form-group is-wide">
                                <label for="external_api"><?php echo xlt('Jitsi External API Script'); ?></label>
                                <input class="form-control" id="external_api" name="<?php echo attr(MedsovTelehealthGlobalConfig::JITSI_EXTERNAL_API); ?>" value="<?php echo attr($settings[MedsovTelehealthGlobalConfig::JITSI_EXTERNAL_API]); ?>" placeholder="https://meet.jit.si/external_api.js">
                            </div>
                        </div>
                        <div class="medsov-actions">
                            <button class="btn medsov-primary" type="submit" name="form_save" value="1">
                                <i class="fa fa-save" aria-hidden="true"></i>
                                <?php echo xlt('Save Configuration'); ?>
                            </button>
                            <a class="btn medsov-secondary" href="<?php echo attr($testRoomUrl); ?>">
                                <i class="fa fa-video-camera" aria-hidden="true"></i>
                                <?php echo xlt('Open Test Room'); ?>
                            </a>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="medsov-panel">
                <div class="medsov-panel-head">
                    <h2 class="medsov-panel-title"><?php echo xlt('Configuration Impact'); ?></h2>
                    <p class="medsov-panel-note"><?php echo xlt('Current settings and what they control.'); ?></p>
                </div>
                <div class="medsov-panel-body">
                    <ul class="medsov-summary-list">
                        <li>
                            <strong><?php echo xlt('Waiting Room'); ?></strong>
                            <span><?php echo !empty($settings[MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED])
                                ? xlt('Patients must wait for provider admission before joining.')
                                : xlt('Patients can join directly from the portal appointment card.'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo xlt('Device Checks'); ?></strong>
                            <span><?php echo xlt('Audio and video flags control required browser permission checks and Jitsi start behavior.'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo xlt('Email Notifications'); ?></strong>
                            <span><?php echo !empty($settings[MedsovTelehealthGlobalConfig::EMAIL_ENABLED])
                                ? xlt('Provider and patient emails are enabled through OpenEMR SMTP.')
                                : xlt('Email sends are disabled; UI and Message Center behavior can still run.'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo xlt('SMS Notifications'); ?></strong>
                            <span><?php echo xlt('The flag is stored for the Medsov Notification Module integration; SMS delivery is not active until that integration is connected.'); ?></span>
                        </li>
                        <li>
                            <strong><?php echo xlt('Participant Limit'); ?></strong>
                            <span><?php echo sprintf(xlt('Configured maximum participants: %s'), text((string)$settings[MedsovTelehealthGlobalConfig::MAX_PARTICIPANTS])); ?></span>
                        </li>
                    </ul>
                </div>
            </aside>
        </div>
    </form>
</main>
</body>
</html>
