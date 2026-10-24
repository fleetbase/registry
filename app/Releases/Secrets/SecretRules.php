<?php

namespace App\Releases\Secrets;

/**
 * A gitleaks-style ruleset in PHP: provider token formats with low false-positive rates
 * (patterns follow gitleaks' default config), plus files that should never ship in a
 * package. A line containing `gitleaks:allow` is skipped, as with gitleaks. Matches are
 * redacted before they are stored.
 */
class SecretRules
{
    public const MAX_FINDINGS_PER_FILE = 20;

    /**
     * id => [description, pattern, keywords]. As in gitleaks, a rule's regex only runs on
     * contents that contain one of its keywords (case-insensitive), which keeps scanning
     * multi-megabyte bundles fast.
     */
    public const RULES = [
        'aws-access-token' => ['AWS access key id', '/\b((?:A3T[A-Z0-9]|AKIA|ASIA|ABIA|ACCA)[A-Z2-7]{16})\b/', ['A3T', 'AKIA', 'ASIA', 'ABIA', 'ACCA']],
        'aws-secret-access-key' => ['AWS secret access key', '/(?i)aws.{0,20}?(?:secret|private).{0,20}?[\'"=:\s]{1,4}([A-Za-z0-9\/+]{40})(?![A-Za-z0-9\/+])/', ['aws']],
        'github-pat' => ['GitHub personal access token', '/\bghp_[0-9A-Za-z]{36}\b/', ['ghp_']],
        'github-fine-grained-pat' => ['GitHub fine-grained personal access token', '/\bgithub_pat_\w{82}\b/', ['github_pat_']],
        'github-oauth' => ['GitHub OAuth access token', '/\bgho_[0-9A-Za-z]{36}\b/', ['gho_']],
        'github-app-token' => ['GitHub app token', '/\b(?:ghu|ghs)_[0-9A-Za-z]{36}\b/', ['ghu_', 'ghs_']],
        'github-refresh-token' => ['GitHub refresh token', '/\bghr_[0-9A-Za-z]{36}\b/', ['ghr_']],
        'gitlab-pat' => ['GitLab personal access token', '/\bglpat-[0-9A-Za-z_-]{20}\b/', ['glpat-']],
        'slack-token' => ['Slack token', '/\bxox[baprs]-[0-9A-Za-z]{10,48}(?:-[0-9A-Za-z]{2,48}){1,4}\b/', ['xoxb-', 'xoxa-', 'xoxp-', 'xoxr-', 'xoxs-']],
        'slack-webhook-url' => ['Slack webhook URL', '#https://hooks\.slack\.com/(?:services|workflows|triggers)/[A-Za-z0-9+/]{43,56}#', ['hooks.slack.com']],
        'stripe-access-token' => ['Stripe secret or restricted key', '/\b(?:sk|rk)_(?:test|live|prod)_[0-9A-Za-z]{10,99}\b/', ['sk_test_', 'sk_live_', 'sk_prod_', 'rk_test_', 'rk_live_', 'rk_prod_']],
        'private-key' => ['Private key', '/-----BEGIN[ A-Z0-9_-]{0,100}PRIVATE KEY(?: BLOCK)?-----[\s\S-]{64,}?KEY(?: BLOCK)?-----/', ['PRIVATE KEY']],
        'gcp-api-key' => ['Google API key', '/\bAIza[0-9A-Za-z_-]{35}\b/', ['AIza']],
        'npm-access-token' => ['npm access token', '/\bnpm_[A-Za-z0-9]{36}\b/', ['npm_']],
        'sendgrid-api-token' => ['SendGrid API key', '/\bSG\.[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}\b/', ['SG.']],
        'twilio-api-key' => ['Twilio API key', '/\bSK[0-9a-fA-F]{32}\b/', ['SK']],
        'mailgun-private-api-token' => ['Mailgun private API key', '/\bkey-[0-9a-f]{32}\b/', ['key-']],
        'openai-api-key' => ['OpenAI API key', '/\bsk-(?:proj-|svcacct-|admin-)?[A-Za-z0-9_-]{20,}T3BlbkFJ[A-Za-z0-9_-]{20,}\b/', ['T3BlbkFJ']],
        'anthropic-api-key' => ['Anthropic API key', '/\bsk-ant-(?:api03|admin01)-[A-Za-z0-9_-]{80,}/', ['sk-ant-']],
        'fleetbase-registry-token' => ['Fleetbase registry token', '/\bflb[pis]_[A-Za-z0-9]{40}\b/', ['flbp_', 'flbi_', 'flbs_']],
        'fleetbase-api-key' => ['Fleetbase API key', '/\bflb_(?:live|test)_[A-Za-z0-9]{16,}\b/', ['flb_live_', 'flb_test_']],
    ];

