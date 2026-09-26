<?php

declare(strict_types=1);

namespace Provemark\C2paCheck;

use DateTimeImmutable;
use DateTimeZone;
use Provemark\C2paVerifier\Report\ValidationState;
use Provemark\C2paVerifier\Verifier\VerificationReport;

/**
 * The compact entry SPEC-001 stores per attachment: the verifier's verdict,
 * or the plugin's own `none` / `error`, never a verdict of its own.
 *
 * Signer, time, codes and the manifest URL come from the file and are
 * untrusted text; whoever shows them escapes them (SPEC-002).
 */
final class Outcome
{
    public const SCHEMA = 1;

    /**
     * @return array<string, mixed>
     */
    public static function fromReport(VerificationReport $report, string $verifierVersion, DateTimeImmutable $at): array
    {
        // A file without a manifest comes back as Invalid with no statuses;
        // "no credential" is decided on hasManifest, never on the state.
        $state = $report->hasManifest ? $report->result->state->value : 'none';
        $info = $report->signatureInfo;

        return [
            'schema' => self::SCHEMA,
            'state' => $state,
            'format' => $report->format,
            'signer' => $info === null ? null : ['issuer' => $info['issuer'], 'common_name' => $info['common_name']],
            'signed_at' => $info['time'] ?? null,
            'codes' => $state === ValidationState::Invalid->value ? self::failureCodes($report) : [],
            'remote_manifest_url' => $report->remoteManifestUrl,
            'reason' => null,
            'verifier' => $verifierVersion,
            'checked_at' => self::utc($at),
        ];
    }

    /**
     * @param  'interrupted'|'unreadable'|'exception'  $reason
     * @return array<string, mixed>
     */
    public static function error(string $reason, string $verifierVersion, DateTimeImmutable $at): array
    {
        return [
            'schema' => self::SCHEMA,
            'state' => 'error',
            'format' => null,
            'signer' => null,
            'signed_at' => null,
            'codes' => [],
            'remote_manifest_url' => null,
            'reason' => $reason,
            'verifier' => $verifierVersion,
            'checked_at' => self::utc($at),
        ];
    }

    /**
     * The failures only, in the order the CLI prints them in
     * validation_status (the active manifest's first, then each
     * ingredient's); result->statuses also holds successes and
     * informational codes, in another order.
     *
     * @return list<string>
     */
    private static function failureCodes(VerificationReport $report): array
    {
        $failures = $report->toArray()['validation_status'] ?? [];
        $codes = [];
        foreach (is_array($failures) ? $failures : [] as $status) {
            if (is_array($status) && is_string($status['code'] ?? null)) {
                $codes[] = $status['code'];
            }
        }

        return $codes;
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
