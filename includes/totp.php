<?php
class TOTP {
    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generates a new, random 16-character Base32 secret.
     */
    public static function generateSecret($length = 16) {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Builds the URI for the QR code (Google Authenticator format).
     */
    public static function getProvisioningUri($accountName, $secret, $issuer = null) {
        // Dynamic issuer based on the instance ID
        if ($issuer === null) {
            $issuer = defined('INSTANCE_ID') ? 'DAoC CMS (' . INSTANCE_ID . ')' : 'DAoC CMS';
        }
        
        $accountName = rawurlencode($accountName);
        $issuer = rawurlencode($issuer);
        return "otpauth://totp/{$issuer}:{$accountName}?secret={$secret}&issuer={$issuer}";
    }

    /**
     * Verifies a TOTP code against the secret.
     * Allows a default drift of 1 time window (30 seconds) for out-of-sync clocks.
     */
    public static function verifyCode($secret, $code, $discrepancy = 1) {
        $currentTimeSlice = floor(time() / 30);
        
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculatedCode = self::calculateCode($secret, $currentTimeSlice + $i);
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }
        return false;
    }

    private static function calculateCode($secret, $timeSlice) {
        $secretKey = self::base32Decode($secret);
        
        // Pack time into binary string
        $time = chr(0) . chr(0) . chr(0) . chr(0) . pack('N*', $timeSlice);
        
        // Hash it with HMAC-SHA1
        $hm = hash_hmac('SHA1', $time, $secretKey, true);
        
        // Use last nibble of result as index/offset
        $offset = ord(substr($hm, -1)) & 0x0F;
        
        // Grab 4 bytes of the result
        $hashPart = substr($hm, $offset, 4);
        
        // Unpack binary value
        $value = unpack('N', $hashPart);
        $value = $value[1];
        
        // Only 32 bits
        $value = $value & 0x7FFFFFFF;
        
        $modulo = pow(10, 6);
        return str_pad($value % $modulo, 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode($secret) {
        if (empty($secret)) return '';
        
        $base32chars = self::$base32Chars;
        $base32charsFlipped = array_flip(str_split($base32chars));
        
        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = array(6, 4, 3, 1, 0);
        if (!in_array($paddingCharCount, $allowedValues)) return false;
        
        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';
        
        for ($i = 0; $i < count($secret); $i = $i + 8) {
            $x = '';
            if (!in_array($secret[$i], str_split($base32chars))) return false;
            for ($j = 0; $j < 8; $j++) {
                $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); $z++) {
                $binaryString .= (($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48) ? $y : '';
            }
        }
        return $binaryString;
    }
}
?>