    /**
     * Files that hold credentials by convention. `.npmrc` and `auth.json` only count when
     * they carry a literal token (a `${VAR}` reference is fine).
     */
    public function sensitiveFile(string $name, ?string $contents = null): ?string
    {
        $base = strtolower(basename($name));

        if (preg_match('/^\.env(\..+)?$/', $base) === 1 && preg_match('/\.(example|sample|dist|template|defaults)$/', $base) !== 1) {
            return 'Environment file ('.$base.')';
        }

        if (in_array($base, ['id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519'], true)) {
            return 'SSH private key ('.$base.')';
        }

        if (preg_match('/\.(p12|pfx|jks|keystore)$/', $base) === 1) {
            return 'Certificate or key store ('.$base.')';
        }

        if ($base === '.npmrc' && $contents !== null && preg_match('/_(?:authToken|auth|password)\s*=\s*(?!\$\{)\S+/', $contents) === 1) {
            return 'npm credentials (.npmrc)';
        }

        if ($base === 'auth.json' && $contents !== null && preg_match('/"(?:bearer|http-basic|github-oauth|gitlab-token|gitlab-oauth|bitbucket-oauth)"\s*:\s*\{\s*"[^"]+"\s*:/', $contents) === 1) {
            return 'Composer credentials (auth.json)';
        }

        return null;
    }

    /**
     * Scan one file. `$contents` is null for files too large to read; then only the file
     * name is checked. Binary contents skip the token rules.
     *
     * @return list<array{rule: string, description: string, file: string, line: int, match: string}>
     */
    public function scan(string $file, ?string $contents): array
    {
        $findings = [];

        if ($sensitive = $this->sensitiveFile($file, $contents)) {
            $findings[] = ['rule' => 'sensitive-file', 'description' => $sensitive, 'file' => $file, 'line' => 0, 'match' => ''];
        }

        if ($contents === null || str_contains(substr($contents, 0, 8192), "\0")) {
            return $findings;
        }

        foreach (self::RULES as $id => [$description, $pattern, $keywords]) {
            if (! static::mentions($contents, $keywords)) {
                continue;
            }

            if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$match, $offset]) {
                $lineStart = strrpos(substr($contents, 0, $offset), "\n");
                $lineStart = $lineStart === false ? 0 : $lineStart + 1;
                $lineEnd = strpos($contents, "\n", $offset + strlen($match));
                $line = substr($contents, $lineStart, ($lineEnd === false ? strlen($contents) : $lineEnd) - $lineStart);
                if (str_contains($line, 'gitleaks:allow')) {
                    continue;
                }

                $findings[] = [
                    'rule' => $id,
                    'description' => $description,
                    'file' => $file,
                    'line' => substr_count($contents, "\n", 0, $offset) + 1,
                    'match' => static::redact($match),
                ];

                if (count($findings) >= self::MAX_FINDINGS_PER_FILE) {
                    return $findings;
                }
            }
        }

        return $findings;
    }

    protected static function mentions(string $contents, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (stripos($contents, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep enough to find the secret, never enough to use it.
     */
    public static function redact(string $secret): string
    {
        $secret = preg_replace('/\s+/', ' ', $secret);

        return mb_substr($secret, 0, min(6, (int) floor(strlen($secret) / 4))).'…['.strlen($secret).' chars]';
    }
}
