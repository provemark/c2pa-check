# SPEC-021: Named sanitize callbacks for the two settings

| Field      | Value                                             |
|------------|---------------------------------------------------|
| Status     | approved                                          |
| Author     | Maurice van Loon                                  |
| Approved   | Maurice van Loon, 2026-09-28                      |
| Supersedes | —                                                 |

> Lifecycle: `draft` → maintainer approves → `approved` → tests-first →
> `implemented` (Traceability filled). No implementation code while `draft`.
> Only the Traceability section of an `approved` spec may change without a new
> approval; everything else needs a proposed amendment.

## Problem

wordpress.org's first review (2026-09-28) listed
`src/SettingsPage.php:75` under "Sanitization for register_setting()",
although both settings have a `sanitize_callback`: a closure for the
DigiCert checkbox and a first-class callable, `self::sanitizeCustom(...)`,
for the custom trust settings. Reasoned: the review's scanner recognises a
callback by name (a function name or a `[class, method]` array), not a
closure. Closures also cannot be named or removed by other code.

The checkbox's closure, `(bool) $value`, turns the string `'false'` into
`true`; WordPress's own `rest_sanitize_boolean()` gives `false`.

## Scope

**In scope**

- DigiCert option: `'sanitize_callback' => 'rest_sanitize_boolean'`.
- Custom trust option: `'sanitize_callback' => [self::class,
  'sanitizeCustom']`; the method is unchanged.

**Out of scope**

- What `sanitizeCustom()` accepts or refuses (SPEC-004 AC5, SPEC-012 AC2
  keep covering it).

## Acceptance criteria

- **AC1** In the admin, both registered settings have a sanitize callback
  that is a function name or a `[class, method]` array, not a closure.
- **AC2** The DigiCert callback gives `true` for `'1'` and `true`, and
  `false` for `''`, `'0'`, `'false'` and `null`.
- **AC3** SPEC-004 AC5 and SPEC-012 AC2 still pass.

## Traceability

- AC1, AC2: `tests/Integration/SanitizeCallbackTest.php`
- AC3: `tests/Integration/TrustTest.php`, `tests/Integration/ReviewFixesTest.php`
