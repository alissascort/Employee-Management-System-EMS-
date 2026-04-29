<?php
/**
 * Terminal Cryptography Module
 * Handles encryption, decryption, and integrity verification
 */

class TerminalCrypto {
    private $encryptionKey;
    private $algorithm = 'aes-256-gcm';
    
    public function __construct() {
        // Load encryption key from secure location
        $this->encryptionKey = $this->loadEncryptionKey();
    }
    
    private function loadEncryptionKey() {
        $keyFile = '/secure/keys/terminal.key';
        if (file_exists($keyFile)) {
            return file_get_contents($keyFile);
        }
        
        // Generate new key if doesn't exist
        $key = random_bytes(32);
        if (!is_dir('/secure/keys')) {
            mkdir('/secure/keys', 0700, true);
        }
        file_put_contents($keyFile, $key, LOCK_EX);
        chmod($keyFile, 0600);
        return $key;
    }
    
    public function encrypt($data) {
        $iv = random_bytes(openssl_cipher_iv_length($this->algorithm));
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $this->algorithm,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        return base64_encode($iv . $tag . $encrypted);
    }
    
    public function decrypt($encryptedData) {
        $data = base64_decode($encryptedData);
        $ivLength = openssl_cipher_iv_length($this->algorithm);
        $tagLength = 16; // GCM tag length
        
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);
        
        return openssl_decrypt(
            $ciphertext,
            $this->algorithm,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }
    
    public function generateHMAC($data) {
        return hash_hmac('sha256', $data, $this->encryptionKey);
    }
    
    public function verifyHMAC($data, $hmac) {
        return hash_equals($this->generateHMAC($data), $hmac);
    }
    
    public function signCommand($command, $userId) {
        $timestamp = time();
        $data = $command . $userId . $timestamp;
        $signature = $this->generateHMAC($data);
        
        return [
            'command' => $command,
            'user_id' => $userId,
            'timestamp' => $timestamp,
            'signature' => $signature
        ];
    }
    
    public function verifySignature($signedCommand) {
        $data = $signedCommand['command'] . 
                $signedCommand['user_id'] . 
                $signedCommand['timestamp'];
        
        return $this->verifyHMAC($data, $signedCommand['signature']);
    }
}
?>
