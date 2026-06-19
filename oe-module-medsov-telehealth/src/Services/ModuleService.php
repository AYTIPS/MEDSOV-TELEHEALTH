<?php

/**
 * Settings persistence for the Medsov Telehealth module.
 *
 * @package OpenEMR
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\MedsovTelehealth\Services;

use OpenEMR\Modules\MedsovTelehealth\MedsovTelehealthGlobalConfig;

class ModuleService
{
    public function getSettings(): array
    {
        $settings = $this->getDefaults();
        $keys = array_keys($settings);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $statement = sqlStatementNoLog(
            "SELECT `gl_name`, `gl_value` FROM `globals` WHERE `gl_name` IN ($placeholders)",
            $keys
        );

        while ($row = sqlFetchArray($statement)) {
            $settings[$row['gl_name']] = $row['gl_value'];
        }

        return $settings;
    }

    public function saveSettings(array $input): void
    {
        $settings = $this->normalizeSettings($input);

        foreach ($settings as $key => $value) {
            $GLOBALS[$key] = $value;
            sqlQuery(
                "INSERT INTO `globals` (`gl_name`, `gl_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `gl_name` = ?, `gl_value` = ?",
                [$key, $value, $key, $value]
            );
        }
    }

    public function isEnabled(): bool
    {
        $settings = $this->getSettings();
        return !empty($settings[MedsovTelehealthGlobalConfig::ENABLED]);
    }

    public function getDefaults(): array
    {
        return [
            MedsovTelehealthGlobalConfig::ENABLED => '1',
            MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED => '1',
            MedsovTelehealthGlobalConfig::VIDEO_ENABLED => '1',
            MedsovTelehealthGlobalConfig::AUDIO_ENABLED => '1',
            MedsovTelehealthGlobalConfig::SMS_ENABLED => '',
            MedsovTelehealthGlobalConfig::EMAIL_ENABLED => '',
            MedsovTelehealthGlobalConfig::JITSI_DOMAIN => 'meet.jit.si',
            MedsovTelehealthGlobalConfig::JITSI_BASE_URL => 'https://meet.jit.si',
            MedsovTelehealthGlobalConfig::JITSI_EXTERNAL_API => 'https://meet.jit.si/external_api.js',
            MedsovTelehealthGlobalConfig::MAX_PARTICIPANTS => '2',
        ];
    }

    private function normalizeSettings(array $input): array
    {
        $settings = $this->getDefaults();

        foreach ([
            MedsovTelehealthGlobalConfig::ENABLED,
            MedsovTelehealthGlobalConfig::WAITING_ROOM_ENABLED,
            MedsovTelehealthGlobalConfig::VIDEO_ENABLED,
            MedsovTelehealthGlobalConfig::AUDIO_ENABLED,
            MedsovTelehealthGlobalConfig::SMS_ENABLED,
            MedsovTelehealthGlobalConfig::EMAIL_ENABLED,
        ] as $flag) {
            $settings[$flag] = !empty($input[$flag]) ? '1' : '';
        }

        $domain = trim((string)($input[MedsovTelehealthGlobalConfig::JITSI_DOMAIN] ?? $settings[MedsovTelehealthGlobalConfig::JITSI_DOMAIN]));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = trim((string)$domain, "/ \t\n\r\0\x0B");
        $settings[MedsovTelehealthGlobalConfig::JITSI_DOMAIN] = $domain ?: 'meet.jit.si';

        $baseUrl = trim((string)($input[MedsovTelehealthGlobalConfig::JITSI_BASE_URL] ?? ''));
        $settings[MedsovTelehealthGlobalConfig::JITSI_BASE_URL] = $baseUrl ?: 'https://' . $settings[MedsovTelehealthGlobalConfig::JITSI_DOMAIN];

        $externalApi = trim((string)($input[MedsovTelehealthGlobalConfig::JITSI_EXTERNAL_API] ?? ''));
        $settings[MedsovTelehealthGlobalConfig::JITSI_EXTERNAL_API] = $externalApi ?: $settings[MedsovTelehealthGlobalConfig::JITSI_BASE_URL] . '/external_api.js';

        $maxParticipants = (int)($input[MedsovTelehealthGlobalConfig::MAX_PARTICIPANTS] ?? 2);
        $settings[MedsovTelehealthGlobalConfig::MAX_PARTICIPANTS] = (string)max(2, $maxParticipants);

        return $settings;
    }
}

