<?php

declare(strict_types=1);

namespace Tracefern\ImageCheck;

if (! defined('ABSPATH')) {
    exit;
}

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
    public const int SCHEMA = 1;

    /**
     * The IPTC digital source type for generative-AI media (SPEC-003): on
     * the image's parent line it makes the image AI-generated (SPEC-027).
     */
    public const string TRAINED_ALGORITHMIC_MEDIA = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    /**
     * The IPTC digital source type for media edited with generative AI
     * (SPEC-027): it makes the image AI-edited, not AI-generated.
     */
    public const string COMPOSITE_TRAINED_ALGORITHMIC_MEDIA = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';

    /**
     * @return array<string, mixed>
     */
    public static function fromReport(VerificationReport $report, string $verifierVersion, DateTimeImmutable $at, string $trust = 'none'): array
    {
        // A file without a manifest comes back as Invalid with no statuses;
        // "no credential" is decided on hasManifest, never on the state, and
        // only when the verifier reported no failure (SPEC-015): a file it
        // could not read as an image at all is an error, not "none".
        if (! $report->hasManifest && $report->result->statuses !== []) {
            return self::error($report->format === 'unknown' ? 'unsupported' : 'unreadable', $verifierVersion, $at, $trust);
        }
        $state = $report->hasManifest ? $report->result->state->value : 'none';
        $info = $report->signatureInfo;
        [$generated, $edited] = self::aiHistory($report->toArray());

        return self::bounded([
            'schema' => self::SCHEMA,
            'state' => $state,
            'format' => $report->format,
            'signer' => $info === null ? null : ['issuer' => $info['issuer'], 'common_name' => $info['common_name']],
            'signed_at' => $info['time'] ?? null,
            'codes' => $state === ValidationState::Invalid->value ? self::failureCodes($report) : [],
            'ai' => $generated,
            'ai_edited' => $edited,
            'remote_manifest_url' => $report->remoteManifestUrl,
            'reason' => null,
            'verifier' => $verifierVersion,
            'checked_at' => self::utc($at),
            'trust' => $trust,
        ]);
    }

    /** The most characters kept of one text from the file (SPEC-015). */
    public const int MAX_TEXT = 256;

    /** The most status codes kept; the rest are counted in `codes_omitted`. */
    public const int MAX_CODES = 50;

    /**
     * The entry with every text from the file cut to MAX_TEXT characters
     * (ending in "…") and the codes to MAX_CODES, so a crafted file cannot
     * make the stored entry or the page arbitrarily large.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function bounded(array $entry): array
    {
        // Invalid UTF-8 scrubbed first: update_post_meta() would refuse it (SPEC-018).
        $cut = static function (mixed $text): mixed {
            if (! is_string($text)) {
                return $text;
            }
            $text = mb_scrub($text, 'UTF-8');

            return mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT - 1).'…' : $text;
        };

        if (is_array($entry['signer'] ?? null)) {
            $entry['signer'] = array_map($cut, $entry['signer']);
        }
        foreach (['signed_at', 'remote_manifest_url'] as $key) {
            if (array_key_exists($key, $entry)) {
                $entry[$key] = $cut($entry[$key]);
            }
        }
        if (is_array($entry['codes'] ?? null) && count($entry['codes']) > self::MAX_CODES) {
            $entry['codes_omitted'] = count($entry['codes']) - self::MAX_CODES;
            $entry['codes'] = array_slice($entry['codes'], 0, self::MAX_CODES);
        }
        if (is_array($entry['codes'] ?? null)) {
            $entry['codes'] = array_map($cut, $entry['codes']);
        }

        return $entry;
    }

    /**
     * @param  'interrupted'|'unreadable'|'exception'|'unsupported'  $reason
     * @return array<string, mixed>
     */
    public static function error(string $reason, string $verifierVersion, DateTimeImmutable $at, string $trust = 'none'): array
    {
        return [
            'schema' => self::SCHEMA,
            'state' => 'error',
            'format' => null,
            'signer' => null,
            'signed_at' => null,
            'codes' => [],
            'ai' => false,
            'ai_edited' => false,
            'remote_manifest_url' => null,
            'reason' => $reason,
            'verifier' => $verifierVersion,
            'checked_at' => self::utc($at),
            'trust' => $trust,
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

    /**
     * What the image's signed history says about AI, as [generated, edited]
     * (SPEC-027). Generated: an action carries TRAINED_ALGORITHMIC_MEDIA in
     * the active manifest or a manifest reached through parentOf
     * ingredients only. Edited, when not generated: a reached manifest
     * carries COMPOSITE_TRAINED_ALGORITHMIC_MEDIA, or TRAINED_ALGORITHMIC_MEDIA
     * off the parent line. Claims only: whoever shows them checks the
     * state first (SPEC-003).
     *
     * @param  array<mixed>  $report  toArray()'s shape
     * @return array{bool, bool}
     */
    public static function aiHistory(array $report): array
    {
        $manifests = is_array($report['manifests'] ?? null) ? $report['manifests'] : [];
        $active = $report['active_manifest'] ?? null;
        if (! is_string($active)) {
            return [false, false];
        }

        $deltas = self::deltaFailures($report);
        $generated = false;
        foreach (self::reached($manifests, $deltas, $active, ['parentOf']) as $manifest) {
            $generated = $generated || self::carries($manifest, self::TRAINED_ALGORITHMIC_MEDIA);
        }
        $edited = false;
        foreach (self::reached($manifests, $deltas, $active, ['parentOf', 'componentOf', 'inputTo']) as $manifest) {
            $edited = $edited || self::carries($manifest, self::COMPOSITE_TRAINED_ALGORITHMIC_MEDIA)
                || self::carries($manifest, self::TRAINED_ALGORITHMIC_MEDIA);
        }

        // Without AI on the parent line, trained AI anywhere reached came
        // in as a component or an input.
        return [$generated, $edited && ! $generated];
    }

    /**
     * The manifests reached from $start through followed ingredients with
     * one of $relationships, each once. An ingredient is followed only
     * when both the results its signer recorded and the verifier's own
     * delta for it are present and fail on nothing but an unknown signer:
     * the bar of a Valid active manifest (SPEC-027 amendment 2).
     *
     * @param  array<mixed>  $manifests
     * @param  array<string, mixed>  $deltas  the verifier's delta failures by ingredient assertion URI
     * @param  list<string>  $relationships
     * @return list<array<mixed>>
     */
    private static function reached(array $manifests, array $deltas, string $start, array $relationships): array
    {
        $reached = [];
        $queue = [$start];
        while ($queue !== []) {
            $label = array_shift($queue);
            if (isset($reached[$label]) || ! is_array($manifests[$label] ?? null)) {
                continue;
            }
            $manifest = $manifests[$label];
            $reached[$label] = $manifest;
            foreach (is_array($manifest['ingredients'] ?? null) ? $manifest['ingredients'] : [] as $ingredient) {
                if (! is_array($ingredient) || ! in_array($ingredient['relationship'] ?? null, $relationships, true)
                    || ! is_string($ingredient['active_manifest'] ?? null) || ! is_string($ingredient['label'] ?? null)) {
                    continue;
                }
                $recorded = $ingredient['validation_results'] ?? null;
                $recorded = is_array($recorded) && is_array($recorded['activeManifest'] ?? null) ? ($recorded['activeManifest']['failure'] ?? null) : null;
                $delta = $deltas["self#jumbf=/c2pa/{$label}/c2pa.assertions/{$ingredient['label']}"] ?? null;
                if (self::onlyUnknownSigner($recorded) && self::onlyUnknownSigner($delta)) {
                    $queue[] = $ingredient['active_manifest'];
                }
            }
        }

        return array_values($reached);
    }

    /**
     * The failures of each ingredient delta the verifier reported, by the
     * ingredient assertion's URI; a URI reported twice keeps them all.
     *
     * @param  array<mixed>  $report
     * @return array<string, list<mixed>>
     */
    private static function deltaFailures(array $report): array
    {
        $results = is_array($report['validation_results'] ?? null) ? $report['validation_results'] : [];
        $deltas = [];
        foreach (is_array($results['ingredientDeltas'] ?? null) ? $results['ingredientDeltas'] : [] as $delta) {
            $uri = is_array($delta) ? ($delta['ingredientAssertionURI'] ?? null) : null;
            $found = is_array($delta) && is_array($delta['validationDeltas'] ?? null) ? ($delta['validationDeltas']['failure'] ?? null) : null;
            if (! is_string($uri)) {
                continue;
            }
            // Anything unreadable is kept as a failure, so the ingredient is not followed.
            $deltas[$uri] = [...($deltas[$uri] ?? []), ...(is_array($found) ? array_values($found) : [null])];
        }

        return $deltas;
    }

    /**
     * Whether a failure list is present and holds nothing but
     * signingCredential.untrusted.
     */
    private static function onlyUnknownSigner(mixed $failures): bool
    {
        if (! is_array($failures)) {
            return false;
        }
        foreach ($failures as $failure) {
            if (! is_array($failure) || ($failure['code'] ?? null) !== 'signingCredential.untrusted') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an action in the manifest's actions assertion (c2pa.actions
     * or c2pa.actions.v2) carries the source type $uri.
     *
     * @param  array<mixed>  $manifest
     */
    private static function carries(array $manifest, string $uri): bool
    {
        $assertions = is_array($manifest['assertions'] ?? null) ? $manifest['assertions'] : [];
        foreach ($assertions as $assertion) {
            if (! is_array($assertion) || ! in_array($assertion['label'] ?? null, ['c2pa.actions', 'c2pa.actions.v2'], true)) {
                continue;
            }
            $data = $assertion['data'] ?? null;
            $actions = is_array($data) && is_array($data['actions'] ?? null) ? $data['actions'] : [];
            foreach ($actions as $action) {
                if (is_array($action) && ($action['digitalSourceType'] ?? null) === $uri) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
