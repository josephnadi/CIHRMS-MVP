# SAML SSO Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the stub XML parser in `SamlSsoAdapter` with a library-backed, signature-verifying SAML 2.0 SP. Today the adapter pulls `NameID` and attribute statements from the SAMLResponse *without* validating the XML-DSIG signature — usable for development against test IdPs, fatal in production.

**Tech Stack:** Laravel 13.7 / PHP 8.3 / Pest 4 / `onelogin/php-saml ^4.2` (recommended) **or** `simplesamlphp/simplesamlphp ^2.3`.

**Reference:**
- Stub: [`app/Services/Sso/SamlSsoAdapter.php`](../../app/Services/Sso/SamlSsoAdapter.php#L23) (TODO at line 23, 61)
- Caller: [`app/Http/Controllers/Auth/SsoController.php`](../../app/Http/Controllers/Auth/SsoController.php)
- Provider model: [`app/Models/SsoIdentityProvider.php`](../../app/Models/SsoIdentityProvider.php)

---

## Decision: `onelogin/php-saml` over `simplesamlphp`

| Criterion | onelogin/php-saml | simplesamlphp |
|---|---|---|
| Footprint | Library, lives inside Laravel | Standalone PHP app with its own router |
| Config | Array in our `config/sso.php` | YAML in `simplesamlphp/config/` |
| Routing | We own ACS URL | Hands ACS to SSP, then redirects |
| Best for | SP-only embedded into a host app | Multi-tenant IdP/SP gateway |

`onelogin/php-saml` is the right fit — we're a single-tenant SP and want the auth flow to stay inside the existing `/auth/sso/{slug}/callback` route.

---

## File map

### Created (PHP)
- `config/sso.php` (per-provider SAML config blocks — IdP cert, entity_id, ACS, NameID format)
- `app/Services/Sso/SamlConfigBuilder.php` (translates `SsoIdentityProvider->config` → onelogin's settings array)
- `tests/Feature/Auth/Sso/SamlSignatureVerificationTest.php` (validates good signature accepts, bad signature rejects, expired NotOnOrAfter rejects)
- `tests/fixtures/saml/idp-metadata-good.xml`
- `tests/fixtures/saml/saml-response-signed.xml`
- `tests/fixtures/saml/saml-response-tampered.xml`

### Modified
- `composer.json` — require `onelogin/php-saml: ^4.2`
- `app/Services/Sso/SamlSsoAdapter.php` — replace stub body with library calls
- `app/Enums/SsoLoginOutcome.php` — add `SignatureInvalid`, `AssertionExpired`, `AudienceMismatch` cases if absent
- `routes/web.php` — verify ACS POST route uses `withoutMiddleware('VerifyCsrfToken')` for the callback (CSRF doesn't apply to IdP-initiated POSTs)
- `app/Http/Middleware/VerifyCsrfToken.php` — add `auth/sso/*/callback` to `$except`

---

## Implementation steps

### 1. Pull the library
- [ ] `composer require onelogin/php-saml:^4.2`
- [ ] Commit lock file

### 2. Config builder
- [ ] Create `config/sso.php` with `defaults` block (NameIDFormat = `emailAddress`, signed AuthnRequests = true, want signed assertions, want encrypted nameID = false until cert exchange).
- [ ] Create `SamlConfigBuilder::for(SsoIdentityProvider $provider): array` that returns the onelogin settings array. Pulls `entity_id`, `sso_url`, `idp_x509cert`, `sp_private_key`, `sp_x509cert` from `$provider->config`.
- [ ] Write a Pest test asserting the builder hydrates onelogin's `Settings` constructor without throwing for a sample row.

### 3. Replace adapter body
- [ ] In `SamlSsoAdapter::initiate()`, replace the manual `gzdeflate + base64_encode` with `new \OneLogin\Saml2\Auth($settings); $auth->login($intendedUrl, [], false, false, true)` — the last `true` returns the redirect URL instead of issuing it.
- [ ] In `handleCallback()`, instantiate `Auth` from the same settings, call `processResponse($lastRequestId ?? null)`, then call `getErrors()`. If non-empty: map onelogin's error codes to `SsoLoginOutcome::SignatureInvalid` / `AssertionExpired` / `AudienceMismatch` / `ProvidersError`.
- [ ] Extract `getNameId()` and `getAttributes()`. Keep the existing return-shape (`SsoAuthResult::ok(...)`).
- [ ] Remove the manual `DOMDocument` / `DOMXPath` block — that's the unsigned read.

### 4. RelayState handling
- [ ] onelogin's `Auth::login()` puts RelayState in the URL automatically. Migrate the existing session-based `relay_state` check to onelogin's `getLastRequestID()` / `processResponse($requestID)` — that's the library-supported way to bind the request to the response.
- [ ] Update session keys in `SsoController::initiate()` and `callback()` to store `last_request_id` instead of `relay_state`.

### 5. Tests
- [ ] **Good signature accepts:** fixture SAML response signed with a known cert; provider row has the matching `idp_x509cert`. Adapter returns `SsoAuthResult::ok` with the expected NameID + attributes.
- [ ] **Tampered signature rejects:** same fixture but flip one byte in an `<AttributeValue>`. Adapter returns `SignatureInvalid` outcome and writes no session.
- [ ] **Expired assertion rejects:** fixture with `NotOnOrAfter` in the past. Adapter returns `AssertionExpired`.
- [ ] **Wrong audience rejects:** SP entityID in settings ≠ `<Audience>` in the assertion. Adapter returns `AudienceMismatch`.
- [ ] **Missing NameID rejects:** strip `<Subject>` from the fixture. Adapter returns `ClaimMissing`.

### 6. Manual smoke test against a free IdP
- [ ] Register a free dev tenant at https://samltest.id/ (or use the Microsoft Entra ID free tier).
- [ ] Add a row to `sso_identity_providers` with their IdP metadata.
- [ ] Hit `/auth/sso/{slug}`, complete the IdP login, confirm the user is provisioned and a session is opened on the Laravel side.
- [ ] Tail the audit log — login event should record `provider`, `subject`, `outcome=success`.

### 7. Documentation
- [ ] Add a `docs/sso/saml-setup.md` covering: how to mint the SP x509 cert (`openssl req -x509 -newkey rsa:2048 -keyout sp.key -out sp.crt -days 3650 -nodes`), where to upload the SP metadata to the IdP, and the standard NameID/attribute claim mapping CIHRMS expects.
- [ ] Add a row to the QA report's "shipped" table.

---

## Sizing
- Effort: **~2 working days** (1 day code, 0.5 day tests, 0.5 day manual smoke + doc)
- Risk: **Low** — the library is mature and well-documented; the host-app integration is small.
- Blocking: **Yes** for any external SSO go-live. Internal-only deployments can ship without this.

## Out of scope
- IdP-initiated login (we only handle SP-initiated for now).
- Single Logout (SLO) — defer until we have a customer asking for it.
- Encrypted assertions — onelogin supports them but adds key-management overhead; defer.
