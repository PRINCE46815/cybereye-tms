<?php
/**
 * TMS — includes/totp.php
 * Time-based One-Time Passwords (RFC 6238) for two-factor auth.
 * Pure PHP, no dependencies. Compatible with Google Authenticator,
 * Authy, Microsoft Authenticator, etc.
 */

/** RFC 4648 base32 alphabet. */
function totp_b32_alphabet() { return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; }

/** Encode raw bytes to base32 (no padding). */
function totp_base32_encode($bytes)
{
    if ($bytes === '') return '';
    $alphabet = totp_b32_alphabet();
    $out = '';
    $buffer = 0; $bitsLeft = 0;
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $buffer = ($buffer << 8) | (ord($bytes[$i]) & 0xFF);
        $bitsLeft += 8;
        while ($bitsLeft >= 5) {
            $bitsLeft -= 5;
            $out .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
        }
    }
    if ($bitsLeft > 0) {
        $out .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
    }
    return $out;
}

/** Decode a base32 string to raw bytes. Ignores spaces/padding/case. */
function totp_base32_decode($b32)
{
    $alphabet = totp_b32_alphabet();
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    if ($b32 === '') return '';
    $buffer = 0; $bitsLeft = 0; $out = '';
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $val = strpos($alphabet, $b32[$i]);
        if ($val === false) continue;
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $out .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }
    return $out;
}

/** Generate a new random base32 secret (default 20 bytes -> 32 chars). */
function totp_secret($bytes = 20)
{
    return totp_base32_encode(random_bytes($bytes));
}

/** Compute the 6-digit TOTP code for a secret at a given time slice. */
function totp_code($secret, $timeSlice = null, $digits = 6, $period = 30)
{
    if ($timeSlice === null) { $timeSlice = (int) floor(time() / $period); }
    $key = totp_base32_decode($secret);
    // 8-byte big-endian counter
    $bin = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $bin, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $part = substr($hash, $offset, 4);
    $value = unpack('N', $part);
    $value = $value[1] & 0x7FFFFFFF;
    $code = $value % (10 ** $digits);
    return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
}

/** Verify a code against the secret, allowing +/- $window slices for drift. */
function totp_verify($secret, $code, $window = 1, $period = 30)
{
    $code = preg_replace('/\s+/', '', (string) $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $current = (int) floor(time() / $period);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $current + $i, 6, $period), $code)) {
            return true;
        }
    }
    return false;
}

/** Build the otpauth:// URI for QR codes / manual entry. */
function totp_uri($secret, $account, $issuer)
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    $params = http_build_query(array(
        'secret'  => $secret,
        'issuer'  => $issuer,
        'algorithm' => 'SHA1',
        'digits'  => 6,
        'period'  => 30,
    ));
    return 'otpauth://totp/' . $label . '?' . $params;
}

/* ---------------- Recovery codes ---------------- */

/** Generate N human-friendly recovery codes (plaintext). */
function totp_recovery_generate($n = 8)
{
    $codes = array();
    for ($i = 0; $i < $n; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
    return $codes;
}

/** Hash a list of plaintext recovery codes for storage (JSON of bcrypt hashes). */
function totp_recovery_hash($codes)
{
    $hashes = array();
    foreach ($codes as $c) { $hashes[] = password_hash(strtoupper(trim($c)), PASSWORD_BCRYPT); }
    return json_encode($hashes);
}

/**
 * Check a recovery code against the stored JSON hash list.
 * On match, returns the NEW JSON list with that code consumed; else false.
 */
function totp_recovery_consume($storedJson, $input)
{
    $input = strtoupper(trim((string) $input));
    if ($input === '') return false;
    $hashes = json_decode((string) $storedJson, true);
    if (!is_array($hashes)) return false;
    foreach ($hashes as $idx => $h) {
        if (password_verify($input, $h)) {
            unset($hashes[$idx]);
            return json_encode(array_values($hashes));
        }
    }
    return false;
}
