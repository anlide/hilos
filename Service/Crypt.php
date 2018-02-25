<?php
namespace Hilos\Service;

class Crypt {
  /**
   * Returns an encrypted & utf8-encoded
   *
   * @param string $pureString
   * @param string $encryptionKey
   * @return string
   */
  public static function encrypt($pureString, $encryptionKey) {
    $ivlen = openssl_cipher_iv_length($cipher="AES-128-CBC");
    $iv = openssl_random_pseudo_bytes($ivlen);
    $ciphertext_raw = openssl_encrypt($pureString, $cipher, $encryptionKey, $options=OPENSSL_RAW_DATA, $iv);
    return base64_encode( $iv.$ciphertext_raw );
  }

  /**
   * Returns decrypted original string
   *
   * @param string $encryptedString
   * @param string $encryptionKey
   * @return string
   */
  public static function decrypt($encryptedString, $encryptionKey) {
    $c = base64_decode($encryptedString);
    $ivlen = openssl_cipher_iv_length($cipher="AES-128-CBC");
    $iv = substr($c, 0, $ivlen);
    $ciphertext_raw = substr($c, $ivlen);
    return @openssl_decrypt($ciphertext_raw, $cipher, $encryptionKey, $options=OPENSSL_RAW_DATA, $iv);
  }
}
