<?php

/**
 * Self-test kriptografi CASELOCK (tanpa Laravel / database).
 *
 *   php tests/selftest.php          (dari folder server/ atau backend/)
 *
 * Menguji: AES-256-GCM, XChaCha20-Poly1305 + DEK per berkas, selective decryption,
 * hash chain, CBOR, konversi COSE->PEM, serta alur registrasi & assertion WebAuthn
 * dengan authenticator tiruan (ES256 dan RS256).
 */

spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use App\Support\Crypto\CryptoService;
use App\Support\Crypto\DecryptionException;
use App\Support\Crypto\HashChain;
use App\Support\WebAuthn\Cbor;
use App\Support\WebAuthn\WebAuthnException;
use App\Support\WebAuthn\WebAuthnVerifier as V;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  [OK]   ' : '  [GAGAL] ') . $name . PHP_EOL;
}
function throws(callable $fn, string $class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}

/* ---------------- Crypto ---------------- */
echo "Kriptografi\n";
$c = new CryptoService(random_bytes(32));

$enc = $c->encryptField('Data sensitif: Rp 1.250.000.000', 'case.description');
check('AES-GCM roundtrip', $c->decryptField($enc, 'case.description') === 'Data sensitif: Rp 1.250.000.000');
check('AES-GCM nonce acak (ciphertext berbeda)', $enc !== $c->encryptField('Data sensitif: Rp 1.250.000.000', 'case.description'));
check('Konteks salah ditolak', throws(fn () => $c->decryptField($enc, 'case.reporter'), DecryptionException::class));
$tampered = substr($enc, 0, -4) . (substr($enc, -4) === 'AAAA' ? 'BBBB' : 'AAAA');
check('Manipulasi ciphertext terdeteksi', throws(fn () => $c->decryptField($tampered, 'case.description'), DecryptionException::class));
check('Master key lain ditolak', throws(fn () => (new CryptoService(random_bytes(32)))->decryptField($enc, 'case.description'), DecryptionException::class));

$fileA = random_bytes(50_000);
$fileB = random_bytes(10_000);
$a = $c->encryptFile($fileA, 'uuid-a', 7);
$b = $c->encryptFile($fileB, 'uuid-b', 7);
check('XChaCha20 roundtrip berkas A', $c->decryptFile($a['blob'], $a['wrapped_key'], 'uuid-a', 7) === $fileA);
check('XChaCha20 roundtrip berkas B', $c->decryptFile($b['blob'], $b['wrapped_key'], 'uuid-b', 7) === $fileB);
check('Blob tidak memuat plaintext', !str_contains($a['blob'], substr($fileA, 0, 32)));
check('Selective: blob A + kunci B ditolak', throws(fn () => $c->decryptFile($a['blob'], $b['wrapped_key'], 'uuid-a', 7), DecryptionException::class));
check('Berkas dipindah ke kasus lain ditolak (AAD)', throws(fn () => $c->decryptFile($a['blob'], $a['wrapped_key'], 'uuid-a', 8), DecryptionException::class));
check('Berkas dipindah ke ID lain ditolak (AAD)', throws(fn () => $c->decryptFile($a['blob'], $a['wrapped_key'], 'uuid-x', 7), DecryptionException::class));
$flip = $a['blob'];
$flip[100] = chr(ord($flip[100]) ^ 1);
check('Bit-flip pada berkas terdeteksi', throws(fn () => $c->decryptFile($flip, $a['wrapped_key'], 'uuid-a', 7), DecryptionException::class));

