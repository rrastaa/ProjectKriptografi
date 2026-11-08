<?php

namespace App\Support\WebAuthn;

/**
 * Decoder CBOR minimal (RFC 8949), cukup untuk attestationObject dan COSE key WebAuthn.
 * Tidak mendukung float dan indefinite-length (tidak dipakai WebAuthn).
 */
final class Cbor
{
    private const MAX_ITEMS = 1024;

    public static function decode(string $data, int &$offset = 0, int $depth = 0): mixed
    {
        if ($depth > 16) {
            throw new WebAuthnException('CBOR: struktur terlalu dalam.');
        }
        if ($offset >= strlen($data)) {
            throw new WebAuthnException('CBOR: data terpotong.');
        }

        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $info = $initial & 0x1f;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new WebAuthnException('CBOR: tipe simple/float tidak didukung.'),
            };
        }

        $value = self::readArgument($data, $offset, $info);

        switch ($major) {
            case 0:
                return $value;
            case 1:
                return -1 - $value;
            case 2:
            case 3:
                if ($value > strlen($data) - $offset) {
                    throw new WebAuthnException('CBOR: panjang string melebihi data.');
                }
                $s = substr($data, $offset, $value);
                $offset += $value;
                return $s;
            case 4:
                self::guardCount($value);
                $arr = [];
                for ($i = 0; $i < $value; $i++) {
                    $arr[] = self::decode($data, $offset, $depth + 1);
                }
                return $arr;
            case 5:
                self::guardCount($value);
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $k = self::decode($data, $offset, $depth + 1);
                    $v = self::decode($data, $offset, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new WebAuthnException('CBOR: tipe key map tidak didukung.');
                    }
                    $map[$k] = $v;
                }
                return $map;
            case 6: // tag: abaikan tag, ambil isinya
                return self::decode($data, $offset, $depth + 1);
        }

        throw new WebAuthnException('CBOR: major type tidak dikenal.');
    }

    private static function readArgument(string $data, int &$offset, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        $bytes = match ($info) {
            24 => 1,
            25 => 2,
            26 => 4,
            27 => 8,
            default => throw new WebAuthnException('CBOR: panjang indefinite tidak didukung.'),
        };
        if ($bytes > strlen($data) - $offset) {
            throw new WebAuthnException('CBOR: data terpotong.');
        }
        $raw = substr($data, $offset, $bytes);
        $offset += $bytes;

        return match ($bytes) {
            1 => ord($raw),
            2 => unpack('n', $raw)[1],
            4 => unpack('N', $raw)[1],
            8 => unpack('J', $raw)[1],
        };
    }

    private static function guardCount(int $n): void
    {
        if ($n < 0 || $n > self::MAX_ITEMS) {
            throw new WebAuthnException('CBOR: jumlah elemen tidak wajar.');
        }
    }
}
