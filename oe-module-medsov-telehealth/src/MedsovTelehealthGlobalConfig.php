<?php

/**
 * Global settings for the Medsov Telehealth module.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\MedsovTelehealth;

use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Services\Globals\GlobalsService;

class MedsovTelehealthGlobalConfig
{
    public const SECTION_NAME = 'Medsov Telehealth';

    public const ENABLED = 'medsov_telehealth_enabled';
    public const WAITING_ROOM_ENABLED = 'medsov_telehealth_waiting_room_enabled';
    public const VIDEO_ENABLED = 'medsov_telehealth_video_enabled';
    public const AUDIO_ENABLED = 'medsov_telehealth_audio_enabled';
    public const SMS_ENABLED = 'medsov_telehealth_sms_enabled';
    public const EMAIL_ENABLED = 'medsov_telehealth_email_enabled';
    public const JITSI_DOMAIN = 'medsov_telehealth_jitsi_domain';
    public const JITSI_BASE_URL = 'medsov_telehealth_base_url';
    public const JITSI_EXTERNAL_API = 'medsov_telehealth_jitsi_external_api';
    public const MAX_PARTICIPANTS = 'medsov_telehealth_max_participants';

    public function setupConfiguration(GlobalsService $service): void
    {
        global $GLOBALS;

        $section = xlt(self::SECTION_NAME);
        $service->createSection($section, 'Portal');

        foreach ($this->getGlobalSettingSectionConfiguration() as $key => $config) {
            $setting = new GlobalSetting(
                xlt($config['title']),
                $config['type'],
                $GLOBALS[$key] ?? $config['default'],
                xlt($config['description']),
                true
            );

            $service->appendToSection($section, $key, $setting);
        }
    }

    public function getGlobalSettingSectionConfiguration(): array
    {
        return [
            self::ENABLED => [
                'title' => 'Enable Medsov Telehealth',
                'description' => 'Enable the Medsov Telehealth module.',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '1',
            ],
            self::WAITING_ROOM_ENABLED => [
                'title' => 'Enable Waiting Room',
                'description' => 'Require patients to pass through the waiting room before joining a session.',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '1',
            ],
            self::VIDEO_ENABLED => [
                'title' => 'Enable Video',
                'description' => 'Allow video in telehealth sessions.',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '1',
            ],
            self::AUDIO_ENABLED => [
                'title' => 'Enable Audio',
                'description' => 'Allow audio in telehealth sessions.',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '1',
            ],
            self::SMS_ENABLED => [
                'title' => 'Enable SMS Notifications',
                'description' => 'Allow SMS notification events when the Medsov Notification Module is available.',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '',
            ],
            self::EMAIL_ENABLED => [
                'title' => 'Enable Email Notifications',
                'description' => 'Allow email notification events when the Medsov Notification Module is available.',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => '',
            ],
            self::JITSI_DOMAIN => [
                'title' => 'Jitsi Domain',
                'description' => 'Jitsi Meet domain used for embedded meetings.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => 'meet.jit.si',
            ],
            self::JITSI_BASE_URL => [
                'title' => 'Jitsi Base URL',
                'description' => 'Base URL used when generating meeting links.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => 'https://meet.jit.si',
            ],
            self::JITSI_EXTERNAL_API => [
                'title' => 'Jitsi External API Script',
                'description' => 'URL for the Jitsi iframe API script.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => 'https://meet.jit.si/external_api.js',
            ],
            self::MAX_PARTICIPANTS => [
                'title' => 'Maximum Participants',
                'description' => 'Maximum participants allowed per telehealth session.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => '2',
            ],
        ];
    }
}