/* ---------------- Hash chain ---------------- */
echo "Hash chain\n";
$rows = [];
$prev = HashChain::GENESIS;
for ($i = 1; $i <= 5; $i++) {
    $details = json_encode(['n' => $i]);
    $at = '2026-11-08T09:00:0' . $i . '.000000Z';
    $h = HashChain::hash($prev, $i, $i % 2 ? 3 : null, 'file.decrypt', 'file', "f$i", 7, $details, $at);
    $rows[] = ['seq' => $i, 'user_id' => $i % 2 ? 3 : null, 'action' => 'file.decrypt', 'subject_type' => 'file', 'subject_id' => "f$i",
        'case_id' => 7, 'details' => $details, 'prev_hash' => $prev, 'hash' => $h, 'logged_at' => $at];
    $prev = $h;
}
check('Rantai utuh valid', HashChain::verify($rows)['valid'] === true);
$t = $rows;
$t[2]['action'] = 'file.view';
$res = HashChain::verify($t);
check('Ubah isi baris terdeteksi di seq 3', !$res['valid'] && $res['broken_at_seq'] === 3);
$t = $rows;
unset($t[1]);
$res = HashChain::verify(array_values($t));
check('Hapus baris terdeteksi', !$res['valid']);
$t = $rows;
$t[4]['prev_hash'] = HashChain::GENESIS;
check('prev_hash dipalsukan terdeteksi', !HashChain::verify($t)['valid']);

/* ---------------- CBOR ---------------- */
echo "CBOR\n";
function cborEnc(mixed $v): string
{
    if (is_int($v)) {
        return $v >= 0 ? cborHead(0, $v) : cborHead(1, -1 - $v);
    }
    if (is_string($v)) {
        return cborHead(preg_match('//u', $v) && !preg_match('/[\x00-\x08\x0e-\x1f\x80-\xff]/', $v) ? 3 : 2, strlen($v)) . $v;
    }
    if (is_array($v)) {
        if (array_is_list($v)) {
            return cborHead(4, count($v)) . implode('', array_map('cborEnc', $v));
        }
        $o = cborHead(5, count($v));
        foreach ($v as $k => $x) {
            $o .= cborEnc($k) . cborEnc($x);
        }
        return $o;
    }
    throw new Exception('unsupported');
}
function cborHead(int $major, int $n): string
{
    $m = $major << 5;
    if ($n < 24) return chr($m | $n);
    if ($n < 256) return chr($m | 24) . chr($n);
    if ($n < 65536) return chr($m | 25) . pack('n', $n);
    return chr($m | 26) . pack('N', $n);
}
$bin = random_bytes(40);
$sample = ['fmt' => 'none', 'attStmt' => [], 'authData' => $bin, 1 => 2, 3 => -7, -1 => 1, 'arr' => [1, 500, 70000, -300]];
$dec = Cbor::decode(cborEnc($sample));
check('Decode map/array/int/negatif/bytes', $dec['fmt'] === 'none' && $dec['authData'] === $bin && $dec[3] === -7 && $dec[-1] === 1 && $dec['arr'] === [1, 500, 70000, -300]);
check('CBOR terpotong ditolak', throws(fn () => Cbor::decode(substr(cborEnc($sample), 0, 10)), WebAuthnException::class));

/* ---------------- WebAuthn dengan authenticator tiruan ---------------- */
echo "WebAuthn\n";
$rpId = 'localhost';
$origin = 'http://localhost:5173';
$v = new V($rpId, [$origin]);

function makeAuthData(string $rpId, int $flags, int $count, ?string $credId = null, ?string $cose = null): string
{
    $d = hash('sha256', $rpId, true) . chr($flags) . pack('N', $count);
    if ($credId !== null) {
        $d .= str_repeat("\x00", 16) . pack('n', strlen($credId)) . $credId . $cose;
    }
    return $d;
}
function clientData(string $type, string $challenge, string $origin): string
{
    return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false]);
}

function runFlow(string $label, $privKey, string $coseBytes, int $opensslAlgo, V $v, string $rpId, string $origin): void
{
    $credId = random_bytes(32);

    // Registrasi
    $chal = V::b64uEncode(random_bytes(32));
    $authData = makeAuthData($rpId, 0x45, 0, $credId, $coseBytes);
    $att = cborEnc(['fmt' => 'none', 'attStmt' => [], 'authData' => $authData]);
    $reg = $v->parseRegistration([
        'id' => V::b64uEncode($credId),
        'response' => ['clientDataJSON' => V::b64uEncode(clientData('webauthn.create', $chal, $origin)), 'attestationObject' => V::b64uEncode($att)],
    ], $chal);
    check("$label: registrasi diterima", $reg['credential_id'] === V::b64uEncode($credId));

    check("$label: registrasi dengan challenge salah ditolak", throws(fn () => $v->parseRegistration([
        'response' => ['clientDataJSON' => V::b64uEncode(clientData('webauthn.create', $chal, $origin)), 'attestationObject' => V::b64uEncode($att)],
    ], V::b64uEncode(random_bytes(32))), WebAuthnException::class));

    // Assertion
    $make = function (string $challenge, int $flags, int $count, string $originUsed, ?string $rp = null) use ($privKey, $opensslAlgo, $rpId, $credId) {
        $ad = makeAuthData($rp ?? $rpId, $flags, $count);
        $cd = clientData('webauthn.get', $challenge, $originUsed);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $privKey, $opensslAlgo);
        return ['id' => V::b64uEncode($credId), 'response' => [
            'clientDataJSON' => V::b64uEncode($cd), 'authenticatorData' => V::b64uEncode($ad), 'signature' => V::b64uEncode($sig),
        ]];
    };

    $c1 = V::b64uEncode(random_bytes(32));
    $n = $v->verifyAssertion($make($c1, 0x05, 1, $origin), $c1, $reg['public_key'], 0, false);
    check("$label: assertion valid (counter=1)", $n === 1);

    $c2 = V::b64uEncode(random_bytes(32));
    check("$label: replay counter ditolak", throws(fn () => $v->verifyAssertion($make($c2, 0x05, 1, $origin), $c2, $reg['public_key'], 1, false), WebAuthnException::class));
    check("$label: challenge salah ditolak", throws(fn () => $v->verifyAssertion($make($c2, 0x05, 2, $origin), V::b64uEncode('x'), $reg['public_key'], 1, false), WebAuthnException::class));
    check("$label: origin asing ditolak", throws(fn () => $v->verifyAssertion($make($c2, 0x05, 2, 'https://evil.example'), $c2, $reg['public_key'], 1, false), WebAuthnException::class));
    check("$label: RP ID salah ditolak", throws(fn () => $v->verifyAssertion($make($c2, 0x05, 2, $origin, 'evil.example'), $c2, $reg['public_key'], 1, false), WebAuthnException::class));
    check("$label: tanpa user verification ditolak bila diwajibkan", throws(fn () => $v->verifyAssertion($make($c2, 0x01, 2, $origin), $c2, $reg['public_key'], 1, true), WebAuthnException::class));
    check("$label: dengan user verification diterima", $v->verifyAssertion($make($c2, 0x05, 2, $origin), $c2, $reg['public_key'], 1, true) === 2);

    $other = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $forged = (function () use ($other, $c2, $origin, $rpId, $credId, $opensslAlgo) {
        $ad = makeAuthData($rpId, 0x05, 9);
        $cd = clientData('webauthn.get', $c2, $origin);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $other, OPENSSL_ALGO_SHA256);
        return ['response' => ['clientDataJSON' => V::b64uEncode($cd), 'authenticatorData' => V::b64uEncode($ad), 'signature' => V::b64uEncode($sig)]];
    })();
    check("$label: tanda tangan kunci lain ditolak", throws(fn () => $v->verifyAssertion($forged, $c2, $reg['public_key'], 1, false), WebAuthnException::class));
}

// ES256
$ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$d = openssl_pkey_get_details($ec);
$cose = cborEnc([1 => 2, 3 => -7, -1 => 1, -2 => str_pad($d['ec']['x'], 32, "\x00", STR_PAD_LEFT), -3 => str_pad($d['ec']['y'], 32, "\x00", STR_PAD_LEFT)]);
runFlow('ES256', $ec, $cose, OPENSSL_ALGO_SHA256, $v, $rpId, $origin);

// RS256
$rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$d = openssl_pkey_get_details($rsa);
$coseRsa = cborEnc([1 => 3, 3 => -257, -1 => $d['rsa']['n'], -2 => $d['rsa']['e']]);
runFlow('RS256', $rsa, $coseRsa, OPENSSL_ALGO_SHA256, $v, $rpId, $origin);

echo PHP_EOL . "Hasil: $pass lulus, $fail gagal" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